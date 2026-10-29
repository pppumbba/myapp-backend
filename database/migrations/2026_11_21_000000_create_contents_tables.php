<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File attachments. contents holds each uploaded file; content_map ties it to a record saved
 * through a form meta (workflow_meta_id) and the singlefile/multifile param it was put in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            // Where the file sits on the local disk (storage/app/private), not a URL.
            $table->string('physical_path', 500);
            $table->string('file_name');
            $table->string('file_extension', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('type_id')->nullable()->index();
            $table->boolean('is_public')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('content_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            // Not foreign keys: restoring a meta version or a deleted meta puts params back under
            // their old ids, and their files must still be there, not cascaded away.
            $table->unsignedBigInteger('workflow_meta_id');
            $table->unsignedBigInteger('param_id');
            $table->timestamps();

            $table->unique(['content_id', 'record_id', 'param_id']);
            $table->index(['workflow_meta_id', 'record_id']);
            $table->index(['param_id', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_map');
        Schema::dropIfExists('contents');
    }
};
