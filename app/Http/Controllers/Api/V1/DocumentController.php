<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\Access;
use App\Support\Contents;
use App\Support\Documents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Report metas as PDF documents (see Documents): their designed layout, a preview of a
 * layout being designed, a record (or several) as a PDF, and the images a layout places.
 */
class DocumentController extends Controller
{
    /** GET /metas/{meta}/layout — the saved layout, or a starter one made from the params. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        $this->report($meta);
        Access::require($request->user(), $meta, 'view');

        return response()->json(['layout' => Documents::layoutOf($meta), 'saved' => is_array($meta->json_config['layout'] ?? null)]);
    }

    /** PUT /metas/{meta}/layout */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        $this->report($meta);
        Access::requireAdmin($request->user());
        $request->validate([
            'layout' => ['required', 'array'],
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'number_field' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $layout = Documents::normalize($request->input('layout'));
        $meta->update(['json_config' => array_merge($meta->json_config ?? [], ['layout' => $layout], $request->only(['title', 'number_field']))]);

        return response()->json(['layout' => $layout, 'saved' => true]);
    }

    /** POST /metas/{meta}/layout/preview — a layout not yet saved as a PDF, of a record or of sample values. */
    public function preview(Request $request, Meta $meta): Response
    {
        $this->report($meta);
        Access::requireAdmin($request->user());
        $request->validate(['layout' => ['required', 'array'], 'record_id' => ['nullable', 'string', 'max:64']]);
        $layout = Documents::normalize($request->input('layout'));
        $record = $request->filled('record_id') ? Documents::record($meta, $request->input('record_id'), $request->user()) : Documents::sample($meta);

        return $this->pdf(Documents::pdf($meta, $layout, [$record]), 'preview.pdf', false);
    }

    /** GET /metas/{meta}/data/{id}/pdf?download=1 */
    public function record(Request $request, Meta $meta, string $id): Response
    {
        $this->report($meta);
        $record = Documents::record($meta, $id, $request->user());

        return $this->pdf(Documents::pdf($meta, Documents::layoutOf($meta), [$record]), Documents::fileName($meta, $record), $request->boolean('download'));
    }

    /** GET /metas/{meta}/pdf?ids=1,2,3 — several records in one PDF, each from a new page. */
    public function many(Request $request, Meta $meta): Response
    {
        $this->report($meta);
        $request->validate(['ids' => ['required', 'string', 'max:2000']]);
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $request->input('ids'))), fn ($id) => $id !== '')));
        abort_if(count($ids) > 100, 422, 'At most 100 records print at once.');
        $records = array_map(fn ($id) => Documents::record($meta, $id, $request->user()), $ids);

        return $this->pdf(Documents::pdf($meta, Documents::layoutOf($meta), $records), Str::slug($meta->name).'.pdf', $request->boolean('download'));
    }

    /**
     * POST /metas/{meta}/documents/{param} {values, record_id?} — a form's document field
     * (its report's layout) filled with the form's values as they are now, saved or not: the
     * page the form's document editor draws, with what each part shows (Documents::resolved).
     * A saved record's own values come first, then the form's over them.
     */
    public function fill(Request $request, Meta $meta, MetaParam $param): JsonResponse
    {
        abort_unless($param->main_meta_id === $meta->id && $param->data_type === Contents::DOCUMENT, 404);
        abort_unless(Access::can($request->user(), $meta, 'create') || Access::can($request->user(), $meta, 'edit'), 403, "You don't have permission to fill this in.");
        $report = $param->ref_meta_id ? Meta::find($param->ref_meta_id) : null;
        abort_unless($report && $report->type?->code === 'report', 422, 'This document field needs a report meta as its ref meta.');
        $request->validate(['values' => ['present', 'array'], 'record_id' => ['nullable', 'string', 'max:64']]);

        $saved = $request->filled('record_id') ? MetaDataController::recordAs($request->user(), $report, $request->input('record_id')) : [];
        $params = MetaParam::where('main_meta_id', $meta->id)->get()
            ->merge(MetaParam::where('main_meta_id', $report->id)->get())
            ->unique('field_path')->values();
        $layout = Documents::layoutOf($report);
        $record = Documents::recordFrom($params, array_merge($saved, $request->input('values')));

        return response()->json(['report_meta_id' => $report->id, 'layout' => $layout, 'shown' => (object) Documents::resolved($layout, $record)]);
    }

    /** POST /metas/{meta}/document-assets — a logo, stamp or signature image for the layout. */
    public function upload(Request $request, Meta $meta): JsonResponse
    {
        $this->report($meta);
        Access::requireAdmin($request->user());
        $request->validate(['file' => ['required', 'file', 'mimes:png,jpg,jpeg,gif', 'max:5120']]);
        $file = $request->file('file');
        $name = Str::uuid().'.'.strtolower($file->extension() === 'jpeg' ? 'jpg' : $file->extension());
        $file->storeAs(Documents::ASSETS."/{$meta->id}", $name, 'local');

        return response()->json(['src' => $name], 201);
    }

    /** GET /metas/{meta}/document-assets/{name} — the designer shows the image from here. */
    public function asset(Request $request, Meta $meta, string $name): StreamedResponse
    {
        Access::require($request->user(), $meta, 'view');
        $path = Documents::ASSETS."/{$meta->id}/{$name}";
        abort_unless(preg_match('/^[0-9a-f-]{36}\.(png|jpe?g|gif)$/', $name) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    private function report(Meta $meta): void
    {
        abort_unless($meta->type?->code === 'report', 422, 'Only report metas print as documents.');
    }

    private function pdf(string $bytes, string $name, bool $download): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.addcslashes($name, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($name),
        ]);
    }
}
