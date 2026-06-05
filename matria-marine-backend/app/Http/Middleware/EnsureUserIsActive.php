<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deactivated staff accounts from authenticated routes.
 *
 * Replaces the inactive-account check that lived in the old (unused)
 * RoleMiddleware. Apply alongside `auth:sanctum` and spatie's `role:` middleware.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact an administrator.',
            ], 403);
        }

        return $next($request);
    }
}
