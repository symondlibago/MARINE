<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll — one run a month, one line per employee.
 *
 * A line carries BOTH the typed inputs and the calculated figures. The figures
 * are stored rather than derived on read so that a finalised month can never
 * change afterwards, even if an employee's salary or CPF scheme is edited
 * later. The employee's details are snapshotted onto the line for the same
 * reason: last March's payslip must still print last March's NRIC and salary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->unique();            // always the 1st of the month
            $table->date('payment_date')->nullable();
            $table->string('status', 16)->default('draft'); // draft | finalised
            $table->string('currency', 8)->default('SGD');
            $table->text('notes')->nullable();
            $table->timestamp('finalised_at')->nullable();
            $table->foreignId('finalised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('payroll_employees')->nullOnDelete();

            // --- snapshot of the employee as they were this month -------------
            $table->string('code', 32);
            $table->string('full_name');
            $table->string('nric', 32)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('cpf_scheme', 48);
            $table->string('shg_fund', 16);
            $table->string('payment_method')->nullable();
            $table->string('bank_ref')->nullable();
            $table->decimal('monthly_basic', 12, 2)->default(0);

            // --- what the office types ---------------------------------------
            $table->decimal('basic_pay', 12, 2)->default(0);       // starts at monthly_basic, editable
            $table->decimal('fixed_allowance', 12, 2)->default(0);
            $table->decimal('other_allowance', 12, 2)->default(0);
            $table->decimal('ot_pay', 12, 2)->default(0);
            $table->decimal('bonus_aw', 12, 2)->default(0);        // bonus / commission = Additional Wages
            $table->decimal('aw_subject_override', 12, 2)->nullable();
            $table->decimal('no_pay_leave', 12, 2)->default(0);
            $table->decimal('other_deduction', 12, 2)->default(0);
            $table->decimal('other_employer_cost', 12, 2)->default(0);
            $table->string('remarks')->nullable();

            // --- what the calculator worked out ------------------------------
            $table->decimal('gross_earnings', 12, 2)->default(0);
            $table->decimal('ow_subject', 12, 2)->default(0);
            $table->decimal('aw_subject', 12, 2)->default(0);
            $table->decimal('cpf_wages', 12, 2)->default(0);
            $table->unsignedTinyInteger('age_band')->default(1);
            $table->decimal('employee_cpf', 12, 2)->default(0);
            $table->decimal('employer_cpf', 12, 2)->default(0);
            $table->decimal('total_cpf', 12, 2)->default(0);
            $table->decimal('shg_deduction', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);
            $table->decimal('sdl', 12, 2)->default(0);
            $table->decimal('total_employer_cost', 12, 2)->default(0);
            $table->json('review_flags')->nullable();

            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['run_id', 'code']);
            $table->index(['run_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
