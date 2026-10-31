<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM app's tables (its metas are in 2026_12_03_000001_create_crm_metas).
 *
 * Reused instead of duplicated: items are proc_items (the procurement catalogue), and a
 * customer that is also a supplier links to proc_suppliers (supplier_id), so purchases
 * stay proc_purchase_orders. Everything new is prefixed crm_.
 *
 * Status flows: leads, quotations and complaints have a workflow status (status_id, see
 * wfm_status); opportunities, orders and invoices keep a plain status/stage text.
 *
 * Line totals: on MySQL a trigger works out line_total = quantity x unit_price less the
 * discount on every insert and update, so a total is right whoever saves the line (forms,
 * process flows, imports). Form scripts show the same figure while typing.
 */
return new class extends Migration
{
    /** Tables with sales lines (quantity, unit_price, discount_pct, line_total). */
    private const LINE_TABLES = ['crm_quotation_lines', 'crm_sales_order_lines', 'crm_invoice_lines'];

    public function up(): void
    {
        Schema::create('crm_customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('kind', 20)->default('company');
            $table->string('name');
            $table->string('register_no', 50)->nullable()->index();
            $table->foreignId('category_id')->nullable()->constrained('crm_customer_categories')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->boolean('is_supplier')->default(false);
            $table->foreignId('supplier_id')->nullable()->constrained('proc_suppliers')->nullOnDelete();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->string('kind', 20)->default('office');
            $table->string('line1');
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Mongolia');
            $table->string('postal_code', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->string('lead_no', 30)->unique();
            $table->string('contact_name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('source', 30)->default('website');
            $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->decimal('estimated_value', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            // Set when converted; no FK, as opportunities also point back at their lead.
            $table->unsignedBigInteger('opportunity_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('crm_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('opp_no', 30)->unique();
            $table->string('name');
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->string('stage', 20)->default('prospecting');
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedTinyInteger('probability')->default(10);
            $table->date('expected_close')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('next_step')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_lead_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained('crm_leads')->cascadeOnDelete();
            $table->string('kind', 20)->default('company');
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('crm_customer_categories')->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('opportunity_name');
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('expected_close')->nullable();
            $table->date('converted_on');
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('kind', 20)->default('call');
            $table->string('subject');
            $table->text('details')->nullable();
            $table->dateTime('activity_at');
            $table->unsignedInteger('duration_min')->nullable();
            $table->string('outcome')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->date('due_on');
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('priority', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit', 20)->default('hour');
            $table->decimal('rate', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->foreignId('customer_category_id')->nullable()->constrained('crm_customer_categories')->nullOnDelete();
            $table->string('currency', 3)->default('MNT');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_price_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('crm_price_lists')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('proc_items')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('crm_services')->restrictOnDelete();
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('min_qty', 12, 2)->default(1);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quote_no', 30)->unique();
            $table->string('doc_type', 20)->default('quotation');
            $table->string('title');
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('intro')->nullable();
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            // The order made from it; no FK, as orders also point back at their quotation.
            $table->unsignedBigInteger('sales_order_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('crm_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('so_number', 30)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('quotation_id')->nullable()->unique()->constrained('crm_quotations')->nullOnDelete();
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->string('status', 20)->default('open');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 30)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->unique()->constrained('crm_sales_orders')->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('status', 20)->default('issued');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        foreach (['crm_quotation_lines' => ['quotation_id', 'crm_quotations'], 'crm_sales_order_lines' => ['sales_order_id', 'crm_sales_orders'], 'crm_invoice_lines' => ['invoice_id', 'crm_invoices']] as $name => [$parent, $parentTable]) {
            Schema::create($name, function (Blueprint $table) use ($parent, $parentTable) {
                $table->id();
                $table->foreignId($parent)->constrained($parentTable)->cascadeOnDelete();
                $table->foreignId('price_line_id')->nullable()->constrained('crm_price_list_lines')->nullOnDelete();
                $table->foreignId('item_id')->nullable()->constrained('proc_items')->restrictOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('crm_services')->restrictOnDelete();
                $table->string('description')->nullable();
                $table->decimal('quantity', 12, 2)->default(1);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('discount_pct', 5, 2)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->timestamps();
            });
        }

        Schema::create('crm_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 30)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->string('method', 20)->default('bank transfer');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_complaints', function (Blueprint $table) {
            $table->id();
            $table->string('complaint_no', 30)->unique();
            $table->foreignId('customer_id')->constrained('crm_customers')->restrictOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('category', 20)->nullable();
            $table->string('severity', 20)->default('medium');
            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->date('received_on');
            $table->date('due_on')->nullable();
            $table->date('resolved_on')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('wfm_status')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->date('received_on');
            $table->string('channel', 20)->default('email');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            $total = 'ROUND(COALESCE(NEW.quantity, 0) * COALESCE(NEW.unit_price, 0) * (1 - COALESCE(NEW.discount_pct, 0) / 100), 2)';
            foreach (self::LINE_TABLES as $name) {
                foreach (['insert', 'update'] as $event) {
                    DB::unprepared("CREATE TRIGGER {$name}_total_{$event} BEFORE ".strtoupper($event)." ON {$name} FOR EACH ROW SET NEW.line_total = {$total}");
                }
            }
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'crm_feedback', 'crm_complaints', 'crm_payments',
            'crm_invoice_lines', 'crm_sales_order_lines', 'crm_quotation_lines',
            'crm_invoices', 'crm_sales_orders', 'crm_quotations',
            'crm_price_list_lines', 'crm_price_lists', 'crm_services',
            'crm_tasks', 'crm_activities', 'crm_lead_conversions', 'crm_opportunities', 'crm_leads',
            'crm_addresses', 'crm_contacts', 'crm_customers', 'crm_customer_categories',
        ] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::enableForeignKeyConstraints();
    }
};
