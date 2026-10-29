<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\Translations;
use App\Support\Xlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

/** A meta's texts in other languages (admins): read, save, and out to Excel and back for translators. */
class TranslationController extends Controller
{
    /** GET /metas/{meta}/translations — its texts, the source language and the languages to translate into. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());

        return response()->json([
            'source' => Translations::source(),
            'languages' => $this->targets(),
            'texts' => Translations::texts($meta),
        ]);
    }

    /** PUT /metas/{meta}/translations {entries: [{key, locale, value}]} */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'entries' => ['present', 'array'],
            'entries.*.key' => ['required', 'string'],
            'entries.*.locale' => ['required', 'string'],
            'entries.*.value' => ['nullable', 'string', 'max:5000'],
        ]);

        return response()->json(Translations::save($meta, $data['entries']) + ['texts' => Translations::texts($meta)]);
    }

    /** GET /metas/{meta}/translations/export — one row per text, one column per language. */
    public function export(Request $request, Meta $meta): Response
    {
        Access::requireAdmin($request->user());
        $languages = $this->targets();
        $source = collect(Translations::languages())->firstWhere('code', Translations::source());
        $headers = ['Key', 'Where', ($source['name'] ?? 'Original').' ('.Translations::source().')', ...array_map(fn ($l) => "{$l['name']} ({$l['code']})", $languages)];
        $rows = array_map(fn ($t) => [$t['key'], $t['where'], $t['original'], ...array_map(fn ($l) => $t['values'][$l['code']] ?? '', $languages)], Translations::texts($meta));

        $file = Str::slug($meta->code).'-translations.xlsx';

        return response(Xlsx::write('Translations', $headers, $rows), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /**
     * POST /metas/{meta}/translations/import {file} — the exported file filled in. Columns are
     * found by the language code in brackets; rows by their key. Empty cells remove nothing.
     */
    public function import(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $request->file('file');
        try {
            $sheet = Xlsx::read($file->getRealPath(), null, $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $header = array_map(fn ($h) => trim((string) $h), $sheet[0] ?? []);
        $keyColumn = array_search('Key', $header, true);
        abort_if($keyColumn === false, 422, 'The first row needs a "Key" column, as in the exported file.');
        $codes = array_column($this->targets(), 'code');
        $columns = [];
        foreach ($header as $i => $h) {
            if (preg_match('/\(([a-z0-9-]+)\)$/i', $h, $m) && in_array(strtolower($m[1]), $codes, true)) {
                $columns[$i] = strtolower($m[1]);
            }
        }
        abort_if($columns === [], 422, 'No column is a language to translate into. Their headings end with the code in brackets, like "Монгол (mn)".');

        $known = array_column(Translations::texts($meta), 'key');
        $entries = [];
        $skipped = 0;
        foreach (array_slice($sheet, 1) as $row) {
            $key = trim((string) ($row[$keyColumn] ?? ''));
            if ($key === '') {
                continue;
            }
            if (! in_array($key, $known, true)) {
                $skipped++;

                continue;
            }
            foreach ($columns as $i => $code) {
                $value = trim((string) ($row[$i] ?? ''));
                if ($value !== '') {
                    $entries[] = ['key' => $key, 'locale' => $code, 'value' => $value];
                }
            }
        }

        return response()->json(Translations::save($meta, $entries) + ['skipped' => $skipped, 'texts' => Translations::texts($meta)]);
    }

    /** @return list<array<string, mixed>> */
    private function targets(): array
    {
        return array_values(array_filter(Translations::languages(), fn ($l) => $l['is_active'] && $l['code'] !== Translations::source()));
    }
}
