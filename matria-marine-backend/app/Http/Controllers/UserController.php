<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Display a listing of admin staff users.
     */
    public function index()
    {
        // Fetch users who are not instructors
        $staffRoles = ['Admin', 'Program Head', 'Cashier', 'Registrar'];
        $users = User::whereIn('role', $staffRoles)->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $users]);
    }

    /**
     * Store a newly created admin staff user in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'role' => ['required', Rule::in(['Program Head', 'Cashier', 'Registrar'])],
            'password' => 'required|string|min:8|confirmed',
            'secondary_pin' => 'nullable|string|digits:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'password' => Hash::make($request->password),
            'secondary_pin' => $request->secondary_pin ? Hash::make($request->secondary_pin) : null,
        ]);

        return response()->json(['success' => true, 'message' => 'Admin staff created successfully', 'data' => $user], 201);
    }

    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'role' => ['required', Rule::in(['Program Head', 'Cashier', 'Registrar', 'Admin'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $user->update($request->only('name', 'email', 'role'));

        return response()->json(['success' => true, 'message' => 'User updated successfully', 'data' => $user->fresh()]);
    }

    /**
     * Remove the specified admin staff user from storage.
     */
    public function destroy(User $user)
    {
        // Prevent deleting an instructor via this endpoint
        if (strtolower($user->role) === 'instructor') {
             return response()->json(['success' => false, 'message' => 'Instructors must be deleted from the Faculty page.'], 403);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'User deleted successfully']);
    }
}