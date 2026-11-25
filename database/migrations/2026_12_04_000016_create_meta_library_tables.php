<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each person's own lists of metas: the ones they starred, the ones they opened lately and the
 * ones others shared with them. Users live in the main schema, so user ids have no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_stars', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'meta_id']);
        });

        Schema::create('meta_views', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->primary(['user_id', 'meta_id']);
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::create('meta_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('shared_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['meta_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_shares');
        Schema::dropIfExists('meta_views');
        Schema::dropIfExists('meta_stars');
    }
};
