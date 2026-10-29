<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * metas.table_name is read on every record save (Notifier finds the metas of the saved table)
 * and when a record's files are listed, so it becomes an indexed string instead of text.
 * meta_versions(meta_id, hash): the check for an unchanged snapshot before each auto version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metas', function (Blueprint $table) {
            $table->string('table_name', 191)->nullable()->change();
            $table->index('table_name');
        });
        Schema::table('meta_versions', fn (Blueprint $table) => $table->index(['meta_id', 'hash']));
    }

    public function down(): void
    {
        Schema::table('meta_versions', fn (Blueprint $table) => $table->dropIndex(['meta_id', 'hash']));
        Schema::table('metas', function (Blueprint $table) {
            $table->dropIndex(['table_name']);
            $table->text('table_name')->nullable()->change();
        });
    }
};
