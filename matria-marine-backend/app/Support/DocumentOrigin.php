<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which operation raised a document, and who may narrow by it.
 *
 * Two operations share one set of books. From the procurement portal you see
 * everything the company does, provisions included — that is the whole of the
 * business and it is what the accountant has to file. From the provisions side
 * you see the provisions and nothing else, because a master looking at what
 * his ship cost has no business reading the company's ship-chandling trade.
 *
 * The owner put it better than this comment does: "if im at the general page
 * for inventory i will only see the accounting of the inventory part i cannot
 * see the PS, if i am at the PS i can see everything included the inventory
 * part."
 *
 * So: one ledger, two views, and this is the one place the views are defined.
 */
class DocumentOrigin
{
    /** Raised by the procurement portal — the ship-chandling trade. */
    public const PROCUREMENT = 'procurement';

    /** Raised to provision a vessel of the fleet. */
    public const INVENTORY = 'inventory';

    public const ALL = [self::PROCUREMENT, self::INVENTORY];

    public const LABELS = [
        self::PROCUREMENT => 'Procurement',
        self::INVENTORY => 'Vessel provisions',
    ];

    /**
     * The origin a request is asking to be narrowed to, or null for all of it.
     *
     * Anything that is not one of the two known origins is treated as "all"
     * rather than as an error: a stale bookmark or a typo should show the
     * normal screen, not a refusal.
     */
    public static function fromRequest(Request $request): ?string
    {
        $asked = (string) $request->query('origin', '');

        return in_array($asked, self::ALL, true) ? $asked : null;
    }

    /**
     * Narrow a query to one origin.
     *
     * Null means every origin, which is the procurement portal's own view and
     * the default everywhere. Documents raised before this column existed
     * carry 'procurement', which is true of them.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function apply($query, ?string $origin, string $column = 'origin')
    {
        if ($origin === null) {
            return $query;
        }

        return $query->where($column, $origin);
    }

    /**
     * What a document raised right now should be stamped with.
     *
     * A document created while somebody is working on the provisions side is a
     * provisions document. Everything else is procurement, which is both the
     * safe default and what every existing document is.
     */
    public static function forRequest(Request $request): string
    {
        // Explicit beats inferred: the bridge that turns a vessel's
        // requisition into an enquiry says what it is making.
        $declared = (string) $request->input('origin', '');

        if (in_array($declared, self::ALL, true)) {
            return $declared;
        }

        return str_starts_with(ltrim($request->path(), '/'), 'api/inventory')
            ? self::INVENTORY
            : self::PROCUREMENT;
    }

    public static function label(?string $origin): string
    {
        return self::LABELS[$origin] ?? self::LABELS[self::PROCUREMENT];
    }

    /**
     * Whether this account may see the whole company's books.
     *
     * A vessel's master and cook never can: they reach the provisions module
     * and nothing else, and the gate on it refuses them the fleet's screens
     * anyway. This is here so a report that takes an origin cannot be widened
     * past what the asker is entitled to.
     */
    public static function visibleTo(?User $user, ?string $asked): ?string
    {
        if ($user !== null && \App\Support\Inventory\VesselAccess::isOnBoard($user)) {
            return self::INVENTORY;
        }

        return $asked;
    }
}
