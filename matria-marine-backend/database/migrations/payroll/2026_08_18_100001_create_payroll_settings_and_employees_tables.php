<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll — the company header and the employee master.
 *
 * A separate operation from procurement and from provisions: its own tables,
 * its own prefix, nothing shared but the staff login.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row. The letterhead every payslip is printed with.
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Matria Marine');
            $table->string('uen')->nullable();
            $table->string('company_address')->nullable();
            $table->string('currency', 8)->default('SGD');
            $table->string('default_payment_method')->default('Bank Transfer');
            $table->string('payslip_footer')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();          // MM001
            $table->string('full_name');
            $table->string('nric', 32)->nullable();         // NRIC / FIN / work pass
            $table->date('date_of_birth')->nullable();      // drives the CPF age band
            $table->string('status', 16)->default('active');
            $table->string('cpf_scheme', 48)->default('SC / SPR 3+ (Full)');
            $table->string('shg_fund', 16)->default('None'); // CDAC / ECF / MBMF / SINDA / None
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('fixed_allowance', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('bank_ref')->nullable();
            $table->string('job_title')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_employees');
        Schema::dropIfExists('payroll_settings');
    }
};
