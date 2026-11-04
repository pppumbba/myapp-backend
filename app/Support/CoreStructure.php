<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The structure a new customer schema starts from: the core tables of the current schema (not
 * the main ones, not app or demo tables), their triggers, the built-in lookup rows and every
 * migration marked as run. Kept in database/schema/customer-core.sql, because migrate can't build
 * an empty database. Statements are separated by STATEMENT_END lines. MySQL and MariaDB only.
 */
class CoreStructure
{
    public const FILE = 'schema/customer-core.sql';

    public const STATEMENT_END = '-- @@';

    public static function isCoreTable(string $table): bool
    {
        return ! in_array($table, [...config('customers.main_tables'), ...config('customers.app_tables')], true)
            && ! Str::startsWith($table, config('customers.app_prefixes'));
    }

    public static function dump(): string
    {
        $tables = collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn (object $row) => array_values((array) $row)[0])
            ->filter(fn (string $table) => self::isCoreTable($table))
            ->sort()->values();

        $statements = ['SET FOREIGN_KEY_CHECKS = 0'];
        foreach ($tables as $table) {
            $create = array_values((array) DB::selectOne("SHOW CREATE TABLE `{$table}`"))[1];
            $create = preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
            // Users live in the main schema: no key may point at them.
            $create = preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `users` \([^)]*\)[^,\n]*/', '', $create);
            $statements[] = $create;
        }

        foreach (DB::select('SHOW TRIGGERS') as $trigger) {
            if ($tables->contains($trigger->Table)) {
                $create = (array) DB::selectOne("SHOW CREATE TRIGGER `{$trigger->Trigger}`");
                $statements[] = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $create['SQL Original Statement']);
            }
        }

        foreach (config('customers.lookup_tables') as $table) {
            $rows = DB::table($table)->when($table === 'languages', fn ($q) => $q->where('is_default', true))
                // A widget made from a template meta belongs to that customer, not to every new one.
                ->when($table === 'widgets', fn ($q) => $q->whereNull('template_meta_id'))->get();
            $columns = self::storedColumns($table);
            foreach ($rows as $row) {
                $values = array_map(fn (string $c) => $row->{$c} === null ? 'NULL' : DB::getPdo()->quote((string) $row->{$c}), $columns);
                $statements[] = "INSERT INTO `{$table}` (`".implode('`, `', $columns).'`) VALUES ('.implode(', ', $values).')';
            }
        }

        foreach (self::migrationNames() as $name) {
            $statements[] = "INSERT INTO `migrations` (`migration`, `batch`) VALUES ('{$name}', 1)";
        }
        $statements[] = 'SET FOREIGN_KEY_CHECKS = 1';

        return implode("\n".self::STATEMENT_END."\n", $statements)."\n";
    }

    /** Builds the core structure in the schema the "customer" connection points at now. */
    public static function load(string $sql): void
    {
        $connection = DB::connection('customer');
        $connection->statement('CREATE TABLE IF NOT EXISTS `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL)');
        foreach (explode("\n".self::STATEMENT_END."\n", trim($sql)) as $statement) {
            if (trim($statement) !== '') {
                $connection->unprepared($statement);
            }
        }
    }

    /** @return list<string> the columns that take values (generated ones don't) */
    private static function storedColumns(string $table): array
    {
        return collect(DB::select('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]))
            ->reject(fn (object $c) => str_contains(strtoupper((string) $c->EXTRA), 'GENERATED'))
            ->pluck('COLUMN_NAME')->all();
    }

    /** @return list<string> */
    private static function migrationNames(): array
    {
        return collect(glob(database_path('migrations/*.php')))->map(fn (string $f) => basename($f, '.php'))->sort()->values()->all();
    }
}
