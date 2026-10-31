<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenders: categories with spec templates, the tender with its items, specs and documents,
     * prequalification, bids with their lines and spec answers, negotiation messages, awards and
     * the tender's history. Files are contents rows held here, not through content_map.
     */
    public function up(): void
    {
        Schema::create('market_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('market_categories')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('market_category_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('market_categories')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('label');
            $table->string('kind', 10)->default('text');
            $table->string('operator', 5)->nullable();
            $table->string('value')->nullable();
            $table->string('unit', 30)->nullable();
            $table->boolean('must')->default(false);
            $table->timestamps();
        });

        Schema::create('market_tenders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->nullable()->unique();
            $table->string('title');
            $table->string('type', 10)->default('goods');
            $table->text('description')->nullable();
            $table->foreignId('organizer_company_id')->nullable()->constrained('companies');
            $table->foreignId('organizer_user_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained('market_categories')->nullOnDelete();
            $table->string('incoterm', 10)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->string('status', 15)->default('draft')->index();
            $table->json('rule')->nullable();
            $table->unsignedInteger('round')->default(1);
            $table->timestamp('final_round_closes_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('market_tender_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name');
            $table->decimal('quantity', 15, 3)->nullable();
            $table->string('unit', 30)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('market_categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->decimal('budget', 18, 2)->nullable();
            $table->boolean('show_budget')->default(false);
            $table->timestamps();
        });

        Schema::create('market_tender_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_item_id')->constrained('market_tender_items')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('label');
            $table->string('kind', 10)->default('text');
            $table->string('operator', 5)->nullable();
            $table->string('value')->nullable();
            $table->string('unit', 30)->nullable();
            $table->boolean('must')->default(false);
            $table->timestamps();
        });

        Schema::create('market_tender_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained('market_tender_items')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('contents');
            $table->timestamps();
        });

        Schema::create('market_tender_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('applicant_user_id')->constrained('users');
            $table->text('note')->nullable();
            $table->json('content_ids')->nullable();
            $table->string('status', 10)->default('pending');
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('market_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('submitted_by')->constrained('users');
            $table->unsignedInteger('round')->default(1);
            $table->string('status', 15)->default('draft')->index();
            $table->date('valid_until')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('quality_score', 6, 2)->nullable();
            $table->text('ruled_out_reason')->nullable();
            $table->boolean('overturned')->default(false);
            $table->boolean('shortlisted')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('market_bid_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('market_bids')->cascadeOnDelete();
            $table->foreignId('tender_item_id')->constrained('market_tender_items')->cascadeOnDelete();
            $table->decimal('price', 18, 2);
            $table->decimal('quantity', 15, 3)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('market_bid_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bid_id')->constrained('market_bids')->cascadeOnDelete();
            $table->foreignId('tender_spec_id')->constrained('market_tender_specs')->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->string('claim', 10)->default('meets');
            $table->foreignId('content_id')->nullable()->constrained('contents');
            $table->string('override_claim', 10)->nullable();
            $table->foreignId('override_by')->nullable()->constrained('users');
            $table->text('override_note')->nullable();
            $table->timestamps();
        });

        Schema::create('market_tender_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('bid_id')->constrained('market_bids')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('side', 10);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('market_tender_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('bid_id')->constrained('market_bids')->cascadeOnDelete();
            $table->foreignId('tender_item_id')->nullable()->constrained('market_tender_items')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('market_tender_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('market_tenders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('type', 30);
            $table->text('note')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('market_category_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('market_categories')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'category_id']);
        });
    }

    public function down(): void
    {
        foreach (['market_category_follows', 'market_tender_events', 'market_tender_awards', 'market_tender_messages', 'market_bid_specs', 'market_bid_lines', 'market_bids', 'market_tender_applications', 'market_tender_files', 'market_tender_specs', 'market_tender_items', 'market_tenders', 'market_category_specs', 'market_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
