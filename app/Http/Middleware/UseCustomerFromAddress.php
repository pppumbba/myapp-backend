<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\CustomerDomain;
use App\Support\CustomerSchema;
use App\Support\Sites;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages guests open (public metas, the portal, websites) have no token to tell the customer, so
 * the address does: /c/{code}/… names it, a website's own domain points at it, and anything else
 * is the first customer's (links from before customers existed). Signed-in people keep the
 * customer of their token (UseCustomer).
 */
class UseCustomerFromAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $code = $route?->parameter('customer_code');
        $route?->forgetParameter('customer_code');

        if ($request->user('sanctum')) {
            return $next($request);
        }

        if ($code !== null) {
            $customer = Customer::where('code', $code)->first();
            abort_unless($customer, 404);
        } else {
            $host = Sites::host((string) ($request->query('host') ?: $request->getHost()));
            $customerId = CustomerDomain::where('domain', $host)->value('customer_id');
            $customer = $customerId ? Customer::find($customerId) : Customer::where('code', config('customers.first_code'))->first();
        }

        if ($customer) {
            CustomerSchema::use($customer);
        }

        return $next($request);
    }
}
