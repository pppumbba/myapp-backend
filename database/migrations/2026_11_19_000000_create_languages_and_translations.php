<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Languages the app speaks, and translations of what admins build (meta names, field
 * labels, button labels, status names). Each translation belongs to the meta that owns the
 * row, so it is versioned, copied and deleted with that meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            // Labels are written in the source language; users without a choice get the default.
            $table->boolean('is_source')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order_number')->default(0);
            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('row_id');
            $table->string('field', 64);
            $table->string('locale', 10);
            $table->text('value');
            $table->timestamps();
            $table->unique(['table_name', 'row_id', 'field', 'locale']);
            $table->index(['meta_id', 'locale']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 10)->nullable()->after('theme_meta_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::dropIfExists('translations');
        Schema::dropIfExists('languages');
    }
};
