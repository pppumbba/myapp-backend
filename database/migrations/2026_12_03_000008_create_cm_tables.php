<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The contract management app's tables (its metas are in 2026_12_03_000009_create_cm_metas).
 * Everything is prefixed cm_ (the market module already has contracts, contract_items...).
 *
 * A contract (cm_contracts) has two or more parties (cm_contract_parties: client, contractor,
 * subcontractor, consortium member, guarantor; a party can be a CRM customer or a Procurement
 * supplier, or just named), its terms (clauses, from templates), its lines (items, services
 * and jobs from proc_items), penalty clauses, a payment schedule, collaterals (guarantees,
 * bonds, deposits). Its status is a workflow: draft, review, approved, active, suspended,
 * completed, terminated, expired.
 *
 * After signing: extensions and amendments (time, value, scope) change its end date and value
 * once approved (amended_amount); a termination ends it; execution is followed by progress
 * reports and completion acts (what was done and accepted, line by line, which payments go
 * against); performance reviews rate the parties; fines are imposed under the penalty
 * clauses; payments are recorded against the schedule. cm_alerts holds the reminders sent
 * (contracts ending, collaterals expiring, payments due), one per subject per day.
 *
 * On MySQL triggers work out line_total (quantity x unit price) on contract and act lines, and
 * a review's overall score (the average of its scores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cm_contract_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('direction', 20)->default('purchase');
            $table->unsignedSmallInteger('default_months')->nullable();
            $table->boolean('requires_collateral')->default(false);
            $table->decimal('collateral_pct', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_term_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('category', 30)->default('general');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_standard')->default(true);
            $table->timestamps();
        });

        Schema::create('cm_penalty_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('kind', 30)->default('late_delivery');
            $table->string('basis', 20)->default('per_day_pct');
            $table->decimal('rate', 10, 4)->default(0);
            $table->decimal('cap_pct', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_no', 30)->unique();
            $table->string('title');
            $table->foreignId('contract_type_id')->nullable()->constrained('cm_contract_types')->nullOnDelete();
            $table->string('direction', 20)->default('purchase');
            $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('department')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('original_end_date')->nullable();
            $table->date('signed_on')->nullable();
            $table->string('currency', 3)->default('MNT');
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->decimal('amended_amount', 16, 2)->default(0);
            $table->decimal('advance_pct', 5, 2)->default(0);
            $table->decimal('retention_pct', 5, 2)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->date('terminated_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_contract_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->string('role', 30)->default('contractor');
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('proc_suppliers')->nullOnDelete();
            $table->string('name');
            $table->string('register_no', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('signatory')->nullable();
            $table->string('signatory_position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->decimal('share_pct', 5, 2)->nullable();
            $table->boolean('is_lead')->default(false);
            $table->timestamps();
        });

        Schema::create('cm_contract_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('cm_term_templates')->nullOnDelete();
            $table->string('clause_no', 20)->nullable();
            $table->string('category', 30)->default('general');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('unit', 20)->default('pcs');
            $table->decimal('quantity', 14, 3)->default(1);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_contract_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->foreignId('penalty_type_id')->nullable()->constrained('cm_penalty_types')->nullOnDelete();
            $table->string('basis', 20)->default('per_day_pct');
            $table->decimal('rate', 10, 4)->default(0);
            $table->decimal('cap_pct', 5, 2)->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->string('milestone');
            $table->string('kind', 20)->default('progress');
            $table->date('due_date')->nullable();
            $table->decimal('pct', 5, 2)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_completion_acts', function (Blueprint $table) {
            $table->id();
            $table->string('act_no', 30)->unique();
            $table->foreignId('contract_id')->constrained('cm_contracts')->restrictOnDelete();
            $table->date('act_date');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('accepted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_completion_act_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('act_id')->constrained('cm_completion_acts')->cascadeOnDelete();
            $table->foreignId('contract_line_id')->nullable()->constrained('cm_contract_lines')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('cm_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 30)->unique();
            $table->foreignId('contract_id')->constrained('cm_contracts')->restrictOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('cm_payment_schedules')->nullOnDelete();
            $table->foreignId('act_id')->nullable()->constrained('cm_completion_acts')->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 16, 2);
            $table->string('method', 20)->default('bank transfer');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_collaterals', function (Blueprint $table) {
            $table->id();
            $table->string('collateral_no', 30)->unique();
            $table->foreignId('contract_id')->constrained('cm_contracts')->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('cm_contract_parties')->nullOnDelete();
            $table->string('kind', 30)->default('performance_bond');
            $table->string('issuer')->nullable();
            $table->string('reference_no')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('currency', 3)->default('MNT');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('released_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_extensions', function (Blueprint $table) {
            $table->id();
            $table->string('ext_no', 30)->unique();
            $table->foreignId('contract_id')->constrained('cm_contracts')->restrictOnDelete();
            $table->string('kind', 20)->default('time');
            $table->date('signed_on')->nullable();
            $table->date('old_end_date')->nullable();
            $table->date('new_end_date')->nullable();
            $table->decimal('amount_change', 16, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('applied_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_terminations', function (Blueprint $table) {
            $table->id();
            $table->string('termination_no', 30)->unique();
            $table->foreignId('contract_id')->unique()->constrained('cm_contracts')->restrictOnDelete();
            $table->date('notice_date')->nullable();
            $table->date('terminated_on');
            $table->string('initiated_by', 20)->default('mutual');
            $table->string('reason_kind', 30)->default('mutual');
            $table->text('reason')->nullable();
            $table->decimal('settlement_amount', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->date('report_date');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->string('health', 20)->default('on_track');
            $table->text('work_done')->nullable();
            $table->text('issues')->nullable();
            $table->text('next_steps')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cm_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('cm_contract_parties')->nullOnDelete();
            $table->date('review_date');
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedTinyInteger('timeliness')->nullable();
            $table->unsignedTinyInteger('cost')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->decimal('overall', 3, 1)->nullable();
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('reviewer_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('cm_fines', function (Blueprint $table) {
            $table->id();
            $table->string('fine_no', 30)->unique();
            $table->foreignId('contract_id')->constrained('cm_contracts')->restrictOnDelete();
            $table->foreignId('penalty_id')->nullable()->constrained('cm_contract_penalties')->nullOnDelete();
            $table->foreignId('party_id')->nullable()->constrained('cm_contract_parties')->nullOnDelete();
            $table->date('imposed_on');
            $table->string('reason');
            $table->string('basis', 20)->default('per_day_pct');
            $table->decimal('base_amount', 16, 2)->default(0);
            $table->unsignedInteger('days_late')->default(0);
            $table->decimal('rate', 10, 4)->default(0);
            $table->decimal('cap_pct', 5, 2)->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('status', 20)->default('imposed');
            $table->date('paid_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('cm_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->string('ref_key', 60);
            $table->foreignId('contract_id')->constrained('cm_contracts')->cascadeOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('title');
            $table->date('due_on')->nullable();
            $table->date('alerted_on');
            $table->timestamps();
            $table->unique(['ref_key', 'alerted_on']);
        });

        if (DB::getDriverName() === 'mysql') {
            $total = 'ROUND(COALESCE(NEW.quantity, 0) * COALESCE(NEW.unit_price, 0), 2)';
            foreach (['cm_contract_lines', 'cm_completion_act_lines'] as $name) {
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$name}_total_{$event} BEFORE ".strtoupper($event)." ON {$name} FOR EACH ROW SET NEW.line_total = {$total}");
                }
            }
            $scores = ['quality', 'timeliness', 'cost', 'communication'];
            $sum = implode(' + ', array_map(fn ($s) => "COALESCE(NEW.{$s}, 0)", $scores));
            $count = implode(' + ', array_map(fn ($s) => "(NEW.{$s} IS NOT NULL)", $scores));
            foreach (['insert', 'update'] as $event) {
                DB::unprepared("CREATE TRIGGER cm_performance_reviews_overall_{$event} BEFORE ".strtoupper($event)." ON cm_performance_reviews FOR EACH ROW SET NEW.overall = IF(({$count}) = 0, NULL, ROUND(({$sum}) / ({$count}), 1))");
            }
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'cm_alerts', 'cm_fines', 'cm_performance_reviews', 'cm_progress_reports', 'cm_terminations', 'cm_extensions',
            'cm_collaterals', 'cm_payments', 'cm_completion_act_lines', 'cm_completion_acts', 'cm_payment_schedules',
            'cm_contract_penalties', 'cm_contract_lines', 'cm_contract_terms', 'cm_contract_parties', 'cm_contracts',
            'cm_penalty_types', 'cm_term_templates', 'cm_contract_types',
        ] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::enableForeignKeyConstraints();
    }
};
