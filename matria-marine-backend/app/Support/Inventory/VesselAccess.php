<?php

namespace App\Support\Inventory;

use App\Models\User;

/**
 * Who may do what in the provision module.
 *
 * Four kinds of account reach these screens and they are not doing the same
 * job. The office — super admins and admins — works across the fleet and does
 * the buying. On each ship there are two accounts: the cook, who does the work
 * the module exists to record (counts, withdrawals, receiving, writing the
 * next requisition), and the master, who approves it and sets how the ship is
 * provisioned.
 *
 * This is the one place that is defined. The middleware reads it to refuse a
 * request, the login payload reads it to tell the SPA which shell to draw, and
 * the controllers read it to decide which fields a save may touch — so a
 * screen cannot be hidden from a menu while its data stays reachable, or the
 * other way round.
 *
 * Hiding a link is not security. Everything here is enforced on the server by
 * the path the data lives under, and the client simply follows.
 */
class VesselAccess
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const MASTER = 'vessel_master';
    public const COOK = 'vessel_cook';

    /** The office: the whole fleet, and the buying. */
    public const OFFICE = [self::SUPER_ADMIN, self::ADMIN];

    /** On board: one ship, and only ever that one. */
    public const ON_BOARD = [self::MASTER, self::COOK];

    /** What each rank is called on screen and in a refusal. */
    public const RANKS = [
        self::MASTER => 'Master',
        self::COOK => 'Cook',
    ];

    /**
     * Paths that are something other than the ship's own work.
     *
     * [methods, path pattern, who may]. The first match wins, so a narrower
     * rule sits above a broader one that would also match it — PUT on a vessel
     * is the master saving how his ship is provisioned, while DELETE on the
     * same path is the office taking a ship off the fleet.
     *
     * Anything matching nothing is the ship's work and open to the cook, which
     * is almost the whole module. That default is the right way round here
     * because a vessel account is confined to its own vessel whichever
     * endpoint it reaches — see InventoryController::vessel(). The worst an
     * unlisted new route can do is let a cook change their own ship's figures,
     * and that is the job.
     */
    private const RULES = [
        // Everything hanging off a vessel is the office's: her accounts, her
        // catalogue, putting her back on the fleet. A master who could create
        // accounts could create himself another one with more authority, and a
        // cook has no business restocking the master item list.
        //
        // Deliberately the whole sub-tree rather than a rule per endpoint, so
        // anything added under a vessel later is office-only until somebody
        // decides otherwise. That is the safe default HERE, and the opposite of
        // the module's default below, because none of this is the ship's work.
        ['*', '#^inventory/vessels/\d+/#', 'office'],
        ['GET|POST', '#^inventory/vessels$#', 'office'],
        // The one thing on a vessel the master does decide.
        ['PUT|PATCH', '#^inventory/vessels/\d+$#', 'master'],
        ['DELETE', '#^inventory/vessels/\d+$#', 'office'],
        // Approving the ship's order is the master's whole part in this. The
        // cook writes it and sends it up; he does not sign it off, which is
        // the point of there being two accounts.
        ['POST', '#^inventory/requisitions/\d+/(approve|decline)$#', 'master'],
        // The fleet's approved orders waiting to be bought, and handing one to
        // procurement — both the office's, because the buying is.
        ['GET', '#^inventory/requisitions/inbox$#', 'office'],
        ['POST', '#^inventory/requisitions/\d+/to-enquiry$#', 'office'],
        // What things cost is agreed ashore. The cook counts what is on the
        // shelf; he does not price it.
        ['POST', '#^inventory/items/prices(/|$)#', 'office'],
        // Who supplies the fleet is the office's business, down to the names.
        // A ship records what arrived and the reference on the delivery note;
        // naming the chandler is the office's half of the same event, and the
        // crew's receiving screen does not ask for it.
        //
        // This was once open for reading, on the grounds that a picker is not
        // the price book. It is: the list is every vendor the office deals
        // with, and the ship has no use for it.
        ['*', '#^inventory/suppliers(/|$)#', 'office'],
    ];

    /** Whether this account reaches the provision module at all. */
    public static function mayUseModule(?User $user): bool
    {
        return $user !== null
            && in_array($user->role, [...self::OFFICE, ...self::ON_BOARD], true);
    }

    public static function isOffice(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::OFFICE, true);
    }

    public static function isOnBoard(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::ON_BOARD, true);
    }

    public static function isMaster(?User $user): bool
    {
        return $user !== null && $user->role === self::MASTER;
    }

    /**
     * Whether this account is shown what things cost.
     *
     * The owner's rule: "the master can see budget, cook is just cook, can't
     * see the budget but can withdraw items etc."
     *
     * So the line is not office against ship — it is who answers for the money.
     * The master commands the vessel and is accountable for what she spends, so
     * he sees the budget, the prices and what an order is worth. The cook runs
     * the store: he counts, withdraws, receives and writes the next order, and
     * none of that work needs a price attached to it.
     *
     * The cook is still told when an order is over the budget. That is a flag,
     * not a figure — it says take something off, without saying what anything
     * costs. The two are kept apart everywhere this is applied.
     *
     * Deliberately its own question rather than reusing the master gate above.
     * They happen to cover the same accounts today; they are not the same idea,
     * and if the office ever decides a master should not see prices either,
     * this is the single line that changes.
     */
    public static function seesMoney(?User $user): bool
    {
        return self::isOffice($user) || self::isMaster($user);
    }

    /**
     * Which shell the SPA should draw: the fleet, or one ship.
     *
     * The server does not redirect — this is an API — so it says what kind of
     * account this is and the client routes accordingly.
     */
    public static function scope(?User $user): ?string
    {
        if (self::isOffice($user)) {
            return 'office';
        }

        return self::isOnBoard($user) ? 'vessel' : null;
    }

    /**
     * What a path demands beyond being signed in: 'office', 'master', or null
     * when it is simply the ship's work.
     *
     * @param  string  $path  as Laravel reports it, e.g. "api/inventory/items"
     */
    public static function needs(string $path, string $method = 'GET'): ?string
    {
        $path = preg_replace('#^api/#', '', trim($path, '/'));
        $method = strtoupper($method);

        foreach (self::RULES as [$methods, $pattern, $who]) {
            if ($methods !== '*' && ! in_array($method, explode('|', $methods), true)) {
                continue;
            }

            if (preg_match($pattern, $path)) {
                return $who;
            }
        }

        return null;
    }

    public static function may(?User $user, string $path, string $method = 'GET'): bool
    {
        if (! self::mayUseModule($user)) {
            return false;
        }

        return match (self::needs($path, $method)) {
            'office' => self::isOffice($user),
            'master' => self::isOffice($user) || self::isMaster($user),
            default => true,
        };
    }

    /**
     * Why the request was refused, in the words of the job rather than the code.
     *
     * The crew read these. "403" tells a cook nothing; "the office sets the
     * budget" tells them who to ask.
     */
    public static function refusal(?User $user, string $path, string $method = 'GET'): string
    {
        if (! self::mayUseModule($user)) {
            return 'This area is for Matria staff and vessel crew.';
        }

        $rank = self::RANKS[$user->role] ?? 'crew';

        return match (self::needs($path, $method)) {
            'office' => "That is the office's to do — your account is the {$rank} of this vessel.",
            'master' => 'Only the master can change how the vessel is provisioned.',
            default => 'You do not have access to that.',
        };
    }
}
