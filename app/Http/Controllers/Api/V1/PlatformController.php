<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** For the people who run the platform (users.is_platform_owner): every customer, read only. */
class PlatformController extends Controller
{
    /** GET /platform/customers */
    public function customers(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()->is_platform_owner, 403);
        $members = CustomerUser::selectRaw('customer_id, COUNT(*) AS people')->groupBy('customer_id')->pluck('people', 'customer_id');

        return response()->json(Customer::orderBy('name')->get()->map(fn (Customer $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'members' => (int) ($members[$c->id] ?? 0),
            'created_at' => $c->created_at,
        ])->all());
    }
}
