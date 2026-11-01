<?php

namespace App\Support;

use App\Models\SessionValue;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Custom session values: per user, each session_values row's query runs with its param and
 * the first column of its first row becomes the value of its path, e.g.
 *   path sessionemployeeid, query "SELECT id FROM employees WHERE user_id = :userid", param userid.
 * A param may be userid (also user_id), email, or the path of an earlier row (by id), so
 * sessiondepartmentid can be looked up from sessionemployeeid.
 *
 * Values are worked out at login and cached until the next login, logout, or a change to the
 * definitions. They join NamedSql::builtins, so every query can use :sessionemployeeid, and
 * fill {sessionemployeeid} in default values (see fill()).
 */
class SessionValues
{
    private const VERSION_KEY = 'session_values:version';

    /** @return array<string, mixed> path => value for this user; [] without a user */
    public static function for(?User $user): array
    {
        if (! $user) {
            // Every name is there, empty, so a guest's request can't supply one (see NamedSql::builtins).
            return array_fill_keys(SessionValue::pluck('path')->all(), null);
        }

        return Cache::rememberForever(self::key($user), fn () => self::compute($user));
    }

    /** Works the user's values out again now, e.g. at login. */
    public static function refresh(User $user): array
    {
        Cache::forget(self::key($user));

        return self::for($user);
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::key($user));
    }

    /** After a definition changes: every user's values are worked out again on next use. */
    public static function forgetAll(): void
    {
        Cache::forever(self::VERSION_KEY, (int) Cache::get(self::VERSION_KEY, 0) + 1);
    }

    /**
     * Each row's value for this user, in order; a row whose query fails gets null.
     *
     * @return array<string, mixed>
     */
    public static function compute(User $user): array
    {
        $values = [];
        foreach (SessionValue::orderBy('id')->get() as $row) {
            $values[$row->path] = self::run($row->query, $row->param, $user, $values)['value'];
        }

        return $values;
    }

    /**
     * Runs one session query for a user, as a test from the builder would.
     *
     * @param  array<string, mixed>  $earlier  values of the rows before it
     * @return array{value: mixed, error: ?string}
     */
    public static function run(string $query, ?string $param, User $user, array $earlier = []): array
    {
        $sql = trim($query);
        if (! preg_match('/^(select|with)\b/i', $sql)) {
            return ['value' => null, 'error' => 'A session value is read with a SELECT (or WITH … SELECT) query.'];
        }

        $known = ['userid' => $user->id, 'user_id' => $user->id, 'email' => $user->email] + $earlier;
        $values = NamedSql::builtins(null) + ['user_id' => $user->id] + $earlier;
        if ($param !== null && $param !== '') {
            $values[$param] = self::lookup($known, $param);
        }

        try {
            [$sql, $bindings] = NamedSql::bind($sql, $values);
            // Read only: whatever the query tries to change is rolled back.
            DB::beginTransaction();
            try {
                $row = DB::selectOne($sql, $bindings);
            } finally {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            return ['value' => null, 'error' => $e->getMessage()];
        }

        $first = $row ? array_values((array) $row)[0] ?? null : null;

        return ['value' => $first, 'error' => null];
    }

    /** Fills {path} placeholders (any session value, plus {user_id} and {today}) in a default value. */
    public static function fill(?string $text, ?User $user): ?string
    {
        if ($text === null || ! str_contains($text, '{')) {
            return $text;
        }
        $known = NamedSql::builtins($user);

        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($known) {
            $value = self::lookup($known, $m[1], false);

            return $value === false ? $m[0] : (string) $value;
        }, $text);
    }

    /** A value by name, ignoring case; $missing when there is none. */
    private static function lookup(array $values, string $name, mixed $missing = null): mixed
    {
        if (array_key_exists($name, $values)) {
            return $values[$name];
        }
        foreach ($values as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return $missing;
    }

    private static function key(User $user): string
    {
        return 'session_values:'.(int) Cache::get(self::VERSION_KEY, 0).':'.$user->id;
    }
}
