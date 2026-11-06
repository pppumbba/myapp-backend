<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a process flow step's node sits on the builder's canvas. Null: the canvas picks a
 * spot. The main form's own node keeps its spot in its json_config.process_main_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_process_workflow', function (Blueprint $table) {
            $table->integer('pos_x')->nullable()->after('order_number');
            $table->integer('pos_y')->nullable()->after('pos_x');
        });
    }

    public function down(): void
    {
        Schema::table('meta_process_workflow', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y']);
        });
    }
};
