<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Support\Access;
use App\Support\Translations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The languages the app speaks (admins add and change them) and the user's own choice. */
class LanguageController extends Controller
{
    /** GET /languages — with the language this request is in. */
    public function index(): JsonResponse
    {
        return response()->json(['languages' => Translations::languages(), 'current' => App::getLocale()]);
    }

    /** POST /languages {code, name} */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', 'unique:languages,code'],
            'name' => ['required', 'string', 'max:100'],
        ], ['code.regex' => 'Use a language code like mn, en or zh-hans.']);
        $language = Language::create($data + ['order_number' => (int) Language::max('order_number') + 1]);
        Translations::forgetLanguages();

        return response()->json($language, 201);
    }

    /**
     * PUT /languages/{language} {name?, is_active?, is_source?, is_default?}. Making one the
     * source or the default takes that from the others; the source and the default stay on.
     */
    public function update(Request $request, Language $language): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_source' => ['sometimes', 'accepted'],
            'is_default' => ['sometimes', 'accepted'],
        ]);
        $turningOff = array_key_exists('is_active', $data) && ! $data['is_active'];
        abort_if($turningOff && ($language->is_source || $language->is_default), 422, 'The source and default languages stay on.');

        DB::transaction(function () use ($language, $data) {
            foreach (['is_source', 'is_default'] as $flag) {
                if (! empty($data[$flag])) {
                    Language::whereKeyNot($language->id)->update([$flag => false]);
                    $data[$flag] = true;
                    $data['is_active'] = true;
                }
            }
            $language->update($data);
        });
        Translations::forgetLanguages();

        return response()->json($language->fresh());
    }

    /** DELETE /languages/{language} — its translations go too. */
    public function destroy(Request $request, Language $language): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if($language->is_source || $language->is_default, 422, 'Make another language the source and default first.');
        $metaIds = DB::table('translations')->where('locale', $language->code)->distinct()->pluck('meta_id');
        DB::transaction(function () use ($language) {
            DB::table('translations')->where('locale', $language->code)->delete();
            DB::table('users')->where('locale', $language->code)->update(['locale' => null]);
            $language->delete();
        });
        $metaIds->each(fn ($id) => Translations::forget($id));
        Translations::forgetLanguages();

        return response()->json(null, 204);
    }

    /** PUT /user/locale {locale} — null follows the browser. */
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['nullable', 'string', Rule::in(Translations::activeCodes())]]);
        $request->user()->update(['locale' => $data['locale'] ?? null]);

        return response()->json($request->user()->fresh());
    }
}
