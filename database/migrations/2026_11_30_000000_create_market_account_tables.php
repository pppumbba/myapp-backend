<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market accounts: people verified through DAN (on users), companies checked through ХУР,
 * who acts for them (members and their rights), the requests to become a company's
 * representative, and invites for colleagues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The person's registry number (регистрийн дугаар) and name as DAN gave them.
            $table->string('registry_number', 10)->nullable()->unique();
            $table->string('verified_name')->nullable();
            $table->timestamp('verified_at')->nullable();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('registry_number', 7)->unique();
            $table->string('name');
            // active or liquidated, as ХУР last said.
            $table->string('status', 20);
            $table->string('director_registry_number', 10)->nullable();
            $table->string('director_name')->nullable();
            $table->timestamp('checked_at');
            // Set when ХУР says the company is gone: its members can no longer act for it.
            $table->timestamp('frozen_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('company_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_bid')->default(false);
            $table->boolean('can_sign')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::create('representation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // director (approved through a link) or attorney (a document staff review).
            $table->string('method', 10);
            $table->string('status', 10)->default('pending');
            // Only the hash of the director's link token is kept.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('company_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->boolean('can_bid')->default(false);
            $table->boolean('can_sign')->default(false);
            $table->boolean('can_manage')->default(false);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invites');
        Schema::dropIfExists('representation_requests');
        Schema::dropIfExists('company_members');
        Schema::dropIfExists('companies');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['registry_number']);
            $table->dropColumn(['registry_number', 'verified_name', 'verified_at']);
        });
    }
};
