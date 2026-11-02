<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database a patch needs, carried in its file, so installing it is all it takes: no deploy.
 *
 * Releasing reads what the patch's metas use here: the tables they name or query, the tables
 * those link to and their triggers touch, those tables' triggers, the roles their access names,
 * and the rows of the tables picked for it (data_tables). It also reads the tables metas are kept
 * in, as a meta can use a column added to them lately. Installing adds what the database doesn't
 * have yet: tables, columns, indexes, foreign keys, triggers and roles, and a changed trigger is
 * replaced. Nothing is dropped or altered otherwise, and rows only go into an empty table. It's
 * safe to run twice.
 *
 * Migrations that did the same work are marked as run when the patch installs, so a later
 * deploy doesn't stop on a table that is already there.
 *
 * MySQL and MariaDB only: elsewhere a release carries nothing.
 */
class PatchSchema
{
    /** Tables whose rows never ship: they hold people, logins and the app's own metas. */
    private const PRIVATE_TABLES = ['/^meta/', '/^users$/', '/^roles?$/', '/^role_/', '/^user_/', '/token/', '/^sessions$/', '/^cache/', '/^jobs$/', '/^job_/', '/^failed_jobs$/', '/^migrations$/', '/^patch/', '/^contents?$/', '/^content_/', '/^chat/', '/^social_/', '/^record_log$/', '/^wfm_/', '/^widgets$/'];

    private const MAX_ROWS = 5000;

    public static function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * The tables a package's metas need, with what a release would carry for each.
     *
     * @return list<array{table: string, rows: int, triggers: int, can_ship_rows: bool}>
     */
    public static function overview(array $package): array
    {
        if (! self::supported()) {
            return [];
        }

        return array_map(fn (string $table) => [
            'table' => $table,
            'rows' => DB::table($table)->count(),
            'triggers' => count(self::triggersOn($table)),
            'can_ship_rows' => self::canShipRows($table),
        ], self::tablesOf($package));
    }

    /**
     * What goes in a patch's file.
     *
     * @param  list<string>  $dataTables  the tables whose rows go too
     * @return array{tables?: array<string, array{create: string, rows?: list<array<string, mixed>>}>, triggers?: list<array{name: string, table: string, create: string, body: string}>, roles?: list<array{code: string, name: string, description: ?string}>, migrations?: array<string, list<string>>}
     */
    public static function capture(array $package, array $dataTables = []): array
    {
        if (! self::supported()) {
            return [];
        }
        $names = self::tablesOf($package, true);
        $tables = [];
        $triggers = [];
        foreach ($names as $table) {
            $tables[$table] = ['create' => self::createStatement($table)];
            if (in_array($table, $dataTables, true) && self::canShipRows($table)) {
                $tables[$table]['rows'] = self::rowsOf($table);
            }
            foreach (self::triggersOn($table) as $trigger) {
                $triggers[] = ['name' => $trigger->name, 'table' => $table, 'create' => self::triggerStatement($trigger->name), 'body' => $trigger->body];
            }
        }

        $roles = DB::table('roles')->whereIn('code', array_values($package['refs']['roles'] ?? []))->get(['code', 'name', 'description'])->map(fn ($role) => (array) $role)->all();

        return ['tables' => $tables, 'triggers' => $triggers, 'roles' => $roles, 'migrations' => self::migrationsOf($names)];
    }

