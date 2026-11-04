<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerInvite;
use App\Models\Role;
use App\Models\User;
use App\Support\Customers;
use App\Support\CustomerSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Invite codes of the current customer, for its admins: whoever joins with one gets its roles. */
class CustomerInviteController extends Controller
{
    /** GET /customer/invites */
    public function index(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $invites = CustomerInvite::where('customer_id', CustomerSchema::current()->id)->latest('id')->get();

        return response()->json($invites->map(fn (CustomerInvite $invite) => $this->shape($invite))->all());
    }

    /** POST /customer/invites {role_ids[], expires_at?, max_uses?} */
    public function store(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ]);

        $invite = CustomerInvite::create([
            'customer_id' => CustomerSchema::current()->id,
            'code' => Customers::newCode(),
            'role_ids' => array_values(array_unique(array_map('intval', $data['role_ids']))),
            'created_by' => $request->user()->id,
            'expires_at' => $data['expires_at'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
        ]);

        return response()->json($this->shape($invite->fresh()), 201);
    }

    /** POST /customer/invites/{invite}/revoke */
    public function revoke(Request $request, int $invite): JsonResponse
    {
        $this->requireAdmin($request);
        $found = CustomerInvite::where('customer_id', CustomerSchema::current()->id)->findOrFail($invite);
        $found->update(['revoked_at' => $found->revoked_at ?? now()]);

        return response()->json($this->shape($found));
    }

    private function requireAdmin(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, "Only the customer's admins can do this.");
    }

    /** @return array<string, mixed> */
    private function shape(CustomerInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'code' => $invite->code,
            'roles' => Role::whereIn('id', $invite->role_ids ?? [])->get(['id', 'name'])->toArray(),
            'expires_at' => $invite->expires_at,
            'max_uses' => $invite->max_uses,
            'uses' => $invite->uses,
            'revoked_at' => $invite->revoked_at,
            'created_by_name' => User::whereKey($invite->created_by)->value('name'),
        ];
    }
}
