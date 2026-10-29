<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** EAM demo: a location sits inside another (a room in a building on a site), shown as a tree. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eam_locations') && ! Schema::hasColumn('eam_locations', 'parent_id')) {
            Schema::table('eam_locations', function (Blueprint $table) {
                $table->foreignId('parent_id')->nullable()->after('kind')->constrained('eam_locations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('eam_locations', 'parent_id')) {
            Schema::table('eam_locations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_id');
            });
        }
    }
};
