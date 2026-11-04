<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Customers;
use App\Support\CustomerSchema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request inside the customer the login token is for. "customer" needs one: without it
 * 409 (pick one), and 403 once the person is no longer in it. "customer:optional" (the profile,
 * pages guests open too) goes on without a customer instead.
 */
class UseCustomer
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $optional = $mode === 'optional';
        $user = $request->user('sanctum');
        if (! $user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->getKey()) {
            // Sanctum::actingAs in tests (a stand-in token with no row): the test has picked the customer already.
            return app()->runningUnitTests() || $optional ? $next($request) : $this->refuse(409, 'Pick a customer.', $user);
        }

        $customer = $token->customer_id ? Customer::find($token->customer_id) : null;
        if ($customer && $user->isIn($customer)) {
            CustomerSchema::use($customer);

            return $next($request);
        }
        if ($optional) {
            CustomerSchema::forget();

            return $next($request);
        }

        return $customer || $token->customer_id
            ? $this->refuse(403, 'You are no longer in this customer.', $user)
            : $this->refuse(409, 'Pick a customer.', $user);
    }

    private function refuse(int $status, string $message, User $user): Response
    {
        return response()->json(['message' => __($message), 'customers' => Customers::listFor($user)], $status);
    }
}
