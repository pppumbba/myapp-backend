<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An app's cover picture, apart from its icon (image_path): the file's path under storage/app,
 * e.g. app-images/160-….png (AppImageController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->string('cover_path', 255)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};
