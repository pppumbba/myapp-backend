<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaType;
use App\Support\Access;
use App\Support\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Themes (see Themes): the list everyone picks from, a theme meta's settings, and the user's own choice. */
class ThemeController extends Controller
{
    /** GET /themes — every theme, the default one, and the apps that have their own. */
    public function index(): JsonResponse
    {
        return response()->json(Themes::all());
    }

    /** PUT /metas/{meta}/theme {theme} — a theme meta's settings (admins). */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $request->validate(['theme' => ['required', 'array']]);

        return response()->json(Themes::save($meta, $request->input('theme'), $request->user()->id));
    }

    /**
     * PUT /user/theme {mode, theme_meta_id, size?} — light, dark or system (null), a theme (null: the default),
     * and when sent, how big the app is: sm, md or lg (null: md).
     */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['light', 'dark'])],
            'theme_meta_id' => ['nullable', 'integer', Rule::exists('metas', 'id')->where('type_id', MetaType::where('code', 'theme')->value('id'))],
            'size' => ['sometimes', 'nullable', Rule::in(['sm', 'md', 'lg'])],
        ]);
        $request->user()->update([
            'theme_mode' => $data['mode'] ?? null,
            'theme_meta_id' => $data['theme_meta_id'] ?? null,
            ...array_key_exists('size', $data) ? ['ui_size' => $data['size'] === 'md' ? null : $data['size']] : [],
        ]);

        return response()->json($request->user()->fresh());
    }
}
