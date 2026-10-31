<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer types (metas: 2026_12_03_000011_create_crm_customer_type_metas). One customer
 * (crm_customers, the CRM's register of companies and people) can be several things at once:
 * customer, supplier, transporter, employer, partner... crm_customer_types lists the types and
 * crm_customer_type_dtl gives each customer its types (once each, since a date).
 *
 * A type with is_supplier links the customer to Procurement's suppliers, as before: on MySQL
 * triggers keep crm_customers.is_supplier equal to "has a supplier type", so the customer
 * form's supplier step, the Make supplier button and the Suppliers list keep working.
 *
 * Existing customers get the Customer type, and those marked as suppliers the Supplier type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customer_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('crm_customer_type_dtl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('crm_customers')->cascadeOnDelete();
            $table->foreignId('customer_type_id')->constrained('crm_customer_types')->restrictOnDelete();
            $table->date('since')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'customer_type_id']);
        });

        DB::transaction(function () {
            $now = now();
            foreach ([
                ['customer', 'Customer', false],
                ['supplier', 'Supplier', true],
                ['transporter', 'Transporter', false],
                ['employer', 'Employer', false],
                ['partner', 'Partner', false],
            ] as [$code, $name, $supplier]) {
                DB::table('crm_customer_types')->insert(['code' => $code, 'name' => $name, 'is_supplier' => $supplier, 'created_at' => $now, 'updated_at' => $now]);
            }
            $types = DB::table('crm_customer_types')->pluck('id', 'code');
            foreach (DB::table('crm_customers')->get(['id', 'is_supplier', 'created_at']) as $customer) {
                foreach (array_filter(['customer' => true, 'supplier' => (bool) $customer->is_supplier]) as $code => $yes) {
                    DB::table('crm_customer_type_dtl')->insert([
                        'customer_id' => $customer->id, 'customer_type_id' => $types[$code],
                        'since' => $customer->created_at ? substr((string) $customer->created_at, 0, 10) : null,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });

        if (DB::getDriverName() === 'mysql') {
            $sync = fn (string $row) => "UPDATE crm_customers SET is_supplier = EXISTS (
                    SELECT 1 FROM crm_customer_type_dtl d JOIN crm_customer_types t ON t.id = d.customer_type_id
                    WHERE d.customer_id = {$row}.customer_id AND t.is_supplier = 1)
                WHERE id = {$row}.customer_id";
            DB::unprepared('CREATE TRIGGER crm_customer_type_dtl_insert AFTER INSERT ON crm_customer_type_dtl FOR EACH ROW '.$sync('NEW'));
            DB::unprepared('CREATE TRIGGER crm_customer_type_dtl_delete AFTER DELETE ON crm_customer_type_dtl FOR EACH ROW '.$sync('OLD'));
            DB::unprepared('CREATE TRIGGER crm_customer_type_dtl_update AFTER UPDATE ON crm_customer_type_dtl FOR EACH ROW BEGIN '.$sync('NEW').'; '.$sync('OLD').'; END');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['insert', 'delete', 'update'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS crm_customer_type_dtl_{$event}");
            }
        }
        Schema::dropIfExists('crm_customer_type_dtl');
        Schema::dropIfExists('crm_customer_types');
    }
};
