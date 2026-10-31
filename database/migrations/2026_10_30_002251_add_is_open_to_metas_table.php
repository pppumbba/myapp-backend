<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_open: every signed-in user may view the meta, without a role. Like is_public, but
     * behind a login and view only — e.g. the market app and its screens.
     */
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn('is_open');
        });
    }
};
