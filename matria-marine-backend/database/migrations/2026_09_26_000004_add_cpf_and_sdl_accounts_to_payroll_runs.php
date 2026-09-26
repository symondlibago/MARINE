<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put the employer's CPF and the levy.
 *
 * A payroll run posts three different costs — what staff earned, the CPF the
 * employer adds on top, and the SDL on top of that — so one account code on
 * the run could only ever describe a third of it.
 *
 * `account_code` keeps its meaning and becomes the salaries account; the other
 * two are new. Runs that were coded to 5100 as a whole are moved to 5100-01,
 * because that is what the figure on them actually was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('cpf_account_code', 20)->nullable()->after('account_code');
            $table->string('sdl_account_code', 20)->nullable()->after('cpf_account_code');
        });

        // A run pointing at the parent was really pointing at salaries.
        DB::table('payroll_runs')
            ->where(fn ($q) => $q->whereNull('account_code')->orWhere('account_code', '5100'))
            ->update(['account_code' => '5100-01']);

        DB::table('payroll_runs')->whereNull('cpf_account_code')->update(['cpf_account_code' => '5100-02']);
        DB::table('payroll_runs')->whereNull('sdl_account_code')->update(['sdl_account_code' => '5100-03']);
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['cpf_account_code', 'sdl_account_code']);
        });
    }
};
