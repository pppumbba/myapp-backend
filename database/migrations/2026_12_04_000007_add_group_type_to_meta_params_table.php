<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a form field takes its value when a consolidate button merges several records into one
 * (App\Support\Consolidate): group (lines with the same value become one), sum, avg, min, max,
 * count, concat, first or last. Null: first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->string('group_type', 20)->nullable()->after('expression_string');
        });
    }

    public function down(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->dropColumn('group_type');
        });
    }
};
