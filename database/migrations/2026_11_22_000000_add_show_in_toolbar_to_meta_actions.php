<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets a datagrid button live only on each row (show_in_row) and not in the toolbar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_actions', function (Blueprint $table) {
            $table->boolean('show_in_toolbar')->default(true)->after('needs_row');
        });
    }

    public function down(): void
    {
        Schema::table('meta_actions', function (Blueprint $table) {
            $table->dropColumn('show_in_toolbar');
        });
    }
};
