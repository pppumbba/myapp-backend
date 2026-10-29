<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\ExcelStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A table and datagrid made from a spreadsheet (see ExcelStructure): upload it, look at a
 * sheet, see the columns the rows left would make, then make them. Admins only.
 */
class ExcelStructureController extends Controller
{
    /** POST /excel-structure {file} — keeps the file and shows its first sheet. */
    public function upload(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:xlsx,csv']]);

        return response()->json(ExcelStructure::preview(ExcelStructure::store($request->file('file')), null), 201);
    }

    /** GET /excel-structure/{token}?sheet= — another sheet of the file. */
    public function show(Request $request, string $token): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json(ExcelStructure::preview($token, $request->query('sheet')));
    }

    /** POST /excel-structure/{token}/columns {sheet, skip_rows, skip_columns} — the columns it would make. */
    public function columns(Request $request, string $token): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $this->validated($request);

        return response()->json(ExcelStructure::columns($token, $input['sheet'] ?? null, $input['skip_rows'] ?? [], $input['skip_columns'] ?? []));
    }

    /** POST /excel-structure/{token}/create {sheet, skip_rows, skip_columns, name, code, table, types, labels} — makes the table and its grid. */
    public function create(Request $request, string $token): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $this->validated($request) + $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'table' => ['nullable', 'string', 'max:64'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', 'in:'.implode(',', ExcelStructure::TYPES)],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(ExcelStructure::create($token, $input, $request->user()->id), 201);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'sheet' => ['nullable', 'string', 'max:255'],
            'skip_rows' => ['nullable', 'array'],
            'skip_rows.*' => ['integer', 'min:0'],
            'skip_columns' => ['nullable', 'array'],
            'skip_columns.*' => ['integer', 'min:0'],
        ]);
    }
}
