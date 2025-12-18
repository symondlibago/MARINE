<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Mail\EmailUpdateOtpMail;

class AuthController extends Controller
{
    public function login(Request $request) 
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Attempt login
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
        }

        $user = Auth::user();
        
        // Generate the token for LocalStorage
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        // Revoke the token that was used to authenticate the current request
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $user->update([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully'
        ]);
    }

    // --- NEW EMAIL UPDATE METHODS ---

    /**
     * Step 1: Validate password/new email and send OTP to CURRENT email.
     */
    public function initiateEmailUpdate(Request $request)
    {
        $request->validate([
            'new_email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $otp = rand(100000, 999999);

        // Store OTP and New Email in Cache for 10 minutes
        // Key is specific to the user ID
        Cache::put('email_update_' . $user->id, [
            'new_email' => $request->new_email,
            'otp' => $otp
        ], 600);

        // Send OTP to the CURRENT email address
        Mail::to($user->email)->send(new EmailUpdateOtpMail($otp));

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your current email address.'
        ]);
    }

    /**
     * Step 2: Verify OTP and update the email.
     */
    public function completeEmailUpdate(Request $request)
    {
        $request->validate([
            'otp' => ['required', 'numeric', 'digits:6'],
        ]);

        $user = $request->user();
        $cachedData = Cache::get('email_update_' . $user->id);

        if (!$cachedData || $cachedData['otp'] != $request->otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired OTP.'
            ], 422);
        }

        // Update the email
        $user->update([
            'email' => $cachedData['new_email']
        ]);

        // Clear the cache
        Cache::forget('email_update_' . $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Email updated successfully.',
            'user' => $user
        ]);
    }
}