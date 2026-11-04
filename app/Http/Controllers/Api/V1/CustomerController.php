<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Customers;
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
