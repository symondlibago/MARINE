<?php

namespace App\Http\Controllers;

use App\Support\FxRates;
use Illuminate\Http\Request;

class FxController extends Controller
{
    public function rates(Request $request)
    {
        $base = strtoupper(substr((string) $request->query('base', 'USD'), 0, 3));

        $data = FxRates::for($base);

        if (! $data) {
            return response()->json(['success' => false, 'message' => 'Live rates unavailable right now.'], 502);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
