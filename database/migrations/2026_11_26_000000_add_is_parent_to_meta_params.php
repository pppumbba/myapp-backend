<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * meta_params.is_parent: the field holding the id of a record's parent record in the same
 * table (e.g. parent_id), so a grid shows its records as a tree, children under parents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->boolean('is_parent')->default(false)->after('is_label');
        });
    }

    public function down(): void
    {
        Schema::table('meta_params', fn (Blueprint $table) => $table->dropColumn('is_parent'));
    }
};
