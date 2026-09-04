<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Book a month's payroll to an account, so wages reach the P&L.
 *
 * Until now payroll was invisible to the accounts entirely: the whole wage
 * bill sat outside the income statement, which overstated net profit by the
 * cost of every employee.
 *
 * There is deliberately NO tax column here. Salaries are not a supply and not
 * a purchase — they are out of scope for GST, so there is no rate to record
 * and nothing to claim. {@see App\Support\AccountingBooks::payroll()} enforces
 * that regardless of which account is chosen, so booking wages to an SR
 * account can never push them into Box 5.
 *
 * Additive and nullable. A run with no account behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'account_code')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->string('account_code', 20)->nullable()->after('currency');
                $table->index('account_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'account_code')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropIndex('payroll_runs_account_code_index');
                $table->dropColumn('account_code');
            });
        }
    }
};
