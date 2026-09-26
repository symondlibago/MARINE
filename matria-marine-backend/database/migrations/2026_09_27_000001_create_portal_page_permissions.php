<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One permission per portal screen, so a super admin can choose what each
 * admin sees — Payroll above all.
 *
 * Until now every admin could open every screen. Switching this on must not
 * change that for anyone already working in the system, so every existing
 * admin is given every page here. Nothing is taken away by deploying; access
 * only narrows when a super admin edits someone and unticks a box.
 *
 * Super admins get no rows at all — they are never checked.
 *
 * The page list is written out rather than read from PortalPages, so this
 * migration keeps doing the same thing even after the class moves on.
 */
return new class extends Migration
{
    private const PAGES = [
        'enquiries', 'offers', 'delivery_orders', 'credit_memos', 'invoices',
        'purchase_orders', 'return_notes', 'accounting', 'statements', 'reports',
        'operating_expenses', 'customers', 'vendors', 'sent_log', 'payroll',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(fn ($key) => [
            'name' => 'page.'.$key,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PAGES));

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', array_map(fn ($k) => 'page.'.$k, self::PAGES))
            ->pluck('id');

        $admins = DB::table('users')->where('role', 'admin')->pluck('id');

        $rows = [];
        foreach ($admins as $userId) {
            foreach ($permissionIds as $permissionId) {
                $rows[] = [
                    'permission_id' => $permissionId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $userId,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('model_has_permissions')->insertOrIgnore($chunk);
        }

        // Spatie caches the permission map; make sure it is rebuilt from here.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('name', 'like', 'page.%')->pluck('id');

        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
