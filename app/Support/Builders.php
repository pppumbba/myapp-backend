<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaType;
use App\Models\User;

/**
 * What a builder who is not an admin may put into the metas they make (config/builders.php):
 * only some meta types, no system tables or tables of another database, no SQL, external
 * services or form scripts, and links only to metas they may open themselves, so a meta of
 * theirs never reads what they couldn't. Admins are never checked. Each check stops with 422.
 */
class Builders
{
    /** A meta's columns and settings: its type, table, admin-only columns and the metas it links to. */
    public static function checkMeta(User $user, array $data, ?Meta $current = null): void
    {
        if ($user->isAdmin()) {
            return;
        }
        foreach (config('builders.admin_columns') as $column) {
            $value = $data[$column] ?? null;
            if ($value !== null && $value !== '' && $value !== false && $value !== []) {
                self::refuse($column, __('Only administrators can set :field.', ['field' => str_replace('_', ' ', $column)]));
            }
        }
        if (array_key_exists('type_id', $data) || ! $current) {
            $type = MetaType::whereKey($data['type_id'] ?? null)->value('code');
            if (! in_array($type, config('builders.types'), true)) {
                self::refuse('type_id', __('Builders cannot make this type of meta.'));
            }
        }
        if (! empty($data['table_name'])) {
            self::checkTable((string) $data['table_name']);
        }
        self::checkLinks($user, array_filter([$data['ref_meta_id'] ?? null, ...self::linkedIds($data['json_config'] ?? null)]), 'ref_meta_id');
    }

    /** A param's columns and settings: no SQL expression, and links only to metas the builder may open. */
    public static function checkParam(User $user, array $data): void
    {
        if ($user->isAdmin()) {
            return;
        }
        if (! empty($data['expression_string'])) {
            self::refuse('expression_string', __('Only administrators can set an expression.'));
        }
        self::checkLinks($user, array_filter([$data['ref_meta_id'] ?? null, ...self::linkedIds($data['json_config'] ?? null)]), 'ref_meta_id');
    }

    /** A button: never a stage query, and its form and get only metas the builder may open. */
    public static function checkAction(User $user, array $data): void
    {
        if ($user->isAdmin()) {
            return;
        }
        if (($data['action'] ?? null) === 'stage_query') {
            self::refuse('action', __('Only administrators can add a button that runs a stage query.'));
        }
        self::checkLinks($user, array_filter([$data['form_meta_id'] ?? null, $data['get_meta_id'] ?? null]), 'form_meta_id');
    }

    /** A table a builder's meta may use: a plain name in this database, not a system table. */
    public static function checkTable(string $table): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            self::refuse('table_name', __('A table name has only letters, digits and underscores.'));
        }
        $name = strtolower($table);
        $system = in_array($name, config('builders.system_tables'), true)
            || collect(config('builders.system_prefixes'))->contains(fn (string $prefix) => str_starts_with($name, $prefix));
        if ($system) {
            self::refuse('table_name', __('The table ":table" is for the system; builders cannot use it.', ['table' => $table]));
        }
    }

    /**
     * Every meta id a json_config names: any key ending in meta_id, at any depth
     * (drilldown.meta_id, rows.meta_id, print_meta_id, link_meta_id, ...).
     *
     * @return list<int>
     */
    public static function linkedIds(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }
        $ids = [];
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                array_push($ids, ...self::linkedIds($value));
            } elseif (is_string($key) && str_ends_with($key, 'meta_id') && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    /** @param  array<int, int|string>  $ids */
    private static function checkLinks(User $user, array $ids, string $field): void
    {
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (! Access::can($user, $id, 'view')) {
                self::refuse($field, __('You can only link to metas you may open yourself.'));
            }
        }
    }

    private static function refuse(string $field, string $message): never
    {
        abort(response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422));
    }
}
