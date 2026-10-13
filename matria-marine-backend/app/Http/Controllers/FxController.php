<?php

namespace App\Http\Controllers;

use App\Support\FxRates;
use Illuminate\Http\Request;

class FxController extends Controller
{
    public function rates(Request $request)
    {
        $base = strtoupper(substr((string) $request->query('base', 'USD'), 0, 3));

        $data = FxRates::for($base);

        if (! $data) {
            return response()->json(['success' => false, 'message' => 'Live rates unavailable right now.'], 502);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * The one rate a document may be booked at.
     *
     * There is exactly one way to arrive at a posting rate, and this is it.
     * The screens used to work one out themselves from the rate table, which
     * is how two USD invoices raised on the same day came to sit in the books
     * at 1.292222 and 1.269084 — a shade under two percent apart, decided by
     * which button somebody happened to press. Both were DBS; they were just
     * opposite sides of the bank's buy/sell spread.
     *
     * So the rate buttons ask for this, the automatic stamp uses the same
     * call, and the answer is the same either way.
     */
    public function toBase(Request $request)
    {
        $currency = strtoupper(substr((string) $request->query('currency', ''), 0, 3));

        abort_if($currency === '', 422, 'Say which currency to price.');

        $base = \App\Support\AccountingBooks::baseCurrency();
        $rate = \App\Support\AccountingBooks::stampRate($currency);

        if ($rate === null) {
            return response()->json([
                'success' => false,
                'message' => "No {$base} rate available for {$currency} right now.",
            ], 502);
        }

        return response()->json(['success' => true, 'data' => [
            'currency' => $currency,
            'base_currency' => $base,
            'rate' => $rate,
        ]]);
    }
}
