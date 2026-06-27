<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Staff management — SUPER ADMIN only (gated in routes). Lets a super admin
 * add / edit / remove portal users as either super_admin or admin.
 */
class UserController extends Controller
{
    private array $roles = ['super_admin', 'admin'];

    public function index()
    {
        $users = User::orderByDesc('id')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role,
                'is_active' => (bool) $u->is_active,
                'created_at' => $u->created_at?->toDateString(),
            ]);

        return response()->json(['success' => true, 'data' => $users]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in($this->roles)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'is_active' => true,
        ]);
        $user->syncRoles([$data['role']]);

        return response()->json(['success' => true, 'message' => 'Staff member added.', 'data' => $user], 201);
    }

    public function update(Request $request, User $user)
    {
        // A blank password field on the edit form means "keep the current password".
        if ($request->input('password') === '') {
            $request->merge(['password' => null]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['sometimes', Rule::in($this->roles)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Don't let the last active super admin be demoted or disabled.
        $isDemotion = (array_key_exists('role', $data) && $data['role'] !== 'super_admin')
            || (array_key_exists('is_active', $data) && ! $data['is_active']);
        if ($user->role === 'super_admin' && $isDemotion && User::where('role', 'super_admin')->where('is_active', true)->count() <= 1) {
            return response()->json(['success' => false, 'message' => 'You cannot remove the last active super admin.'], 422);
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }
        if (array_key_exists('phone', $data)) {
            $user->phone = $data['phone'];
        }
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }
        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
            $user->save();
            $user->syncRoles([$data['role']]);
        } else {
            $user->save();
        }

        return response()->json(['success' => true, 'message' => 'Staff member updated.', 'data' => $user]);
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 422);
        }
        if ($user->role === 'super_admin' && User::where('role', 'super_admin')->where('is_active', true)->count() <= 1) {
            return response()->json(['success' => false, 'message' => 'You cannot delete the last active super admin.'], 422);
        }

        $user->delete();

        return response()->json(['success' => true, 'message' => 'Staff member removed.']);
    }
}
