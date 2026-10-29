<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use App\Support\RoleLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admins see every user and decide which roles each has. */
class UserController extends Controller
{
    /** GET /users?search=: everyone, with their roles. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $search = trim((string) $request->query('search', ''));

        return response()->json(User::with('roles:id,code,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(50, ['id', 'name', 'email', 'created_at']));
    }

    /**
     * PUT /users/{user}/roles {role_ids}: replaces the user's roles. The last admin can't lose
     * the admin role, so there is always someone who can hand out roles.
     */
    public function roles(Request $request, User $user): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $admin = Role::where('code', Role::ADMIN)->first();
        $losesAdmin = $admin && $user->isAdmin() && ! in_array($admin->id, array_map('intval', $data['role_ids']), true);
        abort_if($losesAdmin && $admin->users()->count() <= 1, 422, 'There has to be at least one administrator.');

        $changes = $user->roles()->sync($data['role_ids']);
        $user->forgetRoles();
        foreach ($changes['attached'] as $roleId) {
            RoleLog::users((int) $roleId, [$user->id], [], $request->user());
        }
        foreach ($changes['detached'] as $roleId) {
            RoleLog::users((int) $roleId, [], [$user->id], $request->user());
        }

        return response()->json($user->load('roles:id,code,name')->only(['id', 'name', 'email', 'roles']));
    }
}
