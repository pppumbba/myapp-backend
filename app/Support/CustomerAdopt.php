<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Turns the database the app had before customers into the first customer: users, logins, cache
 * and queue tables are copied into the main schema with the same ids, everyone becomes a member,
 * every token works in it, and only after all copies succeed are those tables dropped from the
 * old schema (where a users view takes their place). MySQL and MariaDB only.
 */
class CustomerAdopt
{
    /** Main-schema tables made by the main migrations, not copied. */
    private const MADE_BY_MAIN = ['migrations', 'customers', 'customer_users', 'customer_invites', 'customer_domains'];

    /** @return array{tables: array<string, int>, users: int, tokens: int} */
    public static function plan(): array
    {
        $tables = [];
        foreach (self::tables() as $table) {
            $tables[$table] = DB::connection('customer')->table($table)->count();
        }

        return ['tables' => $tables, 'users' => $tables['users'] ?? 0, 'tokens' => $tables['personal_access_tokens'] ?? 0];
    }

    public static function run(string $name): Customer
    {
        $source = (string) config('database.connections.customer.database');
        $main = (string) config('database.connections.main.database');
        if ($source === $main) {
            throw new RuntimeException('The main schema must be its own database (DB_MAIN_DATABASE).');
        }
        $old = DB::connection('customer');
        if ($old->selectOne('SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$main, 'customers'])
            && $old->table("{$main}.customers")->exists()) {
            throw new RuntimeException('Already adopted.');
        }

        $tables = self::tables();
        $old->statement("CREATE DATABASE IF NOT EXISTS `{$main}`");
        foreach ($tables as $table) {
            if ($old->selectOne('SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$main, $table])) {
                throw new RuntimeException("The main schema already has the table {$table}.");
            }
        }
        DB::purge('main');

        foreach ($tables as $table) {
            $create = array_values((array) $old->selectOne("SHOW CREATE TABLE `{$table}`"))[1];
            // The main schema stands on its own: no keys into a customer's tables (users.theme_meta_id → metas).
            $create = preg_replace('/,\s*CONSTRAINT `[^`]+` FOREIGN KEY \([^)]*\) REFERENCES `[^`]+` \([^)]*\)[^,\n]*/', '', $create);
            DB::connection('main')->statement($create);
            $columns = '`'.implode('`, `', self::storedColumns($source, $table)).'`';
            $old->statement("INSERT INTO `{$main}`.`{$table}` ({$columns}) SELECT {$columns} FROM `{$source}`.`{$table}`");
            if (DB::connection('main')->table($table)->count() !== $old->table($table)->count()) {
                throw new RuntimeException("Copying {$table} lost rows.");
            }
        }
        Artisan::call('migrate', ['--database' => 'main', '--path' => 'database/migrations/main', '--force' => true]);

        $customer = Customer::create(['code' => config('customers.first_code'), 'name' => $name, 'schema_name' => $source]);
        $mainDb = DB::connection('main');
        $mainDb->statement('INSERT INTO customer_users (customer_id, user_id, theme_meta_id, joined_at) SELECT ?, id, theme_meta_id, created_at FROM users', [$customer->id]);
        $mainDb->table('personal_access_tokens')->update(['customer_id' => $customer->id]);

        $old->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $table) {
            $old->statement("DROP TABLE `{$source}`.`{$table}`");
        }
        $old->statement('SET FOREIGN_KEY_CHECKS = 1');
        CustomerSchema::run($customer, fn () => Customers::createUsersView($customer));

        return $customer;
    }

    /** @return list<string> the main tables the old schema has */
    private static function tables(): array
    {
        $wanted = array_values(array_diff(config('customers.main_tables'), self::MADE_BY_MAIN));
        $have = collect(DB::connection('customer')->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn (object $row) => array_values((array) $row)[0])->all();

        return array_values(array_intersect($wanted, $have));
    }

    /** @return list<string> */
    private static function storedColumns(string $schema, string $table): array
    {
        return collect(DB::connection('customer')->select('SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$schema, $table]))
            ->reject(fn (object $c) => str_contains(strtoupper((string) $c->EXTRA), 'GENERATED'))
            ->pluck('COLUMN_NAME')->all();
    }
}
