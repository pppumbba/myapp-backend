<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A new meta of another type made from an existing one ("make based on"): a form from a
 * datagrid, a grid from a form or query, a matrix whose rows are a grid's, a workspace for a
 * form's records, an import into the same table, a query of its table… It reads the same
 * table (or SQL, or query meta) and starts with the same fields; the rest is set in the
 * builder as usual.
 */
class MetaDerive
{
    /** Types a meta reading rows can be made into. */
    private const FROM_DATA = ['datagrid', 'form', 'calendar', 'gantt', 'chart', 'statement', 'report', 'excel_import', 'matrix', 'query'];

    /** Params that aren't plain columns: details, sub-grids, a query's own design rows and the like. */
    private const NOT_COLUMNS = ['detail', 'subgrid', 'signature', 'singlefile', 'multifile', 'document', 'table', 'union', 'where', 'having', 'group', 'condition', 'subquery', 'widget', 'chart'];

    /**
     * The types this meta can be made into.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function targets(Meta $source): array
    {
        $source->loadMissing('type');
        $type = $source->type?->code;
        $table = trim((string) $source->table_name) !== '';
        $select = $type === 'query' ? trim((string) $source->query_string) !== '' : preg_match('/^\s*(select|with)\b/i', (string) $source->query_string) === 1;

        $codes = [];
        if ($table || $select) {
            $codes = self::FROM_DATA;
            if (! $table) {
                // Forms and imports write rows, so they need a table.
                $codes = array_diff($codes, ['form', 'excel_import', 'query']);
            }
            if ($type !== 'datagrid') {
                $codes = array_diff($codes, ['matrix']);
            }
        }
        if ($type === 'form') {
            $codes[] = 'workspace';
        }
        $codes = array_values(array_diff(array_unique($codes), [$type]));

        return MetaType::whereIn('code', $codes)->orderBy('name')->get(['code', 'name'])->map(fn ($t) => ['code' => $t->code, 'name' => $t->name])->all();
    }

    public static function make(Meta $source, string $typeCode, string $name, string $code, ?User $user): Meta
    {
        abort_unless(in_array($typeCode, array_column(self::targets($source), 'code'), true), 422, __('A :typecode can\'t be made from this meta.', ['typecode' => $typeCode]));
        abort_if(Meta::where('code', $code)->exists(), 422, __('There\'s already a meta with the code ":code".', ['code' => $code]));
        $type = MetaType::where('code', $typeCode)->firstOrFail();
        $fromQuery = $source->type?->code === 'query';

        return DB::transaction(function () use ($source, $type, $name, $code, $user, $fromQuery) {
            if ($type->code === 'datagrid' && $fromQuery) {
                $grid = QueryDesign::makeGrid($source, $user?->id, $code);
                $grid->update(['name' => $name]);

                return $grid;
            }

            $values = ['code' => $code, 'name' => $name, 'type_id' => $type->id, 'is_active' => true, 'created_by' => $user?->id, 'updated_by' => $user?->id,
                'description' => "Made from {$source->name}."];
            if ($type->code === 'matrix') {
                // Its rows are the grid's; columns and the cell table are chosen in the builder.
                $values['json_config'] = ['rows' => ['meta_id' => $source->id, 'label' => self::labelField($source)]];
            } elseif ($type->code === 'workspace') {
                // One record of the form at a time; its sections are added in the builder.
                $values['ref_meta_id'] = $source->id;
                $values['json_config'] = ['title_field' => self::labelField($source)];
            } elseif ($type->code === 'excel_import') {
                $values['table_name'] = $source->table_name;
                $values['json_config'] = ['mode' => 'insert'];
            } elseif ($fromQuery) {
                // Reads the query meta's SQL (see QueryDesign::readFrom).
                $values['ref_meta_id'] = $source->id;
            } elseif ($source->table_name) {
                $values['table_name'] = $source->table_name;
            } else {
                $values['query_string'] = $source->query_string;
            }
            $meta = Meta::create($values);

            if ($type->code === 'query') {
                self::queryOfTable($meta, $source, $user);
            } elseif (! in_array($type->code, ['matrix', 'workspace'], true)) {
                self::copyColumns($source, $meta, $type->code);
            }

            return $meta->fresh();
        });
    }

    /** The source's plain columns, top level. */
    private static function columns(Meta $source): Collection
    {
        return MetaParam::where('main_meta_id', $source->id)->whereNull('parent_id')
            ->whereNotIn('data_type', self::NOT_COLUMNS)
            ->when($source->type?->code === 'query', fn ($q) => $q->where('is_show', true))
            ->orderBy('order_number')->orderBy('id')->get()
            ->filter(fn (MetaParam $p) => preg_match('/^[A-Za-z_]\w*$/', (string) $p->field_path));
    }

    /** The field that names a row: the one marked as label, else the first text one, else the first. */
    private static function labelField(Meta $source): ?string
    {
        $columns = self::columns($source);

        return ($columns->firstWhere('is_label', true) ?? $columns->first(fn ($p) => ! $p->is_primary && in_array($p->data_type, ['string', 'text'], true)) ?? $columns->first())?->field_path;
    }

    /** The source's columns as the new meta's params: a form shows them all, a grid filters by its text ones. */
    private static function copyColumns(Meta $source, Meta $meta, string $type): void
    {
        $order = 0;
        foreach (self::columns($source) as $p) {
            $keep = $p->only(['field_path', 'param_name', 'column_path', 'label_name', 'data_type', 'data_mask', 'render_type', 'ref_meta_id', 'display_field', 'value_field', 'is_primary', 'is_label', 'is_required', 'is_unique', 'is_select', 'default_value', 'min_value', 'max_value', 'json_config']);
            $show = match ($type) {
                'form', 'datagrid', 'excel_import' => ! $p->is_primary,
                default => (bool) $p->is_show,
            };
            MetaParam::create($keep + [
                'main_meta_id' => $meta->id, 'order_number' => ++$order, 'is_show' => $show,
                'label_name' => $p->label_name ?: Str::headline($p->field_path),
                'is_criteria' => $type === 'datagrid' ? ($p->is_criteria || (! $p->is_primary && in_array($p->data_type, ['string', 'text'], true))) : false,
            ]);
        }
    }

    /** A query meta reading the source's table: every column, under alias t. */
    private static function queryOfTable(Meta $query, Meta $source, ?User $user): void
    {
        $table = (string) $source->table_name;
        abort_unless(QueryDesign::allowed($table), 422, __('The table ":table" can\'t be read by a query.', ['table' => $table]));
        $columns = QueryDesign::columns($table);
        QueryDesign::save($query, [
            'tables' => [['key' => 't', 'name' => $table, 'x' => 24, 'y' => 24]],
            'columns' => array_map(fn ($c) => ['expr' => ['table' => 't', 'column' => $c['name']], 'alias' => $c['name'], 'data_type' => $c['data_type'], 'show' => true], $columns),
        ], $user?->id);
    }
}
