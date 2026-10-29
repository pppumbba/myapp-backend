<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Loads the rows of an Excel sheet into a table, as an excel_import meta describes.
 *
 * The meta's table_name is where records go. Each param is one column of the file:
 * field_path is the table column it fills, label_name the heading it has in the file
 * (param_name and field_path match too, and json_config.column = "C" picks a column by
 * letter instead). The usual param settings check each value: data_type, is_required,
 * min/max, json_config.options, is_unique. A lookup (is_select, ref_meta_id) takes the
 * looked-up record's display_field in the file and saves its value_field. default_value
 * fills empty cells of new records, and a param whose column is missing from the file.
 *
 * The meta's json_config: mode "insert" (default) adds every row; "update" changes the
 * records whose key columns (json_config.key, else the is_unique params, else the primary
 * key) match, leaving out empty cells; "upsert" does either. sheet (name or position) and
 * header_row (default 1) say where the table starts.
 *
 * Every row is checked before any is written: one problem anywhere, and nothing is saved.
 */
class ExcelImport
{
    public const MODES = ['insert', 'update', 'upsert'];

    public const MAX_ROWS = 10000;

    /** Problems listed back; more are only counted. */
    private const MAX_ERRORS = 200;

    private const SKIPPED_TYPES = ['detail', 'subgrid', 'signature', 'json', 'singlefile', 'multifile', 'document'];

    private string $table;

    private string $primary;

    /** @var Collection<int, MetaParam> */
    private Collection $params;

    /** @var list<string> */
    private array $keys;

    private string $mode;

    /** @var list<array{row: int, column: ?string, message: string}> */
    private array $errors = [];

    private int $errorCount = 0;

