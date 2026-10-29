<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each user's appearance: light, dark or as their system is (null), and the theme meta they picked (null: the default). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme_mode', 10)->nullable()->after('email');
            $table->foreignId('theme_meta_id')->nullable()->after('theme_mode')->constrained('metas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('theme_meta_id');
            $table->dropColumn('theme_mode');
        });
    }
};
