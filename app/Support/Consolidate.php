<?php

namespace App\Support;

use App\Models\MetaParam;
use Illuminate\Support\Collection;

/**
 * A consolidate button merges several records into one new record of its form, lines
 * included: e.g. picked purchase requests become one order whose lines add up the quantity
 * of each item. The rows to merge are the picked grid rows, or every row the button's get
 * meta gives for each of them (a get that joins the records' lines gives a row per line).
 *
 * Each form field takes its value from the rows' column of the same name, by its group_type:
 * sum, avg (to 2 decimals), min, max, count (of values), concat (the different values, joined with ", "),
 * first or last (of the values, blanks skipped). Null means first. On lines, "group" fields
 * decide which rows become one line: rows with the same values in all of them merge, the
 * other fields by their own group_type. Lines without group fields get a line per row.
 */
class Consolidate
{
    /**
     * The merged record: the form's fields, and each detail's lines under its field (a
     * one-to-one detail: one object). Fields the rows don't have are left out, so the form's
     * defaults stay; keys and a line's foreign key never come from the rows.
     *
     * @param  array<int, array>  $details  as MetaDataController::details() gives them
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function merge(Collection $params, array $columns, array $details, array $rows): array
    {
        $record = self::mergeRows(self::fields($params, $columns), $rows);

        foreach ($details as $detail) {
            $fields = self::fields($detail['source']['params'], $detail['source']['columns'], [$detail['foreign_key']]);
            if (! self::usesAny($fields, $rows)) {
                continue;
            }
            $record[$detail['field']] = $detail['one'] ? (object) self::mergeRows($fields, $rows) : self::lines($fields, $rows);
        }

        return $record;
    }

    /** The rows as lines: one per row, or one per set of group values. */
    private static function lines(Collection $fields, array $rows): array
    {
        $groups = $fields->filter(fn (MetaParam $p) => $p->group_type === 'group');
        if ($groups->isEmpty()) {
            return array_map(fn (array $row) => self::mergeRows($fields, [$row]), $rows);
        }

        $buckets = [];
        foreach ($rows as $row) {
            $key = json_encode($groups->map(fn (MetaParam $p) => self::text(self::value($row, $p->field_path)))->values()->all());
            $buckets[$key][] = $row;
        }

        return array_values(array_map(fn (array $bucket) => self::mergeRows($fields, $bucket), $buckets));
    }

    /** One record from rows: each field by its group_type, those the rows don't have left out. */
    private static function mergeRows(Collection $fields, array $rows): array
    {
        $out = [];
        foreach ($fields as $param) {
            if (! self::usesAny(collect([$param]), $rows)) {
                continue;
            }
            $values = array_map(fn (array $row) => self::value($row, $param->field_path), $rows);
            $out[$param->field_path] = Formula::cast($param, self::aggregate($param->group_type ?? 'first', $values));
        }

        return $out;
    }

    /** One value from many, by a group_type. */
    public static function aggregate(string $type, array $values): mixed
    {
        $given = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
        $numbers = array_map(fn ($v) => is_bool($v) ? (int) $v : $v + 0, array_filter($given, fn ($v) => is_numeric($v) || is_bool($v)));

        return match ($type) {
            'sum' => $numbers ? array_sum($numbers) : null,
            'avg' => $numbers ? round(array_sum($numbers) / count($numbers), 2) : null,
            'min', 'max' => $given === [] ? null : (count($numbers) === count($given) ? $type($numbers) : $type(array_map(fn ($v) => self::text($v), $given))),
            'count' => count($given),
            'concat' => implode(', ', array_values(array_unique(array_map(fn ($v) => self::text($v), $given)))),
            'last' => $given === [] ? null : $given[count($given) - 1],
            default => $given[0] ?? null,
        };
    }

    /** The fields rows can fill: the form's columns, never its key or $skip. */
    private static function fields(Collection $params, array $columns, array $skip = []): Collection
    {
        return $params->filter(fn (MetaParam $p) => ! $p->is_primary
            && in_array($p->field_path, $columns, true)
            && ! in_array($p->field_path, $skip, true)
            && Formula::of($p) === null
            && ! in_array($p->data_type, ['detail', 'subgrid', 'singlefile', 'multifile', 'document', 'signature'], true))->values();
    }

    private static function usesAny(Collection $fields, array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($fields as $param) {
                if (self::has($row, $param->field_path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A row's column by name, as written or ignoring case. */
    private static function has(array $row, string $name): bool
    {
        return array_key_exists($name, $row) || self::key($row, $name) !== null;
    }

    private static function value(array $row, string $name): mixed
    {
        $key = array_key_exists($name, $row) ? $name : self::key($row, $name);

        return $key === null ? null : $row[$key];
    }

    private static function key(array $row, string $name): ?string
    {
        foreach (array_keys($row) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $key;
            }
        }

        return null;
    }

    private static function text(mixed $v): string
    {
        return is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
    }
}
