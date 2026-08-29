<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Exchange rates, from DBS's published board rates.
 *
 * DBS is the bank Matria actually converts through, so its board rate is the
 * number that ends up on the bank statement — a generic mid-market feed is
 * always a percent or two optimistic and leaves the books not tying out.
 * This is the same JSON DBS's own currency converter on dbs.com.sg reads.
 *
 * Direction matters. Paying a vendor invoice in EUR from a USD balance means
 * BUYING euros: the bank sells you EUR at its TT Sell and takes your USD at
 * its TT Buy, and you cross both spreads. So the cost of one unit of `from`,
 * expressed in `base`, is sell[from] / buy[base]. Using a mid rate here would
 * quietly understate every foreign line.
 *
 * Everything DBS publishes is quoted against SGD, so cross rates go through it.
 * If DBS is unreachable we fall back to the open mid-market feed rather than
 * leave staff unable to price anything — the response says which was used.
 */
class FxRates
{
    /** The feed behind the DBS currency converter. */
    private const DBS_URL = 'https://www.dbs.com.sg/sg-rates-api/v1/api/sgrates/getCurrencyConversionRates?FETCH_LATEST=';

    private const FALLBACK_URL = 'https://open.er-api.com/v6/latest/';

    /** Board rates move a few times a day; re-reading every 6 hours is plenty. */
    private const TTL_HOURS = 6;

    /**
     * Rates for one base currency.
     *
     * Returns ['base', 'rates', 'date', 'source'] where rates[X] is units of X
     * per 1 base — the shape the portal already expects — or null if no source
     * could be reached.
     */
    public static function for(string $base): ?array
    {
        $base = strtoupper(substr($base ?: 'SGD', 0, 3));

        $board = self::board();

        if ($board) {
            $rates = self::fromBoard($board, $base);
            if ($rates) {
                return [
                    'base' => $base,
                    'rates' => $rates,
                    'date' => $board['effective'],
                    'source' => 'DBS board rate',
                ];
            }
        }

        return self::fallback($base);
    }

    /**
     * DBS's board, reduced to TT Sell / TT Buy per unit against SGD.
     *
     * Returns ['sell' => [CUR => float], 'buy' => [...], 'effective' => string].
     */
    private static function board(): ?array
    {
        return Cache::remember('fx_dbs_board', now()->addHours(self::TTL_HOURS), function () {
            try {
                // verify=>false only outside production: dev machines here sit behind
                // a TLS-intercepting proxy (Norton) that breaks normal cert checks.
                $res = Http::withOptions(['verify' => app()->environment('production')])
                    ->withHeaders(['Accept' => 'application/json'])
                    ->timeout(12)
                    ->get(self::DBS_URL);
            } catch (\Throwable $e) {
                Log::warning('DBS board rates unreachable.', ['error' => $e->getMessage()]);

                return null;
            }

            if (! $res->ok()) {
                return null;
            }

            $sell = [];
            $buy = [];

            foreach ((array) $res->json('results.assets') as $asset) {
                foreach ((array) ($asset['recData'] ?? []) as $row) {
                    // Only the SGD legs — every cross is built from those.
                    if (($row['quoteCurrency'] ?? null) !== 'SGD') {
                        continue;
                    }

                    $cur = strtoupper((string) ($row['currency'] ?? ''));

                    if ($cur === '') {
                        continue;
                    }

                    // Thinly traded currencies are quoted per 100 units, not per 1.
                    $unit = (float) ($row['baseCurrencyUnit'] ?? 1) ?: 1.0;
                    $s = (float) ($row['ttSell'] ?? 0) / $unit;
                    $b = (float) ($row['ttBuy'] ?? 0) / $unit;

                    // A side DBS is not quoting today comes back as 0.000000, and
                    // the two sides are kept apart because they fail apart: DBS
                    // sells pesos and rupees but will not buy them back. That is
                    // still everything needed to price a vendor quoting in them —
                    // only using one as the enquiry's own base is impossible.
                    if ($s > 0) {
                        $sell[$cur] = $s;
                    }
                    if ($b > 0) {
                        $buy[$cur] = $b;
                    }
                }
            }

            if (! $sell) {
                return null;
            }

            // SGD is the quote currency, so it trades against itself at par.
            $sell['SGD'] = 1.0;
            $buy['SGD'] = 1.0;

            return [
                'sell' => $sell,
                'buy' => $buy,
                'effective' => (string) ($res->json('effectiveDateAndTime') ?: $res->json('lastUpdatedDateAndTime')),
            ];
        });
    }

    /** rates[X] = units of X per 1 base, derived from the board. */
    private static function fromBoard(array $board, string $base): ?array
    {
        $baseBuy = $board['buy'][$base] ?? null;

        if (! $baseBuy) {
            return null; // DBS does not quote this currency — fall back.
        }

        $rates = [];

        foreach ($board['sell'] as $cur => $sellPerUnit) {
            // Cost of 1 `cur` in `base`, then inverted into the units-per-base
            // shape the portal reads.
            $costOfOne = $sellPerUnit / $baseBuy;
            if ($costOfOne > 0) {
                $rates[$cur] = 1 / $costOfOne;
            }
        }

        // A currency against itself is 1, never the bank's spread.
        $rates[$base] = 1.0;

        return $rates;
    }

    /** Open mid-market feed, used only when DBS cannot be reached. */
    private static function fallback(string $base): ?array
    {
        $key = "fx_fallback_{$base}_".now()->toDateString();

        return Cache::remember($key, now()->addHours(self::TTL_HOURS), function () use ($base) {
            try {
                $res = Http::withOptions(['verify' => app()->environment('production')])
                    ->timeout(8)
                    ->get(self::FALLBACK_URL.$base);
            } catch (\Throwable $e) {
                return null;
            }

            if (! $res->ok() || $res->json('result') !== 'success' || ! $res->json('rates')) {
                return null;
            }

            return [
                'base' => $base,
                'rates' => $res->json('rates'),
                'date' => (string) $res->json('time_last_update_utc'),
                'source' => 'Mid-market (DBS unavailable)',
            ];
        });
    }

    /**
     * How many `base` units equal 1 unit of `from` — what a quote's
     * exchange_rate column holds. Null when the pair cannot be priced.
     */
    public static function rateToBase(string $from, string $base): ?float
    {
        $from = strtoupper($from);
        $base = strtoupper($base);

        if ($from === $base) {
            return 1.0;
        }

        $data = self::for($base);
        $perBase = (float) ($data['rates'][$from] ?? 0);

        return $perBase > 0 ? 1 / $perBase : null;
    }
}
