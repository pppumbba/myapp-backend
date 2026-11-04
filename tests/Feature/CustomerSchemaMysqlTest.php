<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Support\Customers;
use App\Support\CustomerSchema;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Throwable;

/**
 * Real customer schemas on MySQL or MariaDB. Runs only on a database named *_customertest
 * (main and customer connections both on it, its main migrations run); skipped elsewhere.
 */
class CustomerSchemaMysqlTest extends TestCase
{
    /** @var list<User> */
    private array $users = [];

    /** @var list<string> customer schemas that were there before the test: never dropped */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! str_ends_with((string) DB::getDatabaseName(), '_customertest')) {
            $this->markTestSkipped('Needs a MySQL or MariaDB database named *_customertest.');
        }
        $this->before = $this->schemas();
    }

    protected function tearDown(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && str_ends_with((string) DB::getDatabaseName(), '_customertest')) {
            CustomerSchema::forget();
            // Only what this test made: the server may hold real customers (a converted local database).
            foreach (array_diff($this->schemas(), $this->before) as $schema) {
                DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
            }
            $ids = array_map(fn (User $u) => $u->id, $this->users);
            DB::connection('main')->table('customer_users')->whereIn('user_id', $ids)->delete();
            Customer::whereIn('created_by', $ids)->delete();
            User::whereIn('id', $ids)->delete();
        }
        parent::tearDown();
    }

    public function test_a_new_schema_has_core_tables_and_no_metas(): void
    {
        $customer = Customers::create($this->user(), 'Acme');

        CustomerSchema::run($customer, function () {
            $this->assertSame(0, DB::table('metas')->count());
            $this->assertGreaterThan(0, DB::table('meta_types')->count());
            $this->assertSame(1, DB::table('languages')->count());
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('eam_assets'));
            $this->assertSame(1, DB::table('roles')->where('code', 'admin')->count());
        });
    }

    public function test_a_failed_create_leaves_nothing(): void
    {
        $before = $this->schemas();
        config(['customers.core_file' => __FILE__.'.missing']);

        try {
            Customers::create($user = $this->user(), 'Broken');
            $this->fail('Expected the create to fail.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }

        $this->assertSame($before, $this->schemas());
        $this->assertFalse(Customer::where('created_by', $user->id)->exists());
        $this->assertFalse(DB::connection('main')->table('customer_users')->where('user_id', $user->id)->exists());
    }

    public function test_the_users_view_shows_only_members_and_no_password(): void
    {
        $a = Customers::create($owner = $this->user(), 'Acme');
        $b = Customers::create($other = $this->user(), 'Other');

        CustomerSchema::run($a, function () use ($owner) {
            $this->assertSame([$owner->id], DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->all());
            $this->assertNotContains('password', DB::getSchemaBuilder()->getColumnListing('users'));
        });
        CustomerSchema::run($b, fn () => $this->assertSame([$other->id], DB::table('users')->pluck('id')->map(fn ($id) => (int) $id)->all()));
    }

    public function test_with_role_works_across_the_two_schemas(): void
    {
        $a = Customers::create($owner = $this->user(), 'Acme');
        Customers::create($this->user(), 'Other');

        $admins = CustomerSchema::run($a, fn () => User::withRole(fn ($q) => $q->where('code', 'admin'))->pluck('id')->all());

        $this->assertSame([$owner->id], $admins);
    }

    private function user(): User
    {
        return $this->users[] = User::factory()->create();
    }

    /** @return list<string> */
    private function schemas(): array
    {
        return collect(DB::select('SHOW DATABASES'))->map(fn ($r) => array_values((array) $r)[0])
            ->filter(fn (string $name) => str_starts_with($name, config('customers.schema_prefix')))->sort()->values()->all();
    }
}
