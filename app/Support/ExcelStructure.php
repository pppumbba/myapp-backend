<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A new table and datagrid made from a spreadsheet. The file is kept for a day under a
 * token while the builder looks at it; the admin picks a sheet and removes the rows and
 * columns that aren't the table (titles, notes, totals). The first row left gives the
 * columns' labels, the rest are the rows. The table gets columns c1, c2, … (typed from
 * their values unless chosen) and a datagrid meta whose params carry the labels.
 */
class ExcelStructure
{
    /** Uploads are kept here, and removed after KEEP_HOURS. */
    private const DIR = 'excel-structure';

    private const KEEP_HOURS = 24;

    /** The preview shows the first PREVIEW_ROWS rows; creating reads them all. */
    public const PREVIEW_ROWS = 100;

    public const MAX_COLUMNS = 200;

    public const TYPES = ['string', 'text', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

    /** Tables made this way all start so, which keeps them apart from the app's own. */
    public const PREFIX = 'xl_';

    /** Keeps an upload and gives back its token. */
    public static function store(UploadedFile $file): string
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(self::DIR) as $old) {
            if ($disk->lastModified($old) < now()->subHours(self::KEEP_HOURS)->getTimestamp()) {
                $disk->delete($old);
            }
        }
        $token = Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension());
        $file->storeAs(self::DIR, $token, 'local');
        self::rows($token, null);

        return $token;
    }

    /**
     * A sheet as the builder shows it: the file's sheets, its first rows and its size.
     *
     * @return array{token: string, sheets: list<string>, sheet: string, rows: list<list<mixed>>, total: int, width: int}
     */
    public static function preview(string $token, ?string $sheet): array
    {
        $sheets = Xlsx::sheets(self::path($token), self::extension($token));
        $sheet = $sheet !== null && in_array($sheet, $sheets, true) ? $sheet : $sheets[0];
        $rows = self::rows($token, $sheet);
        $width = min(self::MAX_COLUMNS, max(0, ...array_map('count', $rows ?: [[]])));
        $shown = array_map(fn ($r) => array_pad(array_slice($r, 0, $width), $width, null), array_slice($rows, 0, self::PREVIEW_ROWS));

        return ['token' => $token, 'sheets' => $sheets, 'sheet' => $sheet, 'rows' => $shown, 'total' => count($rows), 'width' => $width];
    }

    /**
     * What the rows left would make: each column's label and the type its values suggest.
     *
     * @return list<array{index: int, column: string, label: string, data_type: string}>
     */
    public static function columns(string $token, ?string $sheet, array $skipRows, array $skipColumns): array
    {
        [$header, $body, $kept] = self::trimmed($token, $sheet, $skipRows, $skipColumns);

        return array_map(fn ($i, $index) => [
            'index' => $index, 'column' => 'c'.($i + 1), 'label' => self::label($header[$i] ?? null, $i),
            'data_type' => self::guess(array_column($body, $i)),
        ], array_keys($kept), $kept);
    }

    /**
     * Makes the table, puts the rows in and makes a datagrid over it. All or nothing: a value
     * that doesn't fit its column's type stops it before the table is made.
     *
     * @param  array{sheet?: ?string, skip_rows?: list<int>, skip_columns?: list<int>, name: string, code?: ?string, table?: ?string, types?: array<int|string, string>, labels?: array<int|string, string>}  $input
     */
    public static function create(string $token, array $input, ?int $userId = null): Meta
    {
        [$header, $body, $kept, $numbers] = self::trimmed($token, $input['sheet'] ?? null, $input['skip_rows'] ?? [], $input['skip_columns'] ?? []);
        $name = trim((string) ($input['name'] ?? ''));
        abort_if($name === '', 422, 'Give the new grid a name.');

        $slug = Str::limit(Str::snake(Str::ascii($name)) ?: 'sheet', 40, '');
        $table = strtolower(trim((string) ($input['table'] ?? '')) ?: self::PREFIX.$slug);
        abort_unless(str_starts_with($table, self::PREFIX) && preg_match('/^[a-z][a-z0-9_]{1,60}$/', $table), 422,
            'The table name must start with '.self::PREFIX.' and use only a–z, 0–9 and _.');
        abort_if(Schema::hasTable($table) || in_array($table, MetaDataController::BLOCKED_TABLES, true), 422, __('There\'s already a table ":table". Choose another name.', ['table' => $table]));
        $code = trim((string) ($input['code'] ?? '')) ?: $slug.'_grid';
        abort_if(Meta::where('code', $code)->exists(), 422, __('There\'s already a meta with the code ":code".', ['code' => $code]));

        // Each kept column: its label (the first row, or as edited) and its type.
        $columns = [];
        foreach ($kept as $i => $index) {
            $type = $input['types'][$index] ?? null;
            $type = in_array($type, self::TYPES, true) ? $type : self::guess(array_column($body, $i));
            $label = trim((string) ($input['labels'][$index] ?? '')) ?: self::label($header[$i] ?? null, $i);
            $columns[] = ['name' => 'c'.($i + 1), 'label' => mb_substr($label, 0, 255), 'data_type' => $type];
        }

        $records = [];
        foreach ($body as $r => $row) {
            $record = [];
            foreach ($columns as $i => $c) {
                try {
                    $record[$c['name']] = self::cast($row[$i] ?? null, $c['data_type']);
                } catch (RuntimeException $e) {
                    abort(422, 'Row '.$numbers[$r].", \"{$c['label']}\": {$e->getMessage()} Change the column's type or remove the row.");
                }
            }
            $records[] = $record + ['created_at' => now(), 'updated_at' => now()];
        }

        Schema::create($table, function (Blueprint $t) use ($columns) {
            $t->id();
            foreach ($columns as $c) {
                match ($c['data_type']) {
                    'integer' => $t->bigInteger($c['name'])->nullable(),
                    'decimal' => $t->decimal($c['name'], 20, 6)->nullable(),
                    'date' => $t->date($c['name'])->nullable(),
                    'datetime' => $t->dateTime($c['name'])->nullable(),
                    'boolean' => $t->boolean($c['name'])->nullable(),
                    'text' => $t->text($c['name'])->nullable(),
                    default => $t->string($c['name'], 255)->nullable(),
                };
            }
            $t->timestamps();
        });

        try {
            return DB::transaction(function () use ($token, $table, $records, $columns, $name, $code, $userId) {
                foreach (array_chunk($records, 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }

                $grid = Meta::create([
                    'code' => $code, 'name' => $name, 'type_id' => MetaType::where('code', 'datagrid')->value('id'),
                    'table_name' => $table, 'is_active' => true, 'created_by' => $userId, 'updated_by' => $userId,
                    'description' => 'Made from a spreadsheet.',
                ]);
                $order = 0;
                MetaParam::create(['main_meta_id' => $grid->id, 'order_number' => ++$order, 'field_path' => 'id', 'param_name' => 'id', 'label_name' => 'ID', 'data_type' => 'integer', 'is_primary' => true, 'is_show' => false]);
                foreach ($columns as $c) {
                    MetaParam::create([
                        'main_meta_id' => $grid->id, 'order_number' => ++$order, 'field_path' => $c['name'], 'param_name' => $c['name'],
                        'label_name' => $c['label'], 'data_type' => $c['data_type'], 'is_show' => true,
                        'is_criteria' => in_array($c['data_type'], ['string', 'text'], true),
                    ]);
                }
                Storage::disk('local')->delete(self::DIR.'/'.self::checkToken($token));

                return $grid;
            });
        } catch (Throwable $e) {
            Schema::dropIfExists($table);
            throw $e;
        }
    }

    /**
     * The sheet without the removed rows and columns (and rows left wholly empty): the
     * first row, the rest, the original index of each column kept and each row's number in the sheet.
     *
     * @return array{0: list<mixed>, 1: list<list<mixed>>, 2: list<int>, 3: list<int>}
     */
    private static function trimmed(string $token, ?string $sheet, array $skipRows, array $skipColumns): array
    {
        $rows = self::rows($token, $sheet);
        $skipRows = array_flip(array_map('intval', $skipRows));
        $skipColumns = array_flip(array_map('intval', $skipColumns));
        $width = min(self::MAX_COLUMNS, max(0, ...array_map('count', $rows ?: [[]])));
        $kept = array_values(array_filter(range(0, max(0, $width - 1)), fn ($i) => $width > 0 && ! isset($skipColumns[$i])));
        abort_if(! $kept, 422, 'Keep at least one column.');

        $left = [];
        $numbers = [];
        foreach ($rows as $r => $row) {
            if (isset($skipRows[$r])) {
                continue;
            }
            $cells = array_map(fn ($i) => $row[$i] ?? null, $kept);
            if (array_filter($cells, fn ($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $left[] = $cells;
                $numbers[] = $r + 1;
            }
        }
        abort_if(! $left, 422, 'No rows are left. Keep the header row at least.');

        array_shift($numbers);

        return [array_shift($left), $left, $kept, $numbers];
    }

    /**
     * A sheet's rows without the empty ones at the bottom and the empty columns on the right
     * (Excel often keeps formatted but empty cells there).
     *
     * @return list<list<mixed>>
     */
    private static function rows(string $token, ?string $sheet): array
    {
        try {
            $rows = Xlsx::read(self::path($token), $sheet, self::extension($token));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $filled = fn ($v) => $v !== null && trim((string) $v) !== '';
        $rows = array_map(function (array $row) use ($filled) {
            while ($row && ! $filled(end($row))) {
                array_pop($row);
            }

            return $row;
        }, $rows);
        while ($rows && end($rows) === []) {
            array_pop($rows);
        }

        return $rows;
    }

    private static function path(string $token): string
    {
        $file = self::DIR.'/'.self::checkToken($token);
        abort_unless(Storage::disk('local')->exists($file), 404, 'That upload is gone (they are kept for a day). Upload the file again.');

        return Storage::disk('local')->path($file);
    }

    private static function checkToken(string $token): string
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}\.(xlsx|csv)$/', $token) === 1, 404, 'Unknown upload.');

        return $token;
    }

    private static function extension(string $token): string
    {
        return pathinfo($token, PATHINFO_EXTENSION);
    }

    private static function label(mixed $value, int $i): string
    {
        $label = is_scalar($value) ? trim((string) $value) : '';

        return $label !== '' ? $label : 'Column '.($i + 1);
    }

    /** The type a column's values suggest: every one an integer, a number, a date, else text. */
    private static function guess(array $values): string
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && trim((string) $v) !== ''));
        if (! $values) {
            return 'string';
        }
        $all = fn (callable $test) => array_filter($values, fn ($v) => ! $test($v)) === [];
        if ($all(fn ($v) => is_int($v) || (is_string($v) && preg_match('/^-?\d{1,18}$/', trim($v))))) {
            return 'integer';
        }
        if ($all(fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric(str_replace([',', ' '], '', trim($v)))))) {
            return 'decimal';
        }
        if ($all(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)))) {
            return 'date';
        }
        if ($all(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', trim($v)))) {
            return 'datetime';
        }

        return max(array_map(fn ($v) => mb_strlen((string) $v), $values)) > 255 ? 'text' : 'string';
    }

    /** A cell's value for a column of the type; throws when it doesn't fit. */
    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $text = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

        return match ($type) {
            'integer' => preg_match('/^-?\d{1,18}$/', $text) ? (int) $text : (is_float($value) && floor($value) === $value ? (int) $value : throw new RuntimeException("\"{$text}\" is not a whole number.")),
            'decimal' => is_numeric($n = str_replace([',', ' '], '', $text)) ? $n : throw new RuntimeException("\"{$text}\" is not a number."),
            'date', 'datetime' => self::date($value, $text, $type === 'datetime'),
            'boolean' => match (mb_strtolower($text)) {
                '1', 'true', 'yes', 'y', 'тийм' => true,
                '0', 'false', 'no', 'n', 'үгүй' => false,
                default => throw new RuntimeException("\"{$text}\" is not yes or no."),
            },
            'text' => $text,
            default => mb_strlen($text) > 255 ? throw new RuntimeException('It is longer than 255 characters; make the column text.') : $text,
        };
    }

    /** A date from an Excel serial number (how Excel keeps them) or text. */
    private static function date(mixed $value, string $text, bool $withTime): string
    {
        if (is_int($value) || is_float($value)) {
            return Xlsx::fromSerial((float) $value, $withTime);
        }
        $time = strtotime($text);
        if ($time === false) {
            throw new RuntimeException("\"{$text}\" is not a date.");
        }

        return date($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', $time);
    }
}