    /**
     * What installing would change here.
     *
     * @return array{tables: list<string>, columns: list<string>, keys: list<string>, triggers: list<string>, replaced_triggers: list<string>, roles: list<string>, rows: array<string, int>, rows_kept: list<string>}
     */
    public static function plan(array $schema): array
    {
        $plan = ['tables' => [], 'columns' => [], 'keys' => [], 'triggers' => [], 'replaced_triggers' => [], 'roles' => [], 'rows' => [], 'rows_kept' => []];

        foreach ($schema['tables'] ?? [] as $table => $definition) {
            self::check($table, $definition);
            $rows = count($definition['rows'] ?? []);
            if (! Schema::hasTable($table)) {
                $plan['tables'][] = $table;
                if ($rows) {
                    $plan['rows'][$table] = $rows;
                }

                continue;
            }
            $missing = self::missing($table, $definition['create']);
            array_push($plan['columns'], ...array_map(fn ($c) => "{$table}.{$c}", array_keys($missing['columns'])));
            array_push($plan['keys'], ...array_map(fn ($k) => "{$table}.{$k}", array_keys($missing['keys'])));
            if ($rows) {
                if (DB::table($table)->exists()) {
                    $plan['rows_kept'][] = $table;
                } else {
                    $plan['rows'][$table] = $rows;
                }
            }
        }

        foreach ($schema['triggers'] ?? [] as $trigger) {
            $here = self::trigger($trigger['name']);
            if ($here === null) {
                $plan['triggers'][] = $trigger['name'];
            } elseif (self::squeeze($here) !== self::squeeze($trigger['body'])) {
                $plan['replaced_triggers'][] = $trigger['name'];
            }
        }

        $plan['roles'] = array_values(array_diff(array_column($schema['roles'] ?? [], 'code'), DB::table('roles')->pluck('code')->all()));

        return $plan;
    }

