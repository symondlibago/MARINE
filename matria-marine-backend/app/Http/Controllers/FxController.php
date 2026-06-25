<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FxController extends Controller
{
    public function rates(Request $request)
    {
        $base = strtoupper(substr((string) $request->query('base', 'USD'), 0, 3));
        $key = "fx_rates_{$base}_".now()->toDateString();

        $data = Cache::get($key);

        if (! $data) {
            try {
                // verify=>false only outside production: dev machines here sit behind
                // a TLS-intercepting proxy (Norton) that breaks normal cert checks.
                $res = Http::withOptions(['verify' => app()->environment('production')])
                    ->timeout(8)
                    ->get("https://open.er-api.com/v6/latest/{$base}");
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => 'Live rates unavailable right now.'], 502);
            }

            if (! $res->ok() || $res->json('result') !== 'success' || ! $res->json('rates')) {
                return response()->json(['success' => false, 'message' => 'Live rates unavailable right now.'], 502);
            }

            $data = [
                'base' => $base,
                'rates' => $res->json('rates'),
                'date' => $res->json('time_last_update_utc'),
            ];
            Cache::put($key, $data, now()->addHours(12));
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
