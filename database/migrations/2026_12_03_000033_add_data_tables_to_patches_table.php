<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** patches.data_tables: the tables whose rows the patch's file carries (see PatchSchema). */
    public function up(): void
    {
        Schema::table('patches', function (Blueprint $table) {
            $table->json('data_tables')->nullable()->after('with_used');
        });
    }

    public function down(): void
    {
        Schema::table('patches', fn (Blueprint $table) => $table->dropColumn('data_tables'));
    }
};
