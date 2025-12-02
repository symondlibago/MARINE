<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Admin Login
     */
    public function login(Request $request)
    {
        // 1. Validate Input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false, 
                'message' => 'Validation error', 
                'errors' => $validator->errors()
            ], 422);
        }

        // 2. Attempt Login
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false, 
                'message' => 'Invalid credentials provided.'
            ], 401);
        }

        // 3. Generate Token
        $user = Auth::user();
        $token = $user->createToken('MatriaAdminToken')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Welcome back, ' . $user->name,
            'data' => [
                'user' => $user,
                'token' => $token,
            ]
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get Current User info
     */
    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()
        ]);
    }
}