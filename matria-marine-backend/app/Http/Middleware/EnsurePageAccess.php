<?php

namespace App\Http\Middleware;

use App\Support\PortalPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a request for a page the admin has not been given.
 *
 * Runs on every portal and payroll request. Which page a request belongs to is
 * decided by its path, from PortalPages — so taking Payroll away from someone
 * closes /api/payroll to them, not just the Payroll link in their menu.
 */
class EnsurePageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $page = PortalPages::pageFor($request->path(), $request->method());

        if ($page !== null && $user !== null && ! PortalPages::canSee($user, $page)) {
            return response()->json([
                'success' => false,
                'message' => "You don't have access to ".PortalPages::label($page).'. Ask a super admin to add it to your account.',
                'page' => $page,
            ], 403);
        }

        return $next($request);
    }
}
