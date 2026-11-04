<?php

namespace App\Jobs\Middleware;

use App\Models\Customer;
use App\Support\CustomerSchema;
use Closure;

/** Runs a queued job inside the customer it was queued in (the job's customerId). */
class InCustomer
{
    public function handle(object $job, Closure $next): mixed
    {
        $customer = isset($job->customerId) ? Customer::find($job->customerId) : null;

        return $customer ? CustomerSchema::run($customer, fn () => $next($job)) : $next($job);
    }
}
