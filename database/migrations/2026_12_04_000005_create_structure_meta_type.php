<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The structure meta type: its params are a table's columns, and "Apply to database" creates
 * the table (metas.table_name) or adds and changes its columns. meta_structures records which
 * table each structure made and the columns as last applied, so a structure only ever touches
 * its own table and knows what changed since.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('meta_types')->insertOrIgnore([
            'code' => 'structure',
            'name' => 'Structure',
            'is_active' => true,
            'description' => 'A database table: its params are the columns. Apply to database creates the table, then adds and changes columns.',
        ]);

        Schema::create('meta_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meta_id')->unique()->constrained('metas')->cascadeOnDelete();
            $table->string('table_name')->unique();
            $table->json('columns');
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_structures');
        DB::table('meta_types')->where('code', 'structure')->delete();
    }
};
