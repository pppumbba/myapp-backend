<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the point of sale app (triggers: 2026_12_03_000023, metas: 2026_12_03_000024).
 *
 * Goods are the shared catalogue (proc_items); pos_products adds what a shop needs on top of an
 * item: barcode, selling price, tax rate. Stock is the warehouse app's: a register sells out of
 * one warehouse (and location, when it has one), and a completed sale or return posts a
 * wh_documents row with lines into wh_stock_moves. Customers are the CRM's (crm_customers).
 *
 * A shift is one person's time on one register, from a float to a counted drawer. A sale is
 * held until it is completed, or void; a return is a draft until it is completed.
 * Amounts include tax. Money columns on a sale, its lines and a return are worked out by triggers.
 *
 * The tax rates and payment methods here are reference data the app cannot start without.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->decimal('rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('kind', 20)->default('card');
            $table->boolean('is_cash')->default(false);
            $table->unsignedInteger('order_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained('proc_items')->cascadeOnDelete();
            $table->string('barcode', 64)->nullable()->unique();
            $table->decimal('sale_price', 14, 2)->nullable()->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained('pos_tax_rates')->nullOnDelete();
            $table->boolean('on_sale')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('wh_locations')->nullOnDelete();
            $table->decimal('max_discount_pct', 5, 2)->nullable()->default(10);
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('shift_no', 30)->unique();
            $table->foreignId('register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->dateTime('opened_at')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable()->default(0);
            $table->dateTime('closed_at')->nullable();
            $table->decimal('sales_total', 14, 2)->nullable();
            $table->decimal('cash_sales', 14, 2)->nullable();
            $table->decimal('cash_refunds', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('cash_difference', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['register_id', 'status']);
            $table->index(['cashier_id', 'status']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 30)->unique();
            $table->string('status', 20)->default('held');
            $table->boolean('complete_on_save')->default(true);
            $table->dateTime('sold_at')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cashier_name')->nullable();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->decimal('subtotal', 14, 2)->nullable()->default(0);
            $table->decimal('discount_total', 14, 2)->nullable()->default(0);
            $table->decimal('tax_total', 14, 2)->nullable()->default(0);
            $table->decimal('total_amount', 14, 2)->nullable()->default(0);
            $table->decimal('paid_amount', 14, 2)->nullable()->default(0);
            $table->decimal('change_due', 14, 2)->nullable()->default(0);
            $table->foreignId('stock_document_id')->nullable()->constrained('wh_documents')->nullOnDelete();
            $table->foreignId('void_document_id')->nullable()->constrained('wh_documents')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'sold_at']);
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->decimal('list_price', 14, 2)->nullable();
            $table->decimal('discount_pct', 5, 2)->nullable()->default(0);
            $table->decimal('tax_rate', 5, 2)->nullable()->default(0);
            $table->decimal('gross_amount', 14, 2)->nullable()->default(0);
            $table->decimal('discount_amount', 14, 2)->nullable()->default(0);
            $table->decimal('tax_amount', 14, 2)->nullable()->default(0);
            $table->decimal('line_total', 14, 2)->nullable()->default(0);
            $table->timestamps();
        });

        Schema::create('pos_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $table->foreignId('method_id')->constrained('pos_payment_methods')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 30)->unique();
            $table->foreignId('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->boolean('complete_on_save')->default(true);
            $table->dateTime('returned_at')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cashier_name')->nullable();
            $table->foreignId('refund_method_id')->nullable()->constrained('pos_payment_methods')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable()->default(0);
            $table->decimal('tax_total', 14, 2)->nullable()->default(0);
            $table->foreignId('stock_document_id')->nullable()->constrained('wh_documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'returned_at']);
        });

        Schema::create('pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('pos_returns')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2)->nullable()->default(0);
            $table->decimal('tax_amount', 14, 2)->nullable()->default(0);
            $table->decimal('line_total', 14, 2)->nullable()->default(0);
            $table->boolean('restock')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('pos_tax_rates')->insert([
            ['code' => 'VAT10', 'name' => 'VAT 10%', 'rate' => 10, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ZERO', 'name' => 'No tax', 'rate' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('pos_payment_methods')->insert([
            ['code' => 'CASH', 'name' => 'Cash', 'kind' => 'cash', 'is_cash' => true, 'order_number' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CARD', 'name' => 'Card', 'kind' => 'card', 'is_cash' => false, 'order_number' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'QPAY', 'name' => 'QPay', 'kind' => 'qr', 'is_cash' => false, 'order_number' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'TRANSFER', 'name' => 'Bank transfer', 'kind' => 'transfer', 'is_cash' => false, 'order_number' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        foreach (['pos_return_lines', 'pos_returns', 'pos_sale_payments', 'pos_sale_lines', 'pos_sales', 'pos_shifts', 'pos_registers', 'pos_products', 'pos_payment_methods', 'pos_tax_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
