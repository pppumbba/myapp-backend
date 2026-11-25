<?php

use App\Models\Customer;
use App\Support\Customers;
use App\Support\CustomerSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'main';

    /** Each customer's users view lists its columns, so it gets avatar only when it is made again. */
    public function up(): void
    {
        if (! in_array(config('database.connections.customer.driver'), ['mysql', 'mariadb'], true)) {
            return;
        }

        Customer::all()->each(fn (Customer $customer) => CustomerSchema::run($customer, fn () => Customers::createUsersView($customer)));
    }

    public function down(): void
    {
        //
    }
};
