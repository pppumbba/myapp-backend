<?php

namespace Tests\Feature;

use App\Support\CustomerAdopt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Adopting a database from before customers: runs only next to a MySQL or MariaDB database named
 * *_customertest, on two scratch schemas it makes and drops (a small "old" app and its main).
 */
class CustomerAdoptTest extends TestCase
{
    private const SOURCE = 'adopt_src_customertest';

    private const MAIN = 'adopt_main_customertest';

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! str_ends_with((string) DB::getDatabaseName(), '_customertest')) {
            $this->markTestSkipped('Needs a MySQL or MariaDB database named *_customertest.');
        }
        $this->dropScratch();
        DB::statement('CREATE DATABASE `'.self::SOURCE.'`');
        $from = DB::getDatabaseName();
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['users', 'personal_access_tokens', 'social_accounts', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'metas', 'roles', 'user_roles'] as $table) {
            DB::statement('CREATE TABLE `'.self::SOURCE."`.`{$table}` LIKE `{$from}`.`{$table}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        DB::table(self::SOURCE.'.users')->insert([
            ['id' => 11, 'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x', 'theme_meta_id' => 5],
            ['id' => 12, 'name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'x', 'theme_meta_id' => null],
        ]);
        DB::table(self::SOURCE.'.personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 11, 'name' => 'api', 'token' => hash('sha256', 'tok'), 'abilities' => '["*"]']);

        config(['database.connections.customer.database' => self::SOURCE, 'database.connections.main.database' => self::MAIN]);
        DB::purge('customer');
        DB::purge('main');
    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->dropScratch();
        }
        parent::tearDown();
    }

    public function test_adopting_moves_users_and_keeps_ids(): void
    {
        $customer = CustomerAdopt::run('My company');

        $this->assertSame(config('customers.first_code'), $customer->code);
        $this->assertSame(self::SOURCE, $customer->schema_name);
        $this->assertSame([11, 12], DB::connection('main')->table('users')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($customer->id, (int) DB::connection('main')->table('personal_access_tokens')->value('customer_id'));
        $this->assertSame(5, (int) DB::connection('main')->table('customer_users')->where('user_id', 11)->value('theme_meta_id'));
        $this->assertSame(2, DB::connection('main')->table('customer_users')->where('customer_id', $customer->id)->count());

        $kind = DB::selectOne('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [self::SOURCE, 'users'])->TABLE_TYPE;
        $this->assertSame('VIEW', $kind);
        $this->assertNull(DB::selectOne('SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [self::SOURCE, 'personal_access_tokens']));
        $this->assertSame(['Ann', 'Bo'], DB::table(self::SOURCE.'.users')->orderBy('id')->pluck('name')->all());
    }

    public function test_adopting_twice_is_refused(): void
    {
        CustomerAdopt::run('My company');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Already adopted.');
        CustomerAdopt::run('My company');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $plan = CustomerAdopt::plan();

        $this->assertSame(2, $plan['users']);
        $this->assertSame(1, $plan['tokens']);
        $this->assertSame(2, $plan['tables']['users']);
        $this->assertSame([], DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [self::MAIN]));
        $this->assertTrue(DB::connection('customer')->getSchemaBuilder()->hasTable('personal_access_tokens'));
    }

    private function dropScratch(): void
    {
        DB::statement('DROP DATABASE IF EXISTS `'.self::SOURCE.'`');
        DB::statement('DROP DATABASE IF EXISTS `'.self::MAIN.'`');
    }
}
