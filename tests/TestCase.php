<?php

namespace Tests;

use App\Models\Customer;
use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\User;
use App\Support\CustomerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** The customer every test works in, unless it switches. */
    protected ?Customer $testCustomer = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->testCustomer = Customer::create(['code' => 'test', 'name' => 'Test', 'schema_name' => 'test']);
            CustomerSchema::use($this->testCustomer);
        }
    }

    protected function tearDown(): void
    {
        CustomerSchema::forget();

        parent::tearDown();
    }

    /** On SQLite (tests) the main and customer connections are one in-memory database: "main" is the same connection. */
    public function createApplication()
    {
        $app = parent::createApplication();
        if (config('database.connections.customer.driver') === 'sqlite') {
            $app['db']->extend('main', fn () => $app['db']->connection('customer'));
        }

        return $app;
    }

    /**
     * Gives the user a new role that may do these things with these metas: a meta with no
     * permission rows is for admins only.
     *
     * @param  int[]  $metaIds
     * @param  string[]  $abilities
     */
    protected function allow(User $user, array $metaIds, array $abilities = ['view']): User
    {
        $role = Role::create(['code' => 'role_'.Str::random(8), 'name' => 'Test role']);
        foreach ($metaIds as $metaId) {
            MetaPermission::create(['meta_id' => $metaId, 'role_id' => $role->id]
                + array_fill_keys(array_map(fn (string $a) => "can_{$a}", $abilities), true));
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
