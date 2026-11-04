<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'main';

    /**
     * customers: organisations on the platform, each with its own schema (schema_name). code is
     * used in public addresses; files_folder null keeps files in the root (the first customer).
     */
    public function up(): void
    {
        Schema::connection('main')->create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('schema_name', 64)->unique();
            $table->string('files_folder')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('main')->dropIfExists('customers');
    }
};
