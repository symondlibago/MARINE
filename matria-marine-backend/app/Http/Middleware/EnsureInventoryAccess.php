<?php

namespace App\Http\Middleware;

use App\Support\Inventory\VesselAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate on the whole provision module.
 *
 * Two jobs, and the second is the important one.
 *
 * It refuses a request for something this account does not do — which rank may
 * do what is defined once, in App\Support\Inventory\VesselAccess.
 *
 * Then it pins a vessel account to its own ship. Every inventory endpoint is
 * scoped to one vessel and takes the id from the request, which is right for
 * the office moving between ships and wrong for a cook: a cook's ship is a
 * property of the account, not of the URL. So the id is filled in from the
 * account, and a request that names a different vessel is refused rather than
 * quietly served from the right one — a cook who has somehow been handed
 * another ship's link should be told, not shown their own figures under it.
 *
 * It reads users.role rather than the spatie role rows on purpose. Migrations
 * are run by hand on Railway, so for a few minutes the code is live and the
 * rows are not; a crew account that cannot log in during that window would be
 * a worse failure than the duplication.
 */
class EnsureInventoryAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $path = $request->path();
        $method = $request->method();

        if (! VesselAccess::may($user, $path, $method)) {
            return $this->refuse(VesselAccess::refusal($user, $path, $method));
        }

        if (VesselAccess::isOnBoard($user)) {
            if (! $user->vessel_id) {
                return $this->refuse(
                    'Your account is not assigned to a vessel yet. Ask the office to set it.'
                );
            }

            $named = (int) $request->input('vessel_id');

            if ($named && $named !== (int) $user->vessel_id) {
                $ship = $user->vessel?->name;

                return $this->refuse(
                    $ship
                        ? "Your account belongs to {$ship}. You cannot open another vessel's stores."
                        : "You cannot open another vessel's stores."
                );
            }

            // Laravel reads query and body alike through input(), so this
            // serves a GET and a POST the same way and no screen has to
            // remember to send it.
            $request->merge(['vessel_id' => (int) $user->vessel_id]);
        }

        return $next($request);
    }

    private function refuse(string $message): Response
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }
}
