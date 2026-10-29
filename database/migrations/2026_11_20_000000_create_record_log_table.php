<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * metas.is_log: saves through this meta (a form, an Excel import, a matrix) are logged.
 * record_log: one row per save of a record: what changed, with its lines, who and how.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->boolean('is_log')->default(false)->after('is_active');
        });

        Schema::create('record_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->nullable()->constrained('metas')->nullOnDelete();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id');
            // created, updated, deleted
            $table->string('action', 10);
            // Fields as [old, new], and lines added, removed and changed.
            $table->json('changes')->nullable();
            // The whole record with its lines, as it was before a delete.
            $table->json('snapshot')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // form, process_flow, schedule, import, matrix
            $table->string('source', 20)->default('form');
            // Saves made together (a form and the steps of its process flow) share one.
            $table->uuid('batch_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['table_name', 'record_id', 'id']);
            $table->index(['meta_id', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_log');
        Schema::table('metas', fn (Blueprint $table) => $table->dropColumn('is_log'));
    }
};
