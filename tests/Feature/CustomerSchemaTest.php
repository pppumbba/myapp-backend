<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Meta;
use App\Models\PersonalAccessToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\CustomerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class CustomerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_models_use_the_main_connection(): void
    {
        $this->assertSame('main', (new User)->getConnectionName());
        $this->assertSame('main', (new SocialAccount)->getConnectionName());
        $this->assertSame('main', (new PersonalAccessToken)->getConnectionName());
        $this->assertSame('main', (new Customer)->getConnectionName());
        $this->assertNull((new Meta)->getConnectionName());
        $this->assertSame('customer', config('database.default'));
    }

    public function test_run_switches_back_afterwards(): void
    {
        [$a, $b] = [$this->customer('a'), $this->customer('b')];
        CustomerSchema::use($a);

        $this->assertSame($b->id, CustomerSchema::run($b, fn () => CustomerSchema::current()->id));
        $this->assertSame($a->id, CustomerSchema::current()->id);

        try {
            CustomerSchema::run($b, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        $this->assertSame($a->id, CustomerSchema::current()->id);
    }

    public function test_the_cache_is_kept_apart_per_customer(): void
    {
        [$a, $b] = [$this->customer('a'), $this->customer('b')];

        CustomerSchema::use($a);
        Cache::store('database')->put('k', 'a');
        CustomerSchema::use($b);
        $this->assertNull(Cache::store('database')->get('k'));
        CustomerSchema::use($a);
        $this->assertSame('a', Cache::store('database')->get('k'));
    }

    private function customer(string $code): Customer
    {
        return Customer::create(['code' => $code, 'name' => strtoupper($code), 'schema_name' => 'cust_'.$code]);
    }
}
