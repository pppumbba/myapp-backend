<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * roles.features: builder abilities beyond what a role may do with metas' records, e.g.
     * ["build", "studio"]: make metas and change their own, and build their own apps in the
     * studio (see Role::FEATURES and Builders).
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('features')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn('features'));
    }
};
