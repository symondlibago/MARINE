<?php

namespace App\Support\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * Requisition numbering for the provision module — PRV-REQ-2026-0001.
 *
 * Deliberately independent of the procurement DocNumber counters so the two
 * operations can never consume each other's sequences.
 */
class ReqNumber
{
    public static function next(?int $year = null): string
    {
        $year = $year ?: (int) date('Y');
        $prefix = "PRV-REQ-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = DB::table('provision_requisitions')
                ->where('req_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('req_number')
                ->value('req_number');

            $seq = $last ? (int) substr($last, strlen($prefix)) : 0;

            return $prefix.str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT);
        });
    }
}