    /**
     * Adds what plan() found. A failure stops the install and says what failed; what was already
     * added stays, and installing again carries on from there.
     *
     * @return array{tables: list<string>, columns: list<string>, keys: list<string>, triggers: list<string>, replaced_triggers: list<string>, previous_triggers: array<string, string>, rows: array<string, int>, rows_kept: list<string>, migrations: list<string>, warnings: list<string>}
     */
    public static function apply(array $schema): array
    {
        abort_unless(self::supported(), 422, __('This patch brings database changes, which only MySQL and MariaDB can take.'));
        $plan = self::plan($schema);
        $previous = [];
        $tables = $schema['tables'] ?? [];

        // Off for this step only: tables link to each other in any order, and rows arrive whole.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($plan['tables'] as $table) {
                self::run("The table {$table} could not be created", fn () => DB::statement($tables[$table]['create']));
            }
            foreach ($tables as $table => $definition) {
                if (in_array($table, $plan['tables'], true)) {
                    continue;
                }
                $missing = self::missing($table, $definition['create']);
                foreach ($missing['columns'] as $column => $line) {
                    self::run("The column {$table}.{$column} could not be added", fn () => DB::statement("ALTER TABLE `{$table}` ADD COLUMN {$line}"));
                }
                foreach ($missing['keys'] as $key => $line) {
                    self::run("The key {$table}.{$key} could not be added", fn () => DB::statement("ALTER TABLE `{$table}` ADD {$line}"));
                }
            }
            foreach (array_keys($plan['rows']) as $table) {
                $rows = $tables[$table]['rows'];
                self::run("The rows of {$table} could not be added", function () use ($table, $rows) {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                });
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        foreach ($schema['triggers'] ?? [] as $trigger) {
            $name = $trigger['name'];
            $replaced = in_array($name, $plan['replaced_triggers'], true);
            if (! $replaced && ! in_array($name, $plan['triggers'], true)) {
                continue;
            }
            self::run("The trigger {$name} could not be created", function () use ($trigger, $name, $replaced, &$previous) {
                if ($replaced) {
                    $previous[$name] = self::triggerStatement($name);
                    DB::unprepared("DROP TRIGGER `{$name}`");
                }
                DB::unprepared($trigger['create']);
            });
        }

        foreach ($schema['roles'] ?? [] as $role) {
            if (in_array($role['code'], $plan['roles'], true)) {
                DB::table('roles')->insert([...array_intersect_key($role, array_flip(['code', 'name', 'description'])), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $plan + [
            'previous_triggers' => $previous,
            'migrations' => self::markMigrations($schema['migrations'] ?? []),
            'warnings' => self::orphans(array_keys($plan['rows'])),
        ];
    }

    /**
     * Takes back what apply() added: its triggers, and its tables if nothing was added to them
     * since. Columns and keys stay, as taking them off could lose what's saved in them, and so do
     * roles, which people may have been given by now.
     *
     * @return list<string> what stayed
     */
    public static function undo(array $done): array
    {
        $warnings = [];
        foreach ($done['replaced_triggers'] ?? [] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            DB::unprepared($done['previous_triggers'][$name]);
        }
        foreach ($done['triggers'] ?? [] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }

        $dropped = [];
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($done['tables'] ?? [] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                if (DB::table($table)->count() > ($done['rows'][$table] ?? 0)) {
                    $warnings[] = "The table {$table} stays: records were added to it after the install.";

                    continue;
                }
                DB::statement("DROP TABLE `{$table}`");
                $dropped[] = $table;
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        // A kept table's migration stays marked: running it would stop on the table.
        $marked = $done['migrations'] ?? [];
        if ($marked && count($dropped) === count($done['tables'] ?? [])) {
            DB::table('migrations')->whereIn('migration', $marked)->delete();
        }
        if ($done['columns'] ?? []) {
            $warnings[] = 'The columns it added stay: '.implode(', ', $done['columns']).'.';
        }

        return $warnings;
    }

    /**
     * The tables the metas name or query, then what those depend on, until nothing new turns up.
     * With $kept, the tables metas are kept in too.
     *
     * @return list<string>
     */
    private static function tablesOf(array $package, bool $kept = false): array
    {
        $known = array_flip(array_column(Schema::getTables(DB::getDatabaseName()), 'name'));
        $texts = [];
        array_walk_recursive($package['metas'], function ($value, $key) use (&$texts) {
            if (is_string($value)) {
                $texts[] = $key === 'table_name' ? "from {$value}" : $value;
            }
        });

        $queue = self::tablesIn(implode("\n", $texts), $known);
        if ($kept) {
            array_push($queue, 'metas', ...array_column(MetaVersions::parts(), 'table'));
        }
        $found = [];
        while ($queue) {
            $table = array_shift($queue);
            if (isset($found[$table]) || $table === 'migrations') {
                continue;
            }
            $found[$table] = true;
            array_push($queue, ...array_column(Schema::getForeignKeys($table), 'foreign_table'));
            foreach (self::triggersOn($table) as $trigger) {
                array_push($queue, ...self::tablesIn($trigger->body, $known));
            }
        }
        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, int>  $known
     * @return list<string>
     */
    private static function tablesIn(string $text, array $known): array
    {
        preg_match_all('/\b(?:from|join|into|update)\s+`?(\w+)`?/i', $text, $matches);

        return array_values(array_unique(array_filter($matches[1], fn ($name) => isset($known[$name]))));
    }

    private static function canShipRows(string $table): bool
    {
        foreach (self::PRIVATE_TABLES as $pattern) {
            if (preg_match($pattern, $table)) {
                return false;
            }
        }

        return true;
    }

    private static function createStatement(string $table): string
    {
        $row = (array) DB::selectOne("SHOW CREATE TABLE `{$table}`");

        return preg_replace('/ AUTO_INCREMENT=\d+/', '', $row['Create Table']);
    }

    /** @return list<array<string, mixed>> the rows, without generated columns: the database fills those */
    private static function rowsOf(string $table): array
    {
        abort_if(DB::table($table)->count() > self::MAX_ROWS, 422, __('The table :table has more than :max rows, too many for a patch.', ['table' => $table, 'max' => self::MAX_ROWS]));
        $columns = array_column(array_filter(Schema::getColumns($table), fn ($c) => $c['generation'] === null), 'name');

        return DB::table($table)->get($columns)->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<object{name: string, body: string}> */
    private static function triggersOn(string $table): array
    {
        return DB::select('SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY ACTION_ORDER', [$table]);
    }

    private static function trigger(string $name): ?string
    {
        return DB::selectOne('SELECT ACTION_STATEMENT AS body FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name])?->body;
    }

    /** Without its DEFINER: that user may not exist there, and the install's own user owns it. */
    private static function triggerStatement(string $name): string
    {
        $row = (array) DB::selectOne("SHOW CREATE TRIGGER `{$name}`");

        return preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', $row['SQL Original Statement']);
    }

    private static function squeeze(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql));
    }

    /** A file is whatever was uploaded, so it must at least be what it says. */
    private static function check(mixed $table, mixed $definition): void
    {
        $create = is_array($definition) ? ($definition['create'] ?? null) : null;
        abort_unless(is_string($table) && preg_match('/^\w+$/', $table) && is_string($create) && str_starts_with($create, "CREATE TABLE `{$table}` ("), 422, __('This patch file is damaged.'));
    }

    /**
     * What a table here lacks compared with its definition, as the lines of SHOW CREATE TABLE.
     *
     * @return array{columns: array<string, string>, keys: array<string, string>}
     */
    private static function missing(string $table, string $create): array
    {
        $columns = [];
        $keys = [];
        foreach (explode("\n", $create) as $line) {
            $line = rtrim(trim($line), ',');
            if (preg_match('/^`(\w+)`\s/', $line, $m)) {
                $columns[$m[1]] = $line;
            } elseif (preg_match('/^(?:(?:UNIQUE|FULLTEXT|SPATIAL) )?KEY `(\w+)`/', $line, $m) || preg_match('/^CONSTRAINT `(\w+)` FOREIGN KEY/', $line, $m)) {
                $keys[$m[1]] = $line;
            }
        }
        $here = [...array_column(Schema::getIndexes($table), 'name'), ...array_column(Schema::getForeignKeys($table), 'name')];

        return [
            'columns' => array_diff_key($columns, array_flip(Schema::getColumnListing($table))),
            'keys' => array_diff_key($keys, array_flip($here)),
        ];
    }

    /**
     * The migrations that have run here and only touch shipped tables, with those tables. Their
     * work is in the shipped definitions, so they needn't run where the patch installs.
     *
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    private static function migrationsOf(array $names): array
    {
        $found = [];
        foreach (DB::table('migrations')->pluck('migration') as $migration) {
            $path = database_path("migrations/{$migration}.php");
            if (! is_file($path)) {
                continue;
            }
            preg_match_all('/Schema::(?:create|table)\(\s*\'(\w+)\'/', file_get_contents($path), $matches);
            $touched = array_values(array_unique($matches[1]));
            if ($touched && ! array_diff($touched, $names)) {
                $found[$migration] = $touched;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, list<string>>  $migrations
     * @return list<string> the ones marked as run by this install
     */
    private static function markMigrations(array $migrations): array
    {
        $done = DB::table('migrations')->pluck('migration')->all();
        $batch = (int) DB::table('migrations')->max('batch') + 1;
        $marked = [];
        foreach ($migrations as $migration => $tables) {
            if (! in_array($migration, $done, true) && ! array_filter($tables, fn ($table) => ! Schema::hasTable($table))) {
                DB::table('migrations')->insert(['migration' => $migration, 'batch' => $batch]);
                $marked[] = $migration;
            }
        }

        return $marked;
    }

    /**
     * Shipped rows keep their ids, so one can point at a record that has a different id here, or
     * none.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function orphans(array $tables): array
    {
        $warnings = [];
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $key) {
                $column = $key['columns'][0];
                $missing = DB::table($table)->whereNotNull($column)
                    ->whereNotIn($column, DB::table($key['foreign_table'])->select($key['foreign_columns'][0]))->count();
                if ($missing) {
                    $warnings[] = "{$missing} rows of {$table} point at {$key['foreign_table']} records that aren't here ({$column}).";
                }
            }
        }

        return $warnings;
    }

    private static function run(string $what, callable $work): void
    {
        try {
            $work();
        } catch (QueryException $e) {
            abort(422, "{$what}: {$e->getPrevious()?->getMessage()}");
        }
    }
}