    public function __construct(private Meta $meta, private ?User $user = null)
    {
        $table = trim((string) $meta->table_name);
        abort_if($table === '', 422, 'This import has no table set.');
        abort_if(in_array(strtolower($table), MetaDataController::BLOCKED_TABLES, true), 422, 'This table cannot be used by a meta.');
        abort_unless(Schema::hasTable($table), 422, __('The table ":table" does not exist.', ['table' => $table]));
        $this->table = $table;

        $existing = Schema::getColumnListing($table);
        $all = MetaParam::where('main_meta_id', $meta->id)->orderBy('order_number')->get()
            ->filter(fn (MetaParam $p) => in_array($p->field_path, $existing, true) && ! in_array($p->data_type, self::SKIPPED_TYPES, true))
            ->values();
        $this->primary = $all->firstWhere('is_primary', true)?->field_path ?? 'id';
        abort_unless(in_array($this->primary, $existing, true), 422, 'This table has no primary key column.');
        $this->params = $all;

        $config = $meta->json_config ?? [];
        $this->mode = in_array($config['mode'] ?? null, self::MODES, true) ? $config['mode'] : 'insert';
        $keys = array_values(array_filter((array) ($config['key'] ?? []), fn ($k) => is_string($k) && $all->contains('field_path', $k)));
        $this->keys = $keys ?: ($all->where('is_unique', true)->pluck('field_path')->values()->all() ?: [$this->primary]);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** The heading a param has in the file. */
    public static function heading(MetaParam $param): string
    {
        return $param->label_name ?: $param->param_name ?: $param->field_path;
    }

    /** Params the file can fill: never the primary key unless it is the match key, never timestamps. */
    private function columns(): Collection
    {
        return $this->params->filter(fn (MetaParam $p) => ($this->mode !== 'insert' && in_array($p->field_path, $this->keys, true))
            || (! $p->is_primary && ! in_array($p->field_path, ParamRules::MANAGED_COLUMNS, true)))->values();
    }

    /** An empty workbook to fill in: the headings, and a sheet saying what each column takes. */
    public function template(): string
    {
        $columns = $this->columns()->filter(fn (MetaParam $p) => $p->is_show || in_array($p->field_path, $this->keys, true))->values();
        $notes = [['Column', 'Required', 'Type', 'Allowed values']];
        foreach ($columns as $p) {
            $options = $p->json_config['options'] ?? null;
            $notes[] = [
                self::heading($p),
                $p->is_required || ($this->mode !== 'insert' && in_array($p->field_path, $this->keys, true)) ? 'Yes' : '',
                $p->is_select && $p->ref_meta_id ? 'Name of a '.(Meta::whereKey($p->ref_meta_id)->value('name') ?? 'record') : $p->data_type,
                is_array($options) ? implode(', ', $options) : $this->range($p),
            ];
        }

        return Xlsx::write($this->meta->label_name ?: $this->meta->name, $columns->map(fn ($p) => self::heading($p))->all(), [], [], ['How to fill' => $notes]);
    }

    private function range(MetaParam $p): string
    {
        $min = is_numeric($p->min_value) ? $p->min_value : null;
        $max = is_numeric($p->max_value) ? $p->max_value : null;

        return match (true) {
            $min !== null && $max !== null => "{$min} to {$max}",
            $min !== null => "At least {$min}",
            $max !== null => "At most {$max}",
            in_array($p->data_type, ['date', 'datetime'], true) => 'A date, e.g. 2026-10-20',
            $p->data_type === 'boolean' => 'Yes or No',
            default => '',
        };
    }

    /**
     * Checks every row and, unless $dryRun or something is wrong, saves them all.
     *
     * @param  list<list<mixed>>  $sheet
     * @return array{mode: string, dry_run: bool, saved: bool, total: int, inserted: int, updated: int, errors: list<array{row: int, column: ?string, message: string}>, error_count: int, columns: list<array{heading: string, field: string, column: ?string}>, preview: list<array<string, mixed>>}
     */
    public function run(array $sheet, bool $dryRun): array
    {
        $headerRow = max(1, (int) ($this->meta->json_config['header_row'] ?? 1));
        $found = $this->locate($sheet[$headerRow - 1] ?? [], $headerRow);
        $mapped = $this->columns()->filter(fn (MetaParam $p) => isset($found[$p->field_path]))->values();

        // Data rows, numbered as in Excel; rows with nothing in the mapped columns are skipped.
        $rows = [];
        foreach (array_slice($sheet, $headerRow, null, true) as $i => $cells) {
            if ($mapped->contains(fn (MetaParam $p) => ! $this->blank($cells[$found[$p->field_path]] ?? null))) {
                $rows[$i + 1] = $cells;
            }
        }
        if (! $this->errors && ! $rows) {
            $this->error($headerRow + 1, null, 'The file has no rows to import under its headings.');
        }
        if (count($rows) > self::MAX_ROWS) {
            $this->error($headerRow + 1, null, 'The file has '.count($rows).' rows; import at most '.self::MAX_ROWS.' at a time.');
        }

        $records = [];
        if (! $this->errors) {
            $lookups = $this->lookups($mapped, $found, $rows);
            $seen = [];
            foreach ($rows as $n => $cells) {
                $record = $this->record($n, $cells, $mapped, $found, $lookups, $seen);
                if ($record) {
                    $records[$n] = $record;
                }
            }
        }

        $inserted = count(array_filter($records, fn ($r) => $r['id'] === null));
        $saved = false;
        if (! $this->errors && ! $dryRun) {
            $this->save($records);
            $saved = true;
        }

        return [
            'mode' => $this->mode,
            'dry_run' => $dryRun,
            'saved' => $saved,
            'total' => count($rows),
            'inserted' => $inserted,
            'updated' => count($records) - $inserted,
            'errors' => $this->errors,
            'error_count' => $this->errorCount,
            'columns' => $this->columns()->map(fn (MetaParam $p) => [
                'heading' => self::heading($p),
                'field' => $p->field_path,
                'column' => isset($found[$p->field_path]) ? Xlsx::columnLetter($found[$p->field_path]) : null,
            ])->all(),
            'preview' => array_values(array_map(fn ($r) => $r['values'], array_slice($records, 0, 5))),
        ];
    }

    /**
     * Which column of the file each param reads (field_path => index). Missing columns that
     * must be there (the match key; a required param with no default) are errors.
     *
     * @param  list<mixed>  $headers
     * @return array<string, int>
     */
    private function locate(array $headers, int $headerRow): array
    {
        $norm = fn ($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
        $byName = [];
        foreach ($headers as $i => $h) {
            if (! $this->blank($h)) {
                $byName[$norm($h)] ??= $i;
            }
        }

        $found = [];
        foreach ($this->columns() as $p) {
            $letter = $p->json_config['column'] ?? null;
            if (is_string($letter) && preg_match('/^[A-Za-z]{1,3}$/', $letter)) {
                $found[$p->field_path] = Xlsx::columnIndex($letter);

                continue;
            }
            foreach (array_filter([$p->json_config['header'] ?? null, $p->label_name, $p->param_name, $p->field_path], 'is_string') as $name) {
                if (isset($byName[$norm($name)])) {
                    $found[$p->field_path] = $byName[$norm($name)];
                    break;
                }
            }

            $isKey = $this->mode !== 'insert' && in_array($p->field_path, $this->keys, true);
            $needed = $isKey || ($this->mode !== 'update' && $p->is_required && $this->blank($p->default_value));
            if (! isset($found[$p->field_path]) && $needed) {
                $this->error($headerRow, self::heading($p), $isKey ? 'This column is needed to find the records to update.' : 'This column is required but the file does not have it.');
            }
        }

        return $found;
    }

    /**
     * For each lookup column, the stored values of the names in the file (lowercased name => value). A
     * value the lookup stores itself (an id, or a code) is taken as it is.
     *
     * @return array<string, array<string, int|string>>
     */
    private function lookups(Collection $mapped, array $found, array $rows): array
    {
        $maps = [];
        foreach ($mapped->filter(fn (MetaParam $p) => $p->is_select && $p->ref_meta_id) as $p) {
            $table = ParamRules::lookupTable($p);
            $label = $p->displayField();
            $key = $p->valueField();
            $values = collect($rows)->map(fn ($cells) => $cells[$found[$p->field_path]] ?? null)
                ->reject(fn ($v) => $this->blank($v))->map(fn ($v) => $this->text($v))->unique()->values();
            $map = [];
            if ($table && Schema::hasColumn($table, $label) && Schema::hasColumn($table, $key)) {
                foreach ($values->chunk(1000) as $chunk) {
                    $lower = $chunk->map(fn ($v) => mb_strtolower($v))->all();
                    foreach (DB::table($table)->whereIn(DB::raw('LOWER('.DB::getQueryGrammar()->wrap($label).')'), $lower)->get([$key, $label]) as $r) {
                        $map[mb_strtolower((string) $r->{$label})] ??= $r->{$key};
                    }
                }
                // A value the lookup stores itself (an id, or a code) is taken as it is.
                $unmatched = $values->reject(fn ($v) => isset($map[mb_strtolower($v)]));
                foreach (DB::table($table)->whereIn($key, $unmatched->all())->pluck($key) as $stored) {
                    $map[(string) $stored] = $stored;
                }
            }
            $maps[$p->field_path] = $map;
        }

        return $maps;
    }

    /**
     * One row as values to write, or null when it has a problem (recorded in errors).
     *
     * @return array{id: ?int, values: array<string, mixed>}|null
     */
    private function record(int $n, array $cells, Collection $mapped, array $found, array $lookups, array &$seen): ?array
    {
        $values = [];
        $ok = true;
        foreach ($mapped as $p) {
            $raw = $cells[$found[$p->field_path]] ?? null;
            if ($this->blank($raw)) {
                $values[$p->field_path] = null;

                continue;
            }
            [$value, $problem] = isset($lookups[$p->field_path])
                ? $this->lookupValue($p, $raw, $lookups[$p->field_path])
                : $this->convert($p, $raw);
            if ($problem) {
                $this->error($n, self::heading($p), $problem);
                $ok = false;
            }
            $values[$p->field_path] = $value;
        }
        if (! $ok) {
            return null;
        }

        // Which record a row updates, by its key columns.
        $id = null;
        if ($this->mode !== 'insert') {
            $keyValues = array_intersect_key($values, array_flip($this->keys));
            $blankKey = in_array(null, $keyValues, true);
            // An upsert row with no key is simply new; an update needs it.
            if ($blankKey && $this->mode === 'update') {
                $this->error($n, null, 'The columns that find the record ('.$this->keyHeadings().') must be filled in.');

                return null;
            }
            $id = $blankKey ? null : DB::table($this->table)->where($keyValues)->value($this->primary);
            if ($id === null && $this->mode === 'update') {
                $this->error($n, null, 'No record matches '.$this->keyHeadings().' '.implode(' / ', $keyValues).'.');

                return null;
            }
        }

        // The same unique value (or the same record to update) twice in one file.
        foreach ($mapped->filter(fn (MetaParam $p) => $p->is_unique || ($this->mode !== 'insert' && in_array($p->field_path, $this->keys, true))) as $p) {
            $v = $values[$p->field_path];
            if ($v === null) {
                continue;
            }
            $k = $p->field_path."\0".mb_strtolower((string) $v);
            if (isset($seen[$k])) {
                $this->error($n, self::heading($p), "\"{$v}\" is also on row {$seen[$k]}.");
                $ok = false;
            }
            $seen[$k] ??= $n;
        }

        if ($id === null) {
            // A new record: empty cells, and columns the file lacks, take their defaults.
            foreach ($this->columns() as $p) {
                if (($values[$p->field_path] ?? null) === null && ! $this->blank($p->default_value)) {
                    [$default] = $this->convert($p, $p->default_value);
                    $values[$p->field_path] = $default;
                }
            }
            unset($values[$this->primary]);
            $check = $this->columns();
        } else {
            // An update leaves empty cells alone, and never changes the key itself.
            $values = array_filter($values, fn ($v) => $v !== null);
            unset($values[$this->primary]);
            $check = $mapped;
        }

        $validator = Validator::make($values, ParamRules::for($check, $this->table, $this->primary, $id === null ? null : (int) $id), [], $check->mapWithKeys(fn ($p) => [$p->field_path => self::heading($p)])->all());
        foreach ($validator->errors()->messages() as $field => $messages) {
            $param = $this->params->firstWhere('field_path', $field);
            $this->error($n, $param ? self::heading($param) : $field, $messages[0]);
            $ok = false;
        }

        return $ok ? ['id' => $id === null ? null : (int) $id, 'values' => $values] : null;
    }

    /** @return array{0: mixed, 1: ?string} the value and, if it can't be used, why */
    private function lookupValue(MetaParam $p, mixed $raw, array $map): array
    {
        $text = $this->text($raw);
        $id = $map[mb_strtolower($text)] ?? $map[$text] ?? null;

        return $id === null ? [null, "No record named \"{$text}\" was found."] : [$id, null];
    }

    /**
     * A cell as the param's data type.
     *
     * @return array{0: mixed, 1: ?string} the value and, if it can't be used, why
     */
    private function convert(MetaParam $p, mixed $raw): array
    {
        $options = $p->json_config['options'] ?? null;
        if (is_array($options)) {
            $text = $this->text($raw);
            foreach ($options as $o) {
                if (mb_strtolower((string) $o) === mb_strtolower($text)) {
                    return [$o, null];
                }
            }

            return [$text, 'Use one of: '.implode(', ', $options).'.'];
        }

        switch ($p->data_type) {
            case 'integer':
            case 'decimal':
                $n = is_bool($raw) ? (int) $raw : $raw;
                if (is_string($n)) {
                    // Spaces and thousands commas, e.g. "1,250 000".
                    $n = preg_match('/^-?[\d\s,]*\.?\d+$/', trim($n)) ? str_replace([' ', ','], '', trim($n)) : $n;
                }
                if (! is_numeric($n)) {
                    return [$raw, $p->data_type === 'integer' ? 'Enter a whole number.' : 'Enter a number.'];
                }
                if ($p->data_type === 'integer') {
                    return fmod((float) $n, 1.0) == 0.0 ? [(int) $n, null] : [$raw, 'Enter a whole number.'];
                }

                return [0 + $n, null];

            case 'boolean':
                if (is_bool($raw)) {
                    return [$raw, null];
                }
                $b = mb_strtolower($this->text($raw));
                if (in_array($b, ['1', 'yes', 'y', 'true', 'on', 'x'], true)) {
                    return [true, null];
                }
                if (in_array($b, ['0', 'no', 'n', 'false', 'off'], true)) {
                    return [false, null];
                }

                return [$raw, 'Use Yes or No.'];

            case 'date':
            case 'datetime':
                $withTime = $p->data_type === 'datetime';
                $text = $this->text($raw);
                // Excel keeps dates as day numbers; a cell formatted as text shows the number.
                if (is_numeric($raw) && (float) $raw >= 1 && (float) $raw < 2958466) {
                    return [Xlsx::fromSerial((float) $raw, $withTime), null];
                }
                $format = $p->json_config['format'] ?? null;
                $date = is_string($format) ? DateTimeImmutable::createFromFormat('!'.$format, $text) : (strtotime($text) !== false ? new DateTimeImmutable($text) : false);
                if (! $date) {
                    return [$raw, 'Enter a date'.(is_string($format) ? " as {$format}" : ', e.g. 2026-10-20').'.'];
                }

                return [$date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d'), null];

            default:
                return [$this->text($raw), null];
        }
    }

    /** @param  array<int, array{id: ?int, values: array<string, mixed>}>  $records */
    private function save(array $records): void
    {
        $now = now();
        $hasCreated = Schema::hasColumn($this->table, 'created_at');
        $hasUpdated = Schema::hasColumn($this->table, 'updated_at');

        try {
            RecordLog::as('import', fn () => DB::transaction(function () use ($records, $now, $hasCreated, $hasUpdated) {
                // With the log on, rows are read before they change, and new ones get their id.
                $logged = RecordLog::enabled($this->meta);
                $before = $logged
                    ? DB::table($this->table)->whereIn($this->primary, array_filter(array_column($records, 'id')))->get()->keyBy($this->primary)->map(fn ($r) => (array) $r)
                    : collect();
                $log = [];
                foreach ($records as $r) {
                    $values = $r['values'] + ($hasUpdated ? ['updated_at' => $now] : []);
                    if ($r['id'] === null) {
                        $values = Autonumber::fill($this->table, $this->params, $values);
                        $row = $values + ($hasCreated ? ['created_at' => $now] : []);
                        if ($logged) {
                            $id = (int) DB::table($this->table)->insertGetId($row);
                            $log[] = [$id, 'created', null, [$this->primary => $id] + $row];
                        } else {
                            DB::table($this->table)->insert($row);
                        }
                    } else {
                        DB::table($this->table)->where($this->primary, $r['id'])->update($values);
                        if ($logged) {
                            $old = $before[$r['id']] ?? [];
                            $log[] = [(int) $r['id'], 'updated', $old, $values + $old];
                        }
                    }
                }
                if ($log) {
                    RecordLog::writeMany($this->meta, $this->table, $log, $this->user);
                }
            }));
        } catch (QueryException $e) {
            abort(422, 'The database refused the import, so nothing was saved: '.($e->errorInfo[2] ?? 'unknown error').'.');
        }
    }

    private function keyHeadings(): string
    {
        return collect($this->keys)->map(fn ($k) => ($p = $this->params->firstWhere('field_path', $k)) ? self::heading($p) : $k)->implode(', ');
    }

    /** Cell text: whole numbers without ".0", trimmed. */
    private function text(mixed $raw): string
    {
        if (is_float($raw) && fmod($raw, 1.0) == 0.0 && abs($raw) < 1e15) {
            return (string) (int) $raw;
        }
        if (is_bool($raw)) {
            return $raw ? 'TRUE' : 'FALSE';
        }

        return trim((string) $raw);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function error(int $row, ?string $column, string $message): void
    {
        $this->errorCount++;
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = ['row' => $row, 'column' => $column, 'message' => $message];
        }
    }
}
