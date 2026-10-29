<?php

namespace App\Support;

use App\Models\User;

/**
 * :named parameters in a meta's SQL (query grids, get metas, charts), always bound and
 * never pasted into the query.
 */
class NamedSql
{
    /**
     * Named parameters every query can use: :user_id, :today and the user's custom session
     * values (e.g. :sessionemployeeid, see SessionValues).
     *
     * @return array<string, mixed>
     */
    public static function builtins(?User $user): array
    {
        return SessionValues::for($user) + ['user_id' => $user?->id, 'today' => now()->toDateString()];
    }

    /**
     * Turns :name parameters into ? placeholders with their values in order, so a name can be
     * used more than once. Text inside '...' and casts like ::date are left alone. A name
     * without a value (or with an empty one) is bound as null, so a query can say
     * (:status IS NULL OR status = :status).
     *
     * @return array{0: string, 1: array, 2: string[]} the SQL, its bindings, the names used
     */
    public static function bind(string $sql, array $values): array
    {
        return self::walk($sql, function (string $name, array &$bindings) use ($values) {
            $value = $values[$name] ?? null;
            $bindings[] = $value === '' ? null : $value;

            return '?';
        });
    }

    /**
     * Puts SQL in place of some :name parameters (name => SQL), the way bind finds them; the
     * others stay :named. A stored query used inside another gets its values this way.
     */
    public static function substitute(string $sql, array $replacements): string
    {
        return self::walk($sql, fn (string $name) => $replacements[$name] ?? ':'.$name)[0];
    }

    /**
     * Calls $replace for each :name outside '...' and casts, and puts in what it returns.
     *
     * @return array{0: string, 1: array, 2: string[]}
     */
    private static function walk(string $sql, callable $replace): array
    {
        $bindings = [];
        $names = [];
        $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue;
            }
            $parts[$i] = preg_replace_callback('/(?<![:\w]):([A-Za-z_]\w*)/', function ($m) use (&$bindings, &$names, $replace) {
                $names[] = $m[1];

                return $replace($m[1], $bindings);
            }, $part);
        }

        return [implode('', $parts), $bindings, array_values(array_unique($names))];
    }
}
