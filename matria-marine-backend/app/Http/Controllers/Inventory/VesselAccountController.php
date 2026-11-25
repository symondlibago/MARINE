<?php

namespace App\Http\Controllers\Inventory;

use App\Models\Inventory\Vessel;
use App\Models\User;
use App\Support\Inventory\VesselAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * The logins that sail on a vessel: normally two, the master and the cook.
 *
 * Kept apart from the portal's Manage Staff screen, which is about the office.
 * These accounts never see procurement — they open one ship's stores and
 * nothing else — and they are added by whoever is adding the ship, next to
 * her settings, rather than from a staff list ashore.
 *
 * Office only, enforced at the gate. A master who could create accounts could
 * create himself a second one with more authority.
 */
class VesselAccountController extends InventoryController
{
    /** Her crew's accounts. */
    public function index(Vessel $vessel)
    {
        return $this->ok(['accounts' => $this->accounts($vessel)]);
    }

    public function store(Request $request, Vessel $vessel)
    {
        $data = $request->validate($this->rules(), $this->messages());

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'vessel_id' => $vessel->id,
            'is_active' => true,
        ]);

        $this->syncRole($user);

        $rank = VesselAccess::RANKS[$user->role];

        return $this->ok(
            ['accounts' => $this->accounts($vessel)],
            "{$rank} account for {$vessel->name} created. They sign in with {$user->username}.",
            201
        );
    }

    public function update(Request $request, Vessel $vessel, User $user)
    {
        $this->belongsToHer($vessel, $user);

        $data = $request->validate($this->rules($user), $this->messages());

        $user->fill(collect($data)->only(['name', 'email', 'username', 'phone', 'role'])->all());

        // Blank means "leave it alone". The office resets a forgotten password
        // by typing a new one, so an empty box must not clear it.
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];

            // Signing somebody out is the point of the switch. The active
            // check already refuses their token, but leaving it issued is
            // leaving a key under the mat.
            if (! $data['is_active']) {
                $user->tokens()->delete();
            }
        }

        $user->save();
        $this->syncRole($user);

        return $this->ok(['accounts' => $this->accounts($vessel)], 'Account updated.');
    }

    public function destroy(Vessel $vessel, User $user)
    {
        $this->belongsToHer($vessel, $user);

        $name = $user->name;
        $user->tokens()->delete();
        $user->delete();

        return $this->ok(['accounts' => $this->accounts($vessel)], "{$name}'s account removed.");
    }

    /**
     * This endpoint reaches crew accounts on this ship, and nothing else.
     *
     * Without it the route is a way for an admin to edit or delete any user
     * in the system — a super admin included — by putting their id in a
     * vessel's URL.
     */
    private function belongsToHer(Vessel $vessel, User $user): void
    {
        if ((int) $user->vessel_id !== (int) $vessel->id || ! $user->isOnBoard()) {
            abort(404, "That account is not one of {$vessel->name}'s.");
        }
    }

    private function accounts(Vessel $vessel): array
    {
        return $vessel->accounts()
            ->orderByRaw("CASE WHEN role = ? THEN 0 ELSE 1 END", [VesselAccess::MASTER])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'username' => $u->username,
                'phone' => $u->phone,
                'role' => $u->role,
                'rank' => VesselAccess::RANKS[$u->role] ?? $u->role,
                'is_active' => (bool) $u->is_active,
            ])
            ->all();
    }

    private function rules(?User $user = null): array
    {
        $editing = $user !== null;

        return [
            'name' => [$editing ? 'sometimes' : 'required', 'string', 'max:255'],
            // Not unique: a ship often has one address and both accounts use
            // it. They are told apart by username and password.
            'email' => [$editing ? 'sometimes' : 'required', 'email', 'max:255'],
            'username' => [
                $editing ? 'sometimes' : 'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('users', 'username')->ignore($user?->id),
            ],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => [$editing ? 'sometimes' : 'required', Rule::in(VesselAccess::ON_BOARD)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function messages(): array
    {
        return [
            'username.unique' => 'That username is already taken — try the vessel name and the rank, e.g. varun-cook.',
            'username.alpha_dash' => 'A username may use letters, numbers, dashes and underscores only.',
            'role.in' => 'A vessel account is either the master or the cook.',
        ];
    }

    /**
     * Keep the spatie role in step with the users.role column.
     *
     * The module's own gate reads the column, so nothing here depends on these
     * rows existing. They matter the other way round: the portal and payroll
     * routes are gated on spatie roles, and a crew account holding one of
     * those would be a way into procurement. findOrCreate because migrations
     * are run by hand on Railway and the rows may not be there yet.
     */
    private function syncRole(User $user): void
    {
        Role::findOrCreate($user->role, 'web');
        $user->syncRoles([$user->role]);
        $user->syncPermissions([]);
    }
}
