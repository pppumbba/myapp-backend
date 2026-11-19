<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A param's expression_string holds a calculated field's formula (App\Support\Formula) or a
 * query column's SQL (QueryDesign); both outgrow 255 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->text('expression_string')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->string('expression_string')->nullable()->change();
        });
    }
};
