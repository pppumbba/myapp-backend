<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * patches: named sets of metas made here to ship to other installations. Released, a patch
 * keeps its file (package) as it was then.
 * patch_installs: patch files installed here, with what each did, so one can be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Also the metas the picked ones use (see MetaTransfer::export).
            $table->boolean('with_used')->default(false);
            // draft, released
            $table->string('status', 10)->default('draft');
            $table->longText('package')->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('patch_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained('patches')->cascadeOnDelete();
            $table->foreignId('meta_id')->constrained('metas')->cascadeOnDelete();
            $table->unique(['patch_id', 'meta_id']);
        });

        Schema::create('patch_installs', function (Blueprint $table) {
            $table->id();
            $table->string('patch_code', 100)->nullable();
            $table->string('patch_name');
            $table->text('description')->nullable();
            $table->string('hash', 64);
            // Where the patch was released (its app URL).
            $table->string('source')->nullable();
            $table->timestamp('released_at')->nullable();
            // replace, skip, copy: what was done with metas already here.
            $table->string('existing', 10);
            // MetaTransfer::import's result: each meta's action, id and the version it replaced; warnings.
            $table->json('result');
            $table->longText('package');
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patch_installs');
        Schema::dropIfExists('patch_metas');
        Schema::dropIfExists('patches');
    }
};
