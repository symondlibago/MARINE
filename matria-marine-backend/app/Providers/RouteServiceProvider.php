<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $who = $request->user()?->id ?: $request->ip();

            // The local backup is bursty by nature: mirroring one document is a
            // manifest plus every PDF and attachment on it, so a few dozen at
            // once runs to hundreds of requests in a minute — nothing like
            // ordinary screen use, and 60 a minute cuts a backup off partway
            // through. It gets a higher ceiling in a bucket of its own, so a
            // backup neither trips the limit nor eats the allowance the rest of
            // the portal needs. Safe to be generous: every one of these is an
            // authenticated, read-only GET of something the user can already
            // open on screen.
            if ($request->is('api/portal/backup/*')) {
                return Limit::perMinute(300)->by('backup|'.$who);
            }

            return Limit::perMinute(60)->by($who);
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
