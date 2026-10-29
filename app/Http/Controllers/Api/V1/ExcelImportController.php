<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\ExcelImport;
use App\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

/** Excel imports: an excel_import meta's template, and loading a file through it (see ExcelImport). */
class ExcelImportController extends Controller
{
    /** GET /metas/{meta}/import/template — an empty workbook with the import's headings. */
    public function template(Request $request, Meta $meta): Response
    {
        Access::require($request->user(), $meta, 'view');
        $import = new ExcelImport($this->importMeta($meta));
        $file = Str::slug($meta->label_name ?: $meta->name).'-template.xlsx';

        return response($import->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /**
     * POST /metas/{meta}/import, multipart: file (.xlsx or .csv), dry_run.
     * A dry run checks every row and saves nothing. Otherwise the rows are saved only when
     * none has a problem. Adding records needs the create permission on the import meta;
     * changing them, edit.
     */
    public function store(Request $request, Meta $meta): JsonResponse
    {
        $import = new ExcelImport($this->importMeta($meta), $request->user());
        $mode = $import->mode();
        if ($mode !== 'update') {
            Access::require($request->user(), $meta, 'create');
        }
        if ($mode !== 'insert') {
            Access::require($request->user(), $meta, 'edit');
        }

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:xlsx,csv'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $file = $data['file'];
        try {
            $sheet = Xlsx::read($file->getRealPath(), $meta->json_config['sheet'] ?? null, $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json($import->run($sheet, (bool) ($data['dry_run'] ?? false)));
    }

    private function importMeta(Meta $meta): Meta
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'excel_import', 422, 'Only Excel import metas import files.');

        return $meta;
    }
}
