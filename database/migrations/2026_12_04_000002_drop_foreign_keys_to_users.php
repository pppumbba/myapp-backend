<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Users move to the main schema, so a customer's tables keep user ids without keys to them
     * (a schema must stay movable on its own). SQLite (tests) keeps its keys: one database there.
     */
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $keys = DB::select("SELECT DISTINCT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users' AND TABLE_NAME <> 'users'");
        foreach ($keys as $key) {
            DB::statement("ALTER TABLE `{$key->TABLE_NAME}` DROP FOREIGN KEY `{$key->CONSTRAINT_NAME}`");
        }
    }

    public function down(): void
    {
        // The keys are not put back: users may already live in another schema.
    }
};
