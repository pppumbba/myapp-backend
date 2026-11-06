<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaStructure;
use App\Models\MetaType;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A structure meta's table. Its params are the columns: field_path the column, data_type its
 * kind (TYPES), json_config.length a string's length, is_required NOT NULL, is_unique a unique
 * index. Every table also gets id and created_at/updated_at.
 *
 * Nothing changes until apply(). The first apply creates the table, which then belongs to the
 * structure (meta_structures); later ones add new columns and change edited ones. A column whose
 * param was removed is never dropped. A structure only touches a table it made itself.
 */
class Structure
{
    /** The kinds of column a structure param can be. */
    public const TYPES = ['string', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

    /** Columns every structure table has, made by apply() itself. */
    private const OWN = ['id', 'created_at', 'updated_at'];

    /**
     * What apply() would do now, and what stops it.
     *
     * @return array{table: string, creating: bool, applied_at: mixed, changes: list<array{kind: string, column: string, label: string, detail: string}>, problems: list<string>}
     */
    public static function plan(Meta $meta): array
    {
        $table = strtolower(trim((string) $meta->table_name));
        $owned = MetaStructure::where('meta_id', $meta->id)->first();
        $problems = [];

        if ($owned && $owned->table_name !== $table) {
            $problems[] = __('This structure made the table ":table". Set the table back to it: a made table is not renamed.', ['table' => $owned->table_name]);
        } elseif (! $owned) {
            $problems = [...$problems, ...self::tableProblems($table)];
        }

        $specs = [];
        foreach (self::params($meta) as $param) {
            $spec = self::spec($param);
            $problem = self::paramProblem($spec, $param);
            if ($problem) {
                $problems[] = $problem;
            } elseif (isset($specs[$spec['name']])) {
                $problems[] = __('Two params use the column ":column".', ['column' => $spec['name']]);
            } else {
                $specs[$spec['name']] = $spec;
            }
        }
        if (! $specs) {
            $problems[] = __('Add params first: each one becomes a column.');
        }

        $changes = [];
        $applied = $owned?->columns ?? [];
        $exists = $owned && Schema::hasTable($owned->table_name);
        $rows = $exists ? DB::table($owned->table_name)->count() : 0;
        foreach ($specs as $name => $spec) {
            $before = $applied[$name] ?? null;
            if (! $owned) {
                $changes[] = self::change('create', $spec, self::describe($spec));
            } elseif (! $before || ! Schema::hasColumn($owned->table_name, $name)) {
                if ($spec['required'] && $rows > 0) {
                    $problems[] = __('":column" is new and required, but the table already has :count rows. Add it as not required, fill it in, then make it required.', ['column' => $name, 'count' => $rows]);
                }
                $changes[] = self::change('add', $spec, self::describe($spec));
            } elseif (self::differs($before, $spec)) {
                $problems = [...$problems, ...self::changeProblems($owned->table_name, $before, $spec)];
                $changes[] = self::change('change', $spec, self::describe($before).' → '.self::describe($spec));
            }
        }
        foreach (array_diff_key($applied, $specs) as $name => $spec) {
            $changes[] = ['kind' => 'keep', 'column' => $name, 'label' => $spec['label'] ?? $name, 'detail' => __('No param any more: the column and its data stay.')];
        }

        return ['table' => $table, 'creating' => ! $owned, 'applied_at' => $owned?->applied_at, 'changes' => $changes, 'problems' => array_values(array_unique($problems))];
    }

    /**
     * Creates the table or adds and changes its columns, one column at a time; a change that
     * fails stops there with 422, keeping the ones before it. On the first create it can make a
     * datagrid and a form over the table.
     *
     * @return array{changes: list<array{kind: string, column: string, label: string, detail: string}>, grid_id: int|null, form_id: int|null}
     */
    public static function apply(Meta $meta, bool $makeScreens, ?User $user): array
    {
        $plan = self::plan($meta);
        abort_if($plan['problems'], 422, $plan['problems'][0] ?? '');
        $table = $plan['table'];
        $specs = collect(self::params($meta))->map(fn (MetaParam $p) => self::spec($p))->keyBy('name')->all();
        $done = array_values(array_filter($plan['changes'], fn (array $c) => $c['kind'] !== 'keep'));

        if ($plan['creating']) {
            try {
                Schema::create($table, function (Blueprint $t) use ($specs, $table) {
                    $t->id();
                    foreach ($specs as $spec) {
                        self::column($t, $spec);
                        if ($spec['unique']) {
                            $t->unique($spec['name'], self::uniqueIndex($table, $spec['name']));
                        }
                    }
                    $t->timestamps();
                });
            } catch (Throwable $e) {
                Schema::dropIfExists($table);
                abort(422, __('The table could not be made: :error', ['error' => self::reason($e)]));
            }
            MetaStructure::create(['meta_id' => $meta->id, 'table_name' => $table, 'columns' => $specs, 'applied_at' => now(), 'applied_by' => $user?->id]);
            $screens = $makeScreens ? self::screens($meta, $specs, $user) : ['grid_id' => null, 'form_id' => null];

            return ['changes' => $done] + $screens;
        }

        $owned = MetaStructure::where('meta_id', $meta->id)->firstOrFail();
        $applied = $owned->columns ?? [];
        foreach ($done as $change) {
            $spec = $specs[$change['column']];
            $before = $applied[$spec['name']] ?? null;
            try {
                Schema::table($table, function (Blueprint $t) use ($spec, $change) {
                    $column = self::column($t, $spec);
                    if ($change['kind'] === 'change') {
                        $column->change();
                    }
                });
                $wasUnique = $change['kind'] === 'change' && ($before['unique'] ?? false);
                if ($spec['unique'] !== $wasUnique) {
                    Schema::table($table, fn (Blueprint $t) => $spec['unique']
                        ? $t->unique($spec['name'], self::uniqueIndex($table, $spec['name']))
                        : $t->dropUnique(self::uniqueIndex($table, $spec['name'])));
                }
            } catch (Throwable $e) {
                $owned->update(['columns' => $applied, 'applied_at' => now(), 'applied_by' => $user?->id]);
                abort(422, __('Stopped at ":column": :error The columns before it were applied.', ['column' => $spec['name'], 'error' => self::reason($e)]));
            }
            $applied[$spec['name']] = $spec;
        }
        $owned->update(['columns' => $applied, 'applied_at' => now(), 'applied_by' => $user?->id]);

        return ['changes' => $done, 'grid_id' => null, 'form_id' => null];
    }

    /**
     * A param as a column: name, type, length (strings), required, unique, and its label.
     *
     * @return array{name: string, label: string, type: string, length: int|null, required: bool, unique: bool}
     */
    public static function spec(MetaParam $param): array
    {
        $type = (string) $param->data_type;

        return [
            'name' => strtolower(trim((string) $param->field_path)),
            'label' => (string) ($param->label_name ?: $param->field_path),
            'type' => $type,
            'length' => $type === 'string' ? max(1, min(2000, (int) ($param->json_config['length'] ?? 255))) : null,
            'required' => (bool) $param->is_required,
            'unique' => (bool) $param->is_unique,
        ];
    }

    /** @return list<MetaParam> the structure's own params (not nested ones), in order */
    private static function params(Meta $meta): array
    {
        return $meta->params()->whereNull('parent_id')->orderBy('order_number')->orderBy('id')->get()->all();
    }

    /** @return list<string> why a new table cannot have this name */
    private static function tableProblems(string $table): array
    {
        if ($table === '') {
            return [__('Set the table name (Edit at the top) first.')];
        }
        if (! preg_match('/^[a-z][a-z0-9_]{1,62}$/', $table)) {
            return [__('A table name uses small letters, digits and _ and starts with a letter.')];
        }
        if (in_array($table, MetaDataController::BLOCKED_TABLES, true) || str_starts_with($table, 'meta_')) {
            return [__('":table" is one of the app\'s own tables. Choose another name.', ['table' => $table])];
        }
        if (Schema::hasTable($table)) {
            return [__('There\'s already a table ":table". A structure only makes a new table; choose another name.', ['table' => $table])];
        }
        if (MetaStructure::where('table_name', $table)->exists()) {
            return [__('Another structure already made ":table".', ['table' => $table])];
        }

        return [];
    }

    private static function paramProblem(array $spec, MetaParam $param): ?string
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $spec['name'])) {
            return __('":column" is not a column name: use small letters, digits and _, starting with a letter.', ['column' => $param->field_path]);
        }
        if (in_array($spec['name'], self::OWN, true)) {
            return __('":column" is made for every table already; remove that param.', ['column' => $spec['name']]);
        }
        if (! in_array($spec['type'], self::TYPES, true)) {
            return __('":column" is a :type param. A structure\'s columns are :types.', ['column' => $spec['name'], 'type' => $spec['type'], 'types' => implode(', ', self::TYPES)]);
        }

        return null;
    }

    /** @return list<string> what in the table's data stops this change */
    private static function changeProblems(string $table, array $before, array $spec): array
    {
        $problems = [];
        $name = $spec['name'];
        if ($spec['required'] && ! ($before['required'] ?? false) && ($empty = DB::table($table)->whereNull($name)->count())) {
            $problems[] = __(':count rows have no ":column". Fill them in before making it required.', ['count' => $empty, 'column' => $name]);
        }
        if ($spec['unique'] && ! ($before['unique'] ?? false)) {
            $twice = DB::table($table)->whereNotNull($name)->groupBy($name)->havingRaw('COUNT(*) > 1')->select($name)->get()->count();
            if ($twice) {
                $problems[] = __(':count values of ":column" are used more than once. Make them unique first.', ['count' => $twice, 'column' => $name]);
            }
        }
        if ($spec['type'] === 'string' && ($before['type'] ?? null) === 'string' && $spec['length'] < ($before['length'] ?? 255)) {
            $long = DB::table($table)->whereRaw('LENGTH('.DB::getQueryGrammar()->wrap($name).') > ?', [$spec['length']])->count();
            if ($long) {
                $problems[] = __(':count rows have a ":column" longer than :length characters.', ['count' => $long, 'column' => $name, 'length' => $spec['length']]);
            }
        }

        return $problems;
    }

    private static function differs(array $before, array $spec): bool
    {
        foreach (['type', 'length', 'required', 'unique'] as $key) {
            if (($before[$key] ?? null) !== $spec[$key]) {
                return true;
            }
        }

        return false;
    }

    private static function change(string $kind, array $spec, string $detail): array
    {
        return ['kind' => $kind, 'column' => $spec['name'], 'label' => $spec['label'], 'detail' => $detail];
    }

    /** "string(100), required, unique". */
    private static function describe(array $spec): string
    {
        $parts = [$spec['type'] === 'string' ? "string({$spec['length']})" : $spec['type']];
        if ($spec['required'] ?? false) {
            $parts[] = 'required';
        }
        if ($spec['unique'] ?? false) {
            $parts[] = 'unique';
        }

        return implode(', ', $parts);
    }

    private static function column(Blueprint $t, array $spec): ColumnDefinition
    {
        $name = $spec['name'];
        $column = match ($spec['type']) {
            'integer' => $t->bigInteger($name),
            'decimal' => $t->decimal($name, 20, 6),
            'date' => $t->date($name),
            'datetime' => $t->dateTime($name),
            'boolean' => $t->boolean($name),
            'text' => $t->text($name),
            default => $t->string($name, $spec['length'] ?? 255),
        };

        return $column->nullable(! $spec['required']);
    }

    private static function uniqueIndex(string $table, string $column): string
    {
        return substr("{$table}_{$column}_unique", 0, 64);
    }

    private static function reason(Throwable $e): string
    {
        return rtrim((string) ($e->getPrevious()?->getMessage() ?: $e->getMessage()), '.').'.';
    }

    /**
     * A datagrid and a form over the new table, with a param per column; the grid opens the form.
     *
     * @return array{grid_id: int, form_id: int}
     */
    private static function screens(Meta $meta, array $specs, ?User $user): array
    {
        return DB::transaction(function () use ($meta, $specs, $user) {
            $types = MetaType::whereIn('code', ['form', 'datagrid'])->pluck('id', 'code');
            $base = ['table_name' => $meta->table_name, 'is_active' => true, 'created_by' => $user?->id, 'updated_by' => $user?->id];
            $form = Meta::create($base + ['code' => self::freeCode("{$meta->code}_form"), 'name' => "{$meta->name} form", 'type_id' => $types['form'], 'description' => "Adds and edits rows of {$meta->table_name}."]);
            $grid = Meta::create($base + ['code' => self::freeCode("{$meta->code}_grid"), 'name' => $meta->name, 'type_id' => $types['datagrid'], 'ref_meta_id' => $form->id, 'description' => "The rows of {$meta->table_name}."]);

            foreach ([$form, $grid] as $screen) {
                MetaParam::create(['main_meta_id' => $screen->id, 'order_number' => 1, 'field_path' => 'id', 'param_name' => 'id', 'label_name' => 'ID', 'data_type' => 'integer', 'is_primary' => true, 'is_show' => false]);
                $order = 1;
                foreach ($specs as $spec) {
                    MetaParam::create([
                        'main_meta_id' => $screen->id, 'order_number' => ++$order, 'field_path' => $spec['name'], 'param_name' => $spec['name'],
                        'label_name' => $spec['label'], 'data_type' => $spec['type'], 'is_show' => true,
                        'is_required' => $screen->is($form) && $spec['required'],
                        'is_criteria' => $screen->is($grid) && in_array($spec['type'], ['string', 'text'], true),
                    ]);
                }
            }

            return ['grid_id' => $grid->id, 'form_id' => $form->id];
        });
    }

    private static function freeCode(string $code): string
    {
        $free = $code;
        for ($i = 2; Meta::where('code', $free)->exists(); $i++) {
            $free = "{$code}_{$i}";
        }

        return $free;
    }
}
