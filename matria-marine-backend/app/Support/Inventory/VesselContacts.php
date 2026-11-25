<?php

namespace App\Support\Inventory;

use App\Models\Inventory\Vessel;
use App\Models\User;
use App\Support\Recipients;

/**
 * Who to write to about a vessel.
 *
 * The office buys for a ship through an ordinary customer record — see
 * App\Support\Inventory\RequisitionBridge — and that record is created from the
 * vessel's name with no email on it, because a ship is not a mailbox. So a
 * quotation raised for a vessel had nowhere to go: the send refused with "this
 * customer has no valid email on file", which is true and useless.
 *
 * Her master is the address. He is the one answerable for what his ship spends,
 * he is the one who approved the order the quotation answers, and his account
 * already carries a real email because creating a crew account requires one.
 *
 * The single definition of that, so the quotation, and anything else the office
 * sends a ship later, all reach the same person.
 */
class VesselContacts
{
    /**
     * The master's address, or addresses if a ship somehow has two active
     * master accounts — better to write to both than to pick one.
     *
     * @return string[]
     */
    public static function masterEmails(?Vessel $vessel): array
    {
        if (! $vessel) {
            return [];
        }

        return User::where('vessel_id', $vessel->id)
            ->where('role', VesselAccess::MASTER)
            ->where('is_active', true)
            ->pluck('email')
            ->flatMap(fn ($raw) => Recipients::emails($raw))
            ->unique()
            ->values()
            ->all();
    }
}
