<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-accounts, and the first three of them: the staff-cost split.
 *
 * "Operating Expenses" as a single line is not what a set of financial
 * statements looks like. The real thing breaks it down — accounting fee, bank
 * charges, insurance, staff costs — and staff costs break down again into
 * salaries, CPF and SDL.
 *
 * `parent_code` is what makes that a hierarchy rather than a naming habit. The
 * code could equally be read as "everything starting 5100-", but the chart
 * belongs to the client: if staff costs are ever renumbered under 6000, a
 * stated parent survives that and a parsed prefix does not.
 *
 * Payroll already works these three figures out for every run — gross
 * earnings, employer CPF, SDL — so nothing new is calculated here. They simply
 * gain somewhere of their own to land instead of being posted as one lump.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'parent_code')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('parent_code', 20)->nullable()->after('code');
                $table->index('parent_code');
            });
        }

        $now = now();
        DB::table('accounts')->insertOrIgnore([
            [
                'code' => '5100-01', 'parent_code' => '5100', 'name' => 'Staff costs — Salaries',
                'type' => 'expense', 'gst_code' => 'OS',
                'description' => 'Gross wages earned by staff before deductions.',
                'is_active' => true, 'sort' => 101, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => '5100-02', 'parent_code' => '5100', 'name' => 'Staff costs — CPF (employer)',
                'type' => 'expense', 'gst_code' => 'OS',
                'description' => "The employer's CPF contribution — a cost to the business, not a deduction from the employee.",
                'is_active' => true, 'sort' => 102, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => '5100-03', 'parent_code' => '5100', 'name' => 'Staff costs — SDL & other contributions',
                'type' => 'expense', 'gst_code' => 'OS',
                'description' => 'Skills Development Levy and other statutory employer contributions.',
                'is_active' => true, 'sort' => 103, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('code', ['5100-01', '5100-02', '5100-03'])->delete();

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['parent_code']);
            $table->dropColumn('parent_code');
        });
    }
};
