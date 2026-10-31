<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Market part 2: contracts between a buyer and a vendor (each a company or a verified person),
 * their items, payment steps, signatures, timeline, disputes and ratings; fee settings; payments
 * through a provider; and the escrow ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->nullable()->unique();
            $table->string('source', 20)->default('direct');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title');
            $table->foreignId('buyer_company_id')->nullable()->constrained('companies');
            $table->foreignId('buyer_user_id')->nullable()->constrained('users');
            $table->foreignId('vendor_company_id')->nullable()->constrained('companies');
            $table->foreignId('vendor_user_id')->nullable()->constrained('users');
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('pledge')->nullable();
            $table->decimal('pledge_amount', 15, 2)->nullable();
            $table->string('middleman', 10)->default('off');
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
        });

        Schema::create('contract_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('label');
            $table->decimal('percent', 5, 2);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('auto_release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('side', 10);
            $table->foreignId('user_id')->constrained('users');
            $table->string('registry_number', 20);
            $table->string('verified_name');
            $table->unsignedInteger('version');
            $table->timestamp('signed_at');
            $table->unique(['contract_id', 'side']);
        });

        Schema::create('contract_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('contract_disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users');
            $table->string('side', 10);
            $table->text('reason');
            $table->string('status', 10)->default('open')->index();
            $table->string('resolution', 10)->nullable();
            $table->text('decision')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('side', 10);
            $table->foreignId('rater_user_id')->constrained('users');
            $table->foreignId('rated_company_id')->nullable()->constrained('companies');
            $table->foreignId('rated_user_id')->nullable()->constrained('users');
            $table->unsignedTinyInteger('stars');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'side']);
        });

        Schema::create('market_fees', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->unique();
            $table->string('mode', 10);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
        $now = now();
        DB::table('market_fees')->insert([
            ['kind' => 'posting', 'mode' => 'fixed', 'amount' => 10000, 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'bidding', 'mode' => 'fixed', 'amount' => 5000, 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'contract', 'mode' => 'percent', 'amount' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['kind' => 'middleman', 'mode' => 'percent', 'amount' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->string('kind', 20);
            $table->decimal('amount', 15, 2);
            $table->json('lines')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('driver', 20);
            $table->string('invoice_id')->nullable();
            $table->text('qr_text')->nullable();
            $table->longText('qr_image')->nullable();
            $table->string('pay_url', 500)->nullable();
            $table->nullableMorphs('subject');
            $table->foreignId('contract_step_id')->nullable()->constrained('contract_steps');
            $table->string('receipt_number', 30)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('escrow_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_step_id')->constrained('contract_steps')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->string('type', 10);
            $table->decimal('amount', 15, 2);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['escrow_entries', 'payments', 'market_fees', 'ratings', 'contract_disputes', 'contract_events', 'contract_signatures', 'contract_steps', 'contract_items', 'contracts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
