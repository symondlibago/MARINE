<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Local Backup screen gets its own checkbox under Manage Staff.
 *
 * It was open to every admin while it had no permission of its own. Switching
 * one on must not take it away from anyone already using it, so every existing
 * admin is granted it here — exactly as the original page permissions did.
 * Access narrows only when a super admin unticks the box.
 *
 * Super admins get no row: they are never checked.
 *
 * The key is written out rather than read from PortalPages, so this migration
 * keeps doing the same thing even after that class moves on.
 */
return new class extends Migration
{
    private const PAGE = 'page.local_backup';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => self::PAGE,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')
            ->where('guard_name', 'web')
            ->where('name', self::PAGE)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        $rows = DB::table('users')
            ->where('role', 'admin')
            ->pluck('id')
            ->map(fn ($userId) => [
                'permission_id' => $permissionId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $userId,
            ])
            ->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('model_has_permissions')->insertOrIgnore($chunk);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', self::PAGE)->value('id');

        if ($id) {
            DB::table('model_has_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
