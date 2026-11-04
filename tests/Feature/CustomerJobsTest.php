<?php

namespace Tests\Feature;

use App\Jobs\Middleware\InCustomer;
use App\Jobs\SendNotificationEmail;
use App\Models\Customer;
use App\Support\CustomerSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_email_runs_in_its_customer(): void
    {
        [$a, $b] = [$this->customer('a'), $this->customer('b')];
        CustomerSchema::use($a);
        $job = new SendNotificationEmail([1]);
        $this->assertSame($a->id, $job->customerId);
        $this->assertContainsOnlyInstancesOf(InCustomer::class, $job->middleware());

        CustomerSchema::use($b);
        $ran = (new InCustomer)->handle($job, fn () => CustomerSchema::current()->id);

        $this->assertSame($a->id, $ran);
        $this->assertSame($b->id, CustomerSchema::current()->id);
    }

    public function test_files_go_to_the_customer_folder(): void
    {
        $first = $this->customer(config('customers.first_code'));
        $other = $this->customer('other');

        CustomerSchema::use($other);
        $this->assertStringContainsString('customers'.DIRECTORY_SEPARATOR.$other->id, Storage::disk('local')->path('x'));

        CustomerSchema::use($first);
        $this->assertSame(storage_path('app/private').DIRECTORY_SEPARATOR.'x', Storage::disk('local')->path('x'));
    }

    public function test_each_runs_a_command_for_every_customer(): void
    {
        $this->customer('a');
        $this->customer('b');
        $seen = [];
        Artisan::command('test:remember', function () use (&$seen) {
            $seen[] = CustomerSchema::current()->code;
        });

        Artisan::call('customers:each', ['name' => 'test:remember']);
        $this->assertSame(['test', 'a', 'b'], $seen);

        $seen = [];
        Artisan::call('customers:each', ['name' => 'test:remember', '--only' => 'b']);
        $this->assertSame(['b'], $seen);
    }

    private function customer(string $code): Customer
    {
        return Customer::create(['code' => $code, 'name' => $code, 'schema_name' => 'cust_'.$code]);
    }
}
