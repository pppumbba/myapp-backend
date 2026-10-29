<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Support\Access;
use App\Support\Contents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** File attachments (see Contents): uploading into a form's file field, and downloading. */
class ContentController extends Controller
{
    /** Files a browser may show in place; anything else always downloads. */
    private const INLINE = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain'];

    /** POST /metas/{meta}/files {param_id, file} — an upload, attached when the form saves. */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        abort_unless(Access::can($request->user(), $meta, 'create') || Access::can($request->user(), $meta, 'edit'), 403, "You don't have permission to add files here.");
        $request->validate(['param_id' => ['required', 'integer'], 'file' => ['required', 'file']]);
        $param = MetaParam::where('main_meta_id', $meta->id)->whereIn('data_type', Contents::TYPES)->find($request->integer('param_id'));
        abort_unless($param, 422, 'This field does not take files.');

        $content = Contents::upload($param, $request->file('file'), $request->user());

        return response()->json(Contents::info($content), 201);
    }

    /** GET /contents/{content}?download=1 */
    public function show(Request $request, Content $content): StreamedResponse
    {
        // Public files open without signing in; the token is still read when it is sent.
        abort_unless(Contents::canRead($content, $request->user('sanctum')), 404);
        abort_unless(Storage::disk('local')->exists($content->physical_path), 404, 'The file is missing from storage.');

        $type = self::INLINE[$content->file_extension ?? ''] ?? null;
        $download = $request->boolean('download') || $type === null;

        return Storage::disk('local')->response($content->physical_path, $content->file_name, [
            'Content-Type' => $type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $content->is_public ? 'public, max-age=86400' : 'private, max-age=3600',
        ], $download ? 'attachment' : 'inline');
    }
}
