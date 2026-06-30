<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
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
            'login' => 'required|string',   // username OR email
            'password' => 'required|string',
        ]);

        $login = trim($request->input('login'));
        $password = $request->input('password');

        // 1) Username is unique → a direct lookup. 2) Otherwise the handle is an
        // email, which several staff may share, so we check the password against
        // each active account on that email and sign in the one that matches.
        $candidates = User::where('is_active', true)
            ->where(function ($q) use ($login) {
                $q->where('username', $login)->orWhere('email', $login);
            })
            ->get();

        $user = $candidates->first(fn (User $u) => Hash::check($password, $u->password));

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials.'], 401);
        }

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
            // Email is no longer unique (staff can share a mailbox), so no unique rule.
            'new_email' => ['required', 'email'],
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