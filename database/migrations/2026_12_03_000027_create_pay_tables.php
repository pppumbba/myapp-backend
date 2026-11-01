<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the payroll app (triggers: 2026_12_03_000028, metas: 2026_12_03_000029).
 *
 * An employee has a monthly base salary and recurring pay items. A run is one month's payroll: it
 * copies the rates in force, makes a slip per employee on the books that month, and each slip
 * keeps its own copy of what it was worked out from, so an old payslip keeps what it said.
 * Runs go draft -> approved -> paid; draft and approved runs can also be voided, paid ones cannot.
 *
 * pay_slip_lines carries run_id and employee_id beside slip_id so that a run can insert all its
 * lines without reading pay_slips, which their triggers update (MySQL refuses that in one statement).
 *
 * The one row in pay_rates is a starting point to be confirmed, not advice: see its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pay_employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_no', 30)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name')->nullable();
            $table->string('register_no', 30)->nullable()->unique();
            $table->foreignId('department_id')->nullable()->constrained('pay_departments')->nullOnDelete();
            $table->string('position')->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->date('hire_date');
            $table->date('end_date')->nullable();
            $table->decimal('base_salary', 14, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('bank_account', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('kind', 20)->default('earning');
            $table->decimal('default_amount', 14, 2)->default(0);
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_insurable')->default(true);
            $table->unsignedInteger('order_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pay_employee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('pay_employees')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('pay_components')->restrictOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'component_id']);
        });

        Schema::create('pay_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->date('effective_from');
            $table->decimal('si_employee_pct', 5, 2)->default(0);
            $table->decimal('si_employer_pct', 5, 2)->default(0);
            $table->decimal('si_monthly_cap', 14, 2)->nullable();
            $table->decimal('pit_pct', 5, 2)->default(0);
            $table->decimal('monthly_tax_credit', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pay_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_no', 30)->unique();
            $table->date('period_start');
            $table->date('period_end')->nullable();
            $table->date('pay_date')->nullable();
            $table->decimal('working_days', 5, 2)->default(22);
            $table->string('status', 20)->default('draft');
            $table->decimal('si_employee_pct', 5, 2)->nullable();
            $table->decimal('si_employer_pct', 5, 2)->nullable();
            $table->decimal('si_monthly_cap', 14, 2)->nullable();
            $table->decimal('pit_pct', 5, 2)->nullable();
            $table->decimal('monthly_tax_credit', 14, 2)->nullable();
            $table->unsignedInteger('employee_count')->nullable()->default(0);
            $table->decimal('gross_total', 16, 2)->nullable()->default(0);
            $table->decimal('si_employee_total', 16, 2)->nullable()->default(0);
            $table->decimal('si_employer_total', 16, 2)->nullable()->default(0);
            $table->decimal('pit_total', 16, 2)->nullable()->default(0);
            $table->decimal('deductions_total', 16, 2)->nullable()->default(0);
            $table->decimal('net_total', 16, 2)->nullable()->default(0);
            $table->decimal('employer_cost_total', 16, 2)->nullable()->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['period_start', 'status']);
        });

        Schema::create('pay_slips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('pay_employees')->restrictOnDelete();
            $table->string('employee_no', 30)->nullable();
            $table->string('employee_name')->nullable();
            $table->string('department_name')->nullable();
            $table->string('position')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account', 50)->nullable();
            $table->decimal('base_salary', 14, 2)->nullable();
            $table->decimal('worked_days', 5, 2)->nullable();
            $table->decimal('base_pay', 14, 2)->nullable()->default(0);
            $table->decimal('earnings_total', 14, 2)->nullable()->default(0);
            $table->decimal('gross_pay', 14, 2)->nullable()->default(0);
            $table->decimal('insurable_pay', 14, 2)->nullable()->default(0);
            $table->decimal('si_employee', 14, 2)->nullable()->default(0);
            $table->decimal('si_employer', 14, 2)->nullable()->default(0);
            $table->decimal('taxable_pay', 14, 2)->nullable()->default(0);
            $table->decimal('pit', 14, 2)->nullable()->default(0);
            $table->decimal('deductions_total', 14, 2)->nullable()->default(0);
            $table->decimal('net_pay', 14, 2)->nullable()->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });

        Schema::create('pay_slip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slip_id')->nullable()->constrained('pay_slips')->cascadeOnDelete();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->foreignId('component_id')->constrained('pay_components')->restrictOnDelete();
            $table->string('component_name')->nullable();
            $table->string('kind', 20)->nullable();
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_insurable')->default(true);
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'employee_id']);
        });

        $now = now();
        DB::table('pay_rates')->insert([
            'code' => 'START', 'name' => 'Starting rates: confirm with your accountant', 'effective_from' => '2020-01-01',
            'si_employee_pct' => 11.5, 'si_employer_pct' => 12.5, 'si_monthly_cap' => null, 'pit_pct' => 10, 'monthly_tax_credit' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $rows = [
            ['BONUS', 'Bonus', 'earning', true, true, 1],
            ['OVERTIME', 'Overtime', 'earning', true, true, 2],
            ['RESP', 'Responsibility allowance', 'earning', true, true, 3],
            ['MEAL', 'Meal allowance', 'earning', true, true, 4],
            ['TRANSPORT', 'Transport allowance', 'earning', true, true, 5],
            ['ADVANCE', 'Salary advance repaid', 'deduction', false, false, 6],
            ['LOAN', 'Staff loan repayment', 'deduction', false, false, 7],
            ['OTHER_DED', 'Other deduction', 'deduction', false, false, 8],
        ];
        foreach ($rows as [$code, $name, $kind, $taxable, $insurable, $order]) {
            DB::table('pay_components')->insert([
                'code' => $code, 'name' => $name, 'kind' => $kind, 'is_taxable' => $taxable, 'is_insurable' => $insurable,
                'order_number' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['pay_slip_lines', 'pay_slips', 'pay_runs', 'pay_rates', 'pay_employee_components', 'pay_components', 'pay_employees', 'pay_departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
