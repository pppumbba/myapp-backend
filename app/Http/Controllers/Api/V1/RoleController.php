<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Access;
use App\Support\RoleLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Roles, managed by admins. The admin role itself can be renamed but keeps its code and stays. */
class RoleController extends Controller
{
    /** GET /roles, with how many users have each. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Role::withCount('users')->orderBy('name')->get());
    }

    /** POST /roles */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Role::create($this->validated($request))->loadCount('users'), 201);
    }

    /** PUT /roles/{role} */
    public function update(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $this->validated($request, $role);
        abort_if($role->code === Role::ADMIN && isset($data['code']) && $data['code'] !== Role::ADMIN, 422, 'The admin role keeps its code.');

        $before = $role->only(['code', 'name', 'description']);
        $role->update($data);
        RoleLog::role($role->id, $before, $role->only(['code', 'name', 'description']), $request->user());

        return response()->json($role->loadCount('users'));
    }

    /** DELETE /roles/{role}. Its permission rows and memberships go with it. */
    public function destroy(Request $request, Role $role): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if($role->code === Role::ADMIN, 422, 'The admin role cannot be deleted.');

        $role->delete();

        return response()->json(null, 204);
    }

    /** @return array{code?: string, name?: string, description?: ?string} */
    private function validated(Request $request, ?Role $current = null): array
    {
        $req = $current ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:100', 'regex:/^[a-z0-9_\-]+$/', Rule::unique('roles', 'code')->ignore($current?->id)],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
    }
}
