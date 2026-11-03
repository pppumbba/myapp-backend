<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the user may do, so the app can hide what they can't. */
class AccessController extends Controller
{
    /**
     * GET /user/access: whether they are an admin, their roles, their builder features and the
     * meta types they may make, and for every meta with permission rows, public or their own
     * what they may do with it (metas not listed are closed).
     * A guest gets the public metas only.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'is_admin' => (bool) $user?->isAdmin(),
            'roles' => $user?->roles()->orderBy('name')->get(['roles.id', 'code', 'name']) ?? [],
            'features' => $user?->features() ?? [],
            'build_types' => $user?->isAdmin() ? null : ($user?->isBuilder() ? config('builders.types') : []),
            'metas' => (object) Access::metaRules($user),
        ]);
    }
}
