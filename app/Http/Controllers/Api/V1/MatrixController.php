<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\RecordLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matrix metas: one value for each pair of records from two grids, e.g. each bidder's price
 * for each tender item, or each bidder's score on each KPI. The matrix's table_name holds
 * the values, one row per pair; json_config:
 *
 *   rows:    {meta_id, label}           the grid down the side, and its column to show
 *   columns: {meta_id, label}           the grid across the top
 *   cell:    {row, column, value, type} the table's columns: the row's id, the column's id,
 *                                       the value (type decimal, integer or string)
 *   best:    "min" | "max"              marks each row's best value
 *   weight:  a rows column              weighs each row in the column totals
 *   total:   what each column adds up to: "sum", "avg", "weighted" (average by
 *            weight, e.g. a KPI score) or "amount" (value × weight summed, e.g. price × qty)
 *
 * scope[] keeps both grids to one record (e.g. tender_id), as on the data API; a new value
 * row also gets the scope values its table has columns for.
 */
class MatrixController extends Controller
{
    private const MAX_SIDE = 500;

    /** GET /metas/{meta}/matrix?scope[field]=value */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        [$config, $rows, $columns] = $this->sides($request, $meta);

        $cells = [];
        if ($rows && $columns) {
            $c = $config['cell'];
            DB::table($meta->table_name)->whereIn($c['row'], array_column($rows, 'id'))->whereIn($c['column'], array_column($columns, 'id'))
                ->get([$c['row'], $c['column'], $c['value']])
                ->each(function (object $cell) use (&$cells, $c) {
                    $cells[$cell->{$c['row']}.':'.$cell->{$c['column']}] = $cell->{$c['value']};
                });
        }

        return response()->json([
            'rows' => $rows,
            'columns' => $columns,
            'cells' => (object) $cells,
            'type' => $config['cell']['type'],
            'best' => $config['best'],
            'total' => $config['total'],
            'weighted' => $config['weight'] !== null,
        ]);
    }

    /**
     * PUT /metas/{meta}/matrix {scope, cells: [{row, column, value}]}
     * Writes these values; a blank one clears it. Only pairs of the scoped rows and columns.
     */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'edit');
        [$config, $rows, $columns] = $this->sides($request, $meta);
        $c = $config['cell'];

        $valueRule = match ($c['type']) {
            'integer' => ['nullable', 'integer'],
            'string' => ['nullable', 'string', 'max:255'],
            default => ['nullable', 'numeric'],
        };
        $data = $request->validate([
            'cells' => ['required', 'array', 'max:5000'],
            'cells.*.row' => ['required', 'integer'],
            'cells.*.column' => ['required', 'integer'],
            'cells.*.value' => $valueRule,
        ]);

        $rowIds = array_column($rows, 'id');
        $columnIds = array_column($columns, 'id');
        $table = $meta->table_name;
        $existing = Schema::getColumnListing($table);
        $scope = array_intersect_key($this->scope($request), array_flip($existing));
        $now = array_intersect_key(['created_at' => now(), 'updated_at' => now()], array_flip($existing));

        // With the log on, each cell row with an id is logged with its value before and after.
        $logged = RecordLog::enabled($meta) && in_array('id', $existing, true);
        RecordLog::as('matrix', fn () => DB::transaction(function () use ($data, $rowIds, $columnIds, $table, $c, $scope, $now, $logged, $meta, $request) {
            $log = [];
            foreach ($data['cells'] as $i => $cell) {
                abort_unless(in_array((int) $cell['row'], $rowIds, true) && in_array((int) $cell['column'], $columnIds, true), 422, 'Value '.($i + 1).' is for a row or column that is not in this matrix.');
                $value = $cell['value'] === '' ? null : $cell['value'];
                $pair = [$c['row'] => (int) $cell['row'], $c['column'] => (int) $cell['column']];
                $before = DB::table($table)->where($pair)->first();
                if ($before) {
                    DB::table($table)->where($pair)->update([$c['value'] => $value] + array_diff_key($now, ['created_at' => 1]));
                    if ($logged) {
                        $log[] = [(int) $before->id, 'updated', (array) $before, [$c['value'] => $value] + (array) $before];
                    }
                } elseif ($value !== null) {
                    $row = $pair + [$c['value'] => $value] + $scope + $now;
                    if ($logged) {
                        $id = (int) DB::table($table)->insertGetId($row);
                        $log[] = [$id, 'created', null, ['id' => $id] + $row];
                    } else {
                        DB::table($table)->insert($row);
                    }
                }
            }
            if ($log) {
                RecordLog::writeMany($meta, $table, $log, $request->user());
            }
        }));

        return $this->show($request, $meta);
    }

    /**
     * The matrix's settings, checked, and its rows and columns as the user sees them.
     *
     * @return array{0: array, 1: list<array{id: int, label: string, weight?: float}>, 2: list<array{id: int, label: string}>}
     */
    private function sides(Request $request, Meta $meta): array
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'matrix', 422, 'Only matrix metas have a matrix.');
        $json = $meta->json_config ?? [];
        $table = trim((string) $meta->table_name);
        abort_if($table === '' || in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true) || ! Schema::hasTable($table), 422, 'This matrix needs a table to keep its values in.');

        $cell = (array) ($json['cell'] ?? []);
        foreach (['row', 'column', 'value'] as $part) {
            abort_unless(is_string($cell[$part] ?? null) && Schema::hasColumn($table, $cell[$part]), 422, __('This matrix\'s cell.:part must be a column of :table.', ['part' => $part, 'table' => $table]));
        }
        $config = [
            'cell' => $cell + ['type' => in_array($cell['type'] ?? null, ['integer', 'string'], true) ? $cell['type'] : 'decimal'],
            'best' => in_array($json['best'] ?? null, ['min', 'max'], true) ? $json['best'] : null,
            'total' => in_array($json['total'] ?? null, ['sum', 'avg', 'weighted', 'amount'], true) ? $json['total'] : null,
            'weight' => is_string($json['weight'] ?? null) && $json['weight'] !== '' ? $json['weight'] : null,
        ];

        $query = ['scope' => $this->scope($request)];
        $rows = $this->side($request, (array) ($json['rows'] ?? []), $query, 'rows', $config['weight']);
        $columns = $this->side($request, (array) ($json['columns'] ?? []), $query, 'columns');

        return [$config, $rows, $columns];
    }

    /** @return list<array{id: int, label: string, weight?: float}> */
    private function side(Request $request, array $side, array $query, string $name, ?string $weight = null): array
    {
        $grid = Meta::find($side['meta_id'] ?? 0);
        abort_unless($grid, 422, __('This matrix\'s :name need a grid (meta_id).', ['name' => $name]));
        Access::require($request->user(), $grid, 'view');
        $label = is_string($side['label'] ?? null) ? $side['label'] : 'name';

        // In the order they were entered, not a grid's newest-first.
        return collect(MetaDataController::rowsAs($request->user(), $grid, $query + ['sort' => 'id', 'dir' => 'asc'], self::MAX_SIDE))
            ->map(fn (array $r) => ['id' => (int) $r['id'], 'label' => (string) ($r[$label] ?? "#{$r['id']}")]
                + ($weight !== null ? ['weight' => (float) ($r[$weight] ?? 0)] : []))
            ->all();
    }

    /** @return array<string, string> */
    private function scope(Request $request): array
    {
        $scope = $request->input('scope', $request->query('scope', []));

        return is_array($scope) ? array_map('strval', array_filter($scope, 'is_scalar')) : [];
    }
}
