<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patch;
use App\Models\PatchInstall;
use App\Support\Access;
use App\Support\Patches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Patches made here to ship metas elsewhere, and patch files installed here (admins; see Patches). */
class PatchController extends Controller
{
    /** GET /patches — newest first, with their metas. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(Patch::with(['metas:id,code,name,type_id', 'metas.type:id,code,name', 'releaser:id,name'])->orderByDesc('id')->get());
    }

    /** POST /patches {code, name, description?, with_used?, meta_ids?} */
    public function store(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $this->validated($request);
        $patch = Patch::create([...collect($input)->except('meta_ids')->all(), 'created_by' => $request->user()->id]);
        $patch->metas()->sync($input['meta_ids'] ?? []);

        return response()->json($this->shown($patch), 201);
    }

    /** PUT /patches/{patch} — a draft's name, description and metas. */
    public function update(Request $request, Patch $patch): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_if($patch->isReleased(), 422, __('A released patch can’t change. Make a new one.'));
        $input = $this->validated($request, $patch);
        $patch->update(collect($input)->except('meta_ids')->all());
        if (array_key_exists('meta_ids', $input)) {
            $patch->metas()->sync($input['meta_ids']);
        }

        return response()->json($this->shown($patch));
    }

    public function destroy(Request $request, Patch $patch): JsonResponse
    {
        Access::requireAdmin($request->user());
        $patch->delete();

        return response()->json(null, 204);
    }

    /** POST /patches/{patch}/release — writes its file and freezes it. */
    public function release(Request $request, Patch $patch): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json($this->shown(Patches::release($patch, $request->user())));
    }

    /** GET /patches/{patch}/download — the released file. */
    public function download(Request $request, Patch $patch): StreamedResponse
    {
        Access::requireAdmin($request->user());
        abort_unless($patch->isReleased(), 422, __('Release the patch first.'));

        return $this->file($patch->package, "{$patch->code}.patch.json");
    }

    /** GET /patches/changed?since= — metas changed since then (default: since the newest release). */
    public function changed(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate(['since' => ['nullable', 'date']]);

        return response()->json(Patches::changedSince(isset($input['since']) ? Carbon::parse($input['since']) : null));
    }

    /** GET /patch-installs — patch files installed here, newest first. */
    public function installs(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(PatchInstall::with(['installer:id,name', 'rollbacker:id,name'])->orderByDesc('id')->get());
    }

    /** POST /patch-installs {package, existing, dry_run?, file_name?} — installs a patch file, or says what it would do. */
    public function install(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate([
            'package' => ['required', 'array'],
            'existing' => ['required', 'in:replace,skip,copy'],
            'dry_run' => ['nullable', 'boolean'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);
        $dryRun = (bool) ($input['dry_run'] ?? false);
        $result = Patches::install($input['package'], $input['existing'], $request->user(), $dryRun, $input['file_name'] ?? null);

        return response()->json($result, $dryRun ? 200 : 201);
    }

    /** POST /patch-installs/{install}/rollback */
    public function rollback(Request $request, PatchInstall $install): JsonResponse
    {
        Access::requireAdmin($request->user());
        $warnings = Patches::rollback($install, $request->user());

        return response()->json(['install' => $install->fresh(['installer:id,name', 'rollbacker:id,name']), 'warnings' => $warnings]);
    }

    /** GET /patch-installs/{install}/download — the file as it was installed. */
    public function downloadInstall(Request $request, PatchInstall $install): StreamedResponse
    {
        Access::requireAdmin($request->user());

        return $this->file($install->package, ($install->patch_code ?? 'patch-'.$install->id).'.patch.json');
    }

    private function file(string $contents, string $name): StreamedResponse
    {
        return response()->streamDownload(fn () => print ($contents), $name, ['Content-Type' => 'application/json']);
    }

    private function validated(Request $request, ?Patch $current = null): array
    {
        return $request->validate([
            'code' => [$current ? 'sometimes' : 'required', 'string', 'max:100', 'regex:/^[A-Za-z][A-Za-z0-9_.\-]*$/', Rule::unique('patches', 'code')->ignore($current?->id)],
            'name' => [$current ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'with_used' => ['sometimes', 'boolean'],
            'meta_ids' => ['sometimes', 'array'],
            'meta_ids.*' => ['integer', 'exists:metas,id'],
        ]);
    }

    private function shown(Patch $patch): Patch
    {
        return $patch->load(['metas:id,code,name,type_id', 'metas.type:id,code,name', 'releaser:id,name']);
    }
}
