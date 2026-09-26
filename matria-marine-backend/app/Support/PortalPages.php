<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Which screens an admin may open, and which API paths belong to each.
 *
 * The one place this is defined. The middleware reads it to refuse requests,
 * the staff form reads it to draw the checkboxes, and the login payload reads
 * it to tell the sidebar what to show — so a page cannot be hidden from the
 * menu while its data stays reachable, or the other way round.
 *
 * Hiding a menu item is not security. Anyone can type /api/payroll/runs into a
 * browser, so every page is enforced on the server by the path its data lives
 * under, and the menu simply follows.
 *
 * Super admins see everything and are never checked. The rules below apply to
 * admins only.
 */
class PortalPages
{
    /**
     * key => [label, path patterns].
     *
     * Order matters: the first pattern that matches wins, so a narrower path
     * has to sit above a broader one that would also match it — the credit-memo
     * save route lives under /invoices, and statements live under /reports.
     *
     * Anything that matches nothing is shared and open to every admin: the
     * chart of accounts, exchange rates, document numbering, payments and the
     * like, which several screens lean on at once.
     */
    private const PAGES = [
        'enquiries' => ['Enquiries', ['#^portal/(rfqs|quotes|item-suggestions)(/|$)#']],
        'offers' => ['Offers', ['#^portal/offers(/|$)#']],
        'delivery_orders' => ['Delivery Orders', ['#^portal/delivery-orders(/|$)#']],
        // Above invoices: the credit memo screen saves through an invoice URL.
        'credit_memos' => ['Credit Memos', ['#^portal/credit-memos(/|$)#', '#^portal/invoices/[^/]+/credit-memo$#']],
        'invoices' => ['Invoices', ['#^portal/invoices(/|$)#']],
        'purchase_orders' => ['Purchase Orders', ['#^portal/purchase-orders(/|$)#']],
        'return_notes' => ['Return Notes', ['#^portal/return-notes(/|$)#']],
        // Every accounting screen except the chart, which every account picker
        // in the app reads.
        'accounting' => ['Accounting', ['#^portal/accounting/(?!chart$)#']],
        // Above reports: statements are served from under /reports.
        'statements' => ['Statements', ['#^portal/reports/(statements|open-entries|ledger-entries)(/|$)#']],
        'reports' => ['Reports', ['#^portal/reports(/|$)#']],
        'operating_expenses' => ['Operating Expenses', ['#^portal/operating-expenses(/|$)#']],
        'customers' => ['Customers', ['#^portal/customers(/|$)#']],
        'vendors' => ['Vendors', ['#^portal/vendors(/|$)#']],
        'sent_log' => ['Sent Log', ['#^portal/sent-logs(/|$)#']],
        'payroll' => ['Payroll', ['#^payroll(/|$)#']],
    ];

    /**
     * Pages whose LIST can be read without the page itself.
     *
     * Every invoice, offer and purchase order has a customer or vendor picker,
     * so taking Customers away must not break those screens. Reading the list
     * stays open; adding, editing and deleting a customer does not.
     */
    private const READS_OPEN = ['customers', 'vendors'];

    /** Every page, for the staff form: [['key' => …, 'label' => …], …]. */
    public static function all(): array
    {
        return collect(self::PAGES)
            ->map(fn ($p, $key) => ['key' => $key, 'label' => $p[0]])
            ->values()
            ->all();
    }

    public static function keys(): array
    {
        return array_keys(self::PAGES);
    }

    public static function label(string $key): string
    {
        return self::PAGES[$key][0] ?? $key;
    }

    public static function permission(string $key): string
    {
        return 'page.'.$key;
    }

    /**
     * The page a request belongs to, or null when it is shared.
     *
     * @param  string  $path  as Laravel reports it, e.g. "api/portal/rfqs/12"
     */
    public static function pageFor(string $path, string $method = 'GET'): ?string
    {
        $path = preg_replace('#^api/#', '', trim($path, '/'));

        foreach (self::PAGES as $key => [, $patterns]) {
            foreach ($patterns as $pattern) {
                if (! preg_match($pattern, $path)) {
                    continue;
                }

                // A picker reading the customer or vendor list is not a visit
                // to the Customers page.
                if (in_array($key, self::READS_OPEN, true) && strtoupper($method) === 'GET') {
                    return null;
                }

                return $key;
            }
        }

        return null;
    }

    /** The pages this user may open. Super admins get every one. */
    public static function visibleTo(User $user): array
    {
        if (self::isSuperAdmin($user) || ! self::ready()) {
            return self::keys();
        }

        $granted = $user->getPermissionNames()->all();

        return array_values(array_filter(
            self::keys(),
            fn ($key) => in_array(self::permission($key), $granted, true)
        ));
    }

    public static function canSee(User $user, string $key): bool
    {
        return in_array($key, self::visibleTo($user), true);
    }

    public static function isSuperAdmin(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    /**
     * Whether page permissions have been set up yet.
     *
     * Migrations are run by hand on Railway, so for a few minutes the code is
     * live and the permission rows are not. Until they exist, admins keep the
     * access they had before this feature — every page — rather than being
     * locked out of everything mid-deploy.
     */
    private static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('permissions')
            && \Spatie\Permission\Models\Permission::where('name', 'like', 'page.%')->exists();
    }
}
