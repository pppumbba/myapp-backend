<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Customers;
use App\Support\CustomerSchema;
use App\Support\SessionValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A person's customers: making one, and the login token that works in it. */
class CustomerController extends Controller
{
    /** POST /customers {name} — a new customer with its own schema; the person becomes its admin. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $customer = Customers::create($request->user(), trim($data['name']));

        return $this->opened($request, $customer, 201);
    }

    /** POST /customers/join {code} — joins the customer of an invite code with its roles. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        return $this->opened($request, Customers::join($request->user(), $data['code']));
    }

    /** POST /customers/{customer}/use — work in another of the person's customers. */
    public function use(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->isIn($customer), 403, 'You are not in this customer.');
        CustomerSchema::run($customer, fn () => SessionValues::refresh($request->user()));

        return $this->opened($request, $customer);
    }

    /** GET /me/customers */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(Customers::listFor($request->user()));
    }

    /** DELETE /me/customers/{customer} — leave it (not as its last admin). */
    public function leave(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->isIn($customer), 404);
        Customers::remove($customer, $request->user());

        return response()->json(['customers' => Customers::listFor($request->user())]);
    }

    /** DELETE /customer/members/{user} — an admin takes someone out of the current customer. */
    public function removeMember(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, "Only the customer's admins can do this.");
        $customer = CustomerSchema::current();
        abort_unless($user->isIn($customer), 404);
        Customers::remove($customer, $user);

        return response()->json(['message' => __('Removed.')]);
    }

    /** The answer when a customer is opened: it, and a new token for it (the old one is gone). */
    private function opened(Request $request, Customer $customer, int $status = 200): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $old = $user->currentAccessToken();
        $token = Customers::bindToken($user, $customer);
        if ($old instanceof PersonalAccessToken && $old->getKey()) {
            $old->delete();
        }

        return response()->json(['customer' => $customer->only(['id', 'code', 'name']), 'token' => $token], $status);
    }
}
