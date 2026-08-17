<?php

namespace App\Support;

use App\Models\DocumentCounter;
use App\Models\DocumentCounterAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin control over the running document numbers.
 *
 * The one rule this file exists to enforce: a counter may only ever move
 * FORWARD, past every number already stamped on a document. Winding it back
 * would hand the next invoice a number that is already taken, and every one of
 * those columns is uniquely indexed — the save would throw, mid-transaction,
 * in front of whoever happened to be raising the document.
 *
 * So the floor is not read from the counter alone. It is the higher of:
 *
 *   · the counter's own position, and
 *   · the largest sequence found on a real document of that type
 *
 * which stays correct even if a counter was previously edited by hand, or a
 * document was created before the counter existed.
 */
class DocumentSeries
{
    /** Nobody needs a document number past this; catches a slipped keypress. */
    public const MAX_SEQ = 9_000_000;

    /** Current position and headroom for every numbering series. */
    public static function state(): array
    {
        $counters = DocumentCounter::pluck('seq', 'key');

        return collect(DocNumber::types())->map(function (string $type) use ($counters) {
            $seq = (int) ($counters[$type] ?? 0);
            $issued = self::highestIssued($type);
            $floor = max($seq, $issued);

            return [
                'key' => $type,
                'label' => DocNumber::label($type),
                'prefix' => DocNumber::format($type)[0],
                // What the very next document of this type would be called.
                'next_number' => DocNumber::preview($type, $floor + 1),
                'next_seq' => $floor + 1,
                // The smallest value an admin is allowed to set it to.
                'minimum' => $floor + 1,
                'highest_issued' => $issued,
                'last_number' => $issued > 0 ? DocNumber::preview($type, $issued) : null,
                // Flagged so the screen can explain why the minimum is what it is.
                'behind' => $issued > $seq,
            ];
        })->values()->all();
    }

    /**
     * Point a series at a new next number.
     *
     * Runs inside a transaction with the counter row locked, and recomputes the
     * floor INSIDE that lock — so a document raised a moment ago is still
     * counted, and two admins saving at once cannot both win.
     *
     * @throws ValidationException when the move would go backwards
     */
    public static function setNext(string $type, int $nextNumber, ?User $user = null, ?string $reason = null): array
    {
        if (! DocNumber::knows($type)) {
            throw ValidationException::withMessages(['key' => 'Unknown document type.']);
        }

        if ($nextNumber < 1 || $nextNumber > self::MAX_SEQ) {
            throw ValidationException::withMessages([
                'next_number' => 'Enter a number between 1 and '.number_format(self::MAX_SEQ).'.',
            ]);
        }

        return DB::transaction(function () use ($type, $nextNumber, $user, $reason) {
            DocumentCounter::firstOrCreate(['key' => $type], ['seq' => 0]);
            $counter = DocumentCounter::where('key', $type)->lockForUpdate()->first();

            $floor = max((int) $counter->seq, self::highestIssued($type));
            $targetSeq = $nextNumber - 1;

            if ($targetSeq < $floor) {
                throw ValidationException::withMessages([
                    'next_number' => sprintf(
                        '%s is already in use. The next number cannot be lower than %s.',
                        DocNumber::preview($type, $nextNumber),
                        DocNumber::preview($type, $floor + 1),
                    ),
                ]);
            }

            $from = (int) $counter->seq;

            if ($targetSeq !== $from) {
                $counter->seq = $targetSeq;
                $counter->save();

                DocumentCounterAudit::create([
                    'key' => $type,
                    'from_seq' => $from,
                    'to_seq' => $targetSeq,
                    'reason' => $reason,
                    'changed_by' => $user?->id,
                ]);
            }

            return [
                'key' => $type,
                'changed' => $targetSeq !== $from,
                'next_number' => DocNumber::preview($type, $targetSeq + 1),
                'skipped' => max(0, $targetSeq - $floor),
            ];
        });
    }

    /**
     * The largest sequence already stamped on a document of this type.
     *
     * Numbers are read and parsed in PHP rather than picked apart in SQL: the
     * volume is trivial (one short column, only when an admin opens the
     * screen) and it keeps the parsing exact instead of depending on how a
     * particular database engine handles string functions.
     */
    public static function highestIssued(string $type): int
    {
        [$prefix] = DocNumber::format($type);
        // Anchored so MMS-PO never matches an MMS-ProINV number, and a
        // per-vendor suffix (…-01) is ignored rather than misread as the sequence.
        $pattern = '/^'.preg_quote($prefix, '/').'-\d{4}-(\d+)$/';
        $highest = 0;

        foreach (DocNumber::sources($type) as [$model, $column]) {
            $numbers = $model::query()
                ->whereNotNull($column)
                ->where($column, 'like', $prefix.'-%')
                ->pluck($column);

            foreach ($numbers as $value) {
                if (preg_match($pattern, (string) $value, $m)) {
                    $highest = max($highest, (int) $m[1]);
                }
            }
        }

        return $highest;
    }
}
