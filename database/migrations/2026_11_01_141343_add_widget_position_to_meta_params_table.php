<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * meta_params.widget_position: where a datagrid's column sits when the grid shows its
     * records as widgets (cards, inline cards, tiles): image, title, subtitle, badge, body or
     * footer. Empty: the grid decides.
     */
    public function up(): void
    {
        Schema::table('meta_params', function (Blueprint $table) {
            $table->string('widget_position', 10)->nullable()->after('render_type');
        });
    }

    public function down(): void
    {
        Schema::table('meta_params', fn (Blueprint $table) => $table->dropColumn('widget_position'));
    }
};
