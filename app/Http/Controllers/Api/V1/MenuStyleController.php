<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\MenuStyles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Menu styles (see MenuStyles): the list apps pick from, and a menu style meta's settings. */
class MenuStyleController extends Controller
{
    /** GET /menu-styles — every active menu style, filled in. */
    public function index(): JsonResponse
    {
        return response()->json(['styles' => MenuStyles::all()]);
    }

    /** PUT /metas/{meta}/menu-style {menu} — a menu style's settings (admins, or the builder who made it). */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        abort_unless(Access::designs($request->user(), $meta), 403);
        $request->validate(['menu' => ['present', 'array']]);

        return response()->json(MenuStyles::save($meta, (array) $request->input('menu'), $request->user()->id));
    }
}
