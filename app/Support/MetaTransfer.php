<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Metas moved between installations as a file: export writes metas with all their parts
 * (see MetaVersions::parts) as JSON, import puts them into this database.
 *
 * Ids differ from one database to another, so the file names what its rows point at by
 * code: metas, meta types, roles and widgets by code, tags by name, a field by its meta's
 * code and field path, a workflow status by its workflow's code and its own code. On
 * import, rows of the file point at each other's new rows; anything else is looked up by
 * that name. What isn't found is cleared, or the row is left out when it needs it, and
 * each is reported. Meta ids inside json_config (menu items, open_meta_id…) are moved the
 * same way. A page's own widget metas always go with it.
 */
class MetaTransfer
{
    public const FORMAT = 'myapp-metas';

    public const VERSION = 1;

    /** What a meta row points at: column => [table, needed]. */
    private const META_REFS = ['type_id' => ['meta_types', true], 'ref_meta_id' => ['metas', false], 'widget_id' => ['widgets', false]];

    /** Part rows whose json_config may hold meta ids. */
    private const JSON_PARTS = ['params', 'actions'];

    /**
     * The metas as a file's contents. With $withUsed, the metas they use too (lookups, forms
     * of buttons, process steps, linked metas…), and the ones those use, and so on.
     *
     * @param  list<int>  $ids
     */
    public static function export(array $ids, bool $withUsed = false): array
    {
        $order = [];
        $queue = array_values(array_unique(array_map('intval', $ids)));
        while ($queue) {
            $id = array_shift($queue);
            if (isset($order[$id]) || ! DB::table('metas')->where('id', $id)->exists()) {
                continue;
            }
            $order[$id] = true;
            $parts = MetaVersions::parts();
            // A page's own widgets always go with it, right after it.
            $widgets = ($parts['widgets']['scope'])(DB::table('metas'), $id)->orderBy('id')->pluck('id')->all();
            $queue = [...$widgets, ...($withUsed ? self::uses($id) : []), ...$queue];
        }

        $metas = [];
        $refs = [];
        foreach (array_keys($order) as $id) {
            $snapshot = MetaVersions::snapshot($id);
            unset($snapshot['parts']['widgets']);
            $metas[] = $snapshot;
            self::collectRefs($snapshot, $refs);
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => config('app.url'),
            'metas' => $metas,
            'refs' => self::names($refs),
        ];
    }

    /**
     * Puts a file's metas into this database. A meta whose code is taken is replaced
     * (keeping its id; its state is saved as a version first), skipped, or added as a copy
     * under a free code. With $dryRun nothing is kept: it says what would happen.
     *
     * @param  'replace'|'skip'|'copy'  $existing
     * @return array{metas: list<array{code: string, name: string, type: ?string, action: string, id: ?int, version_id?: ?int}>, warnings: list<string>}
     */
    public static function import(array $package, string $existing, ?User $user, bool $dryRun = false, string $note = 'Before importing it from a file'): array
    {
        abort_unless(($package['format'] ?? null) === self::FORMAT && is_array($package['metas'] ?? null), 422, __('This is not a metas file.'));
        abort_if((int) ($package['version'] ?? 0) > self::VERSION, 422, __('This metas file was made by a newer version of the app.'));

        $state = new class
        {
            /** @var array<string, array<int, int>> old id => new id, per table */
            public array $maps = [];

            public array $refs = [];

            /** @var list<string> */
            public array $warnings = [];
        };
        $state->refs = $package['refs'] ?? [];
        $result = [];

        DB::beginTransaction();
        try {
            // The metas' own rows first, so parts can point at any of them.
            $imported = [];
            foreach ($package['metas'] as $snapshot) {
                $row = $snapshot['meta'] ?? null;
                if (! is_array($row) || ! isset($row['id'], $row['code'])) {
                    continue;
                }
                $old = (int) $row['id'];
                $found = DB::table('metas')->where('code', $row['code'])->value('id');
                $action = $found === null ? 'create' : $existing;
                $type = self::lookup($state, 'meta_types', $row['type_id'] ?? null);
                $entry = ['code' => $row['code'], 'name' => $row['name'] ?? $row['code'], 'type' => $state->refs['meta_types'][$row['type_id'] ?? ''] ?? null, 'action' => $action, 'id' => $found];

                if ($action === 'skip') {
                    $state->maps['metas'][$old] = $found;
                    $result[] = $entry;

                    continue;
                }
                if ($type === null) {
                    $state->warnings[] = "{$row['code']} is left out: its meta type ({$entry['type']}) isn't in this app.";
                    $entry['action'] = 'left out';
                    $result[] = $entry;

                    continue;
                }

                $values = array_intersect_key($row, array_flip(Schema::getColumnListing('metas')));
                unset($values['id']);
                $values['type_id'] = $type;
                $values['ref_meta_id'] = $values['widget_id'] = null;
                $values['updated_by'] = $user?->id;
                $values['updated_at'] = now();
                if ($action === 'replace') {
                    // The version it is put back to when the import is undone (see Patches::rollback).
                    $entry['version_id'] = (MetaVersions::saveIfChanged($found, $user, $note) ?? MetaVersions::matching($found))?->id;
                    array_push($state->warnings, ...MetaCopy::clear($found));
                    unset($values['created_by'], $values['created_at']);
                    DB::table('metas')->where('id', $found)->update($values);
                    $new = $found;
                } else {
                    if ($action === 'copy') {
                        $values['code'] = $entry['code'] = MetaCopy::freeCode($row['code']);
                    }
                    $values['created_by'] = $user?->id;
                    $values['created_at'] = now();
                    $new = DB::table('metas')->insertGetId($values);
                }
                $entry['id'] = $new;
                $state->maps['metas'][$old] = $new;
                $imported[$old] = $snapshot;
                $result[] = $entry;

                $table = $row['table_name'] ?? null;
                if ($table && preg_match('/^\w+$/', $table) && ! Schema::hasTable($table)) {
                    $state->warnings[] = "{$values['code']} reads the table {$table}, which isn't in this database.";
                }
            }

            // Then what the metas point at, now that all of them have ids here.
            foreach ($imported as $old => $snapshot) {
                $row = $snapshot['meta'];
                $update = [];
                foreach (['ref_meta_id', 'widget_id'] as $column) {
                    $update[$column] = self::ref($state, self::META_REFS[$column][0], $row[$column] ?? null, "{$row['code']}: {$column}");
                }
                if (isset($row['json_config'])) {
                    $update['json_config'] = self::moveJson($state, $row['json_config'], $row['code']);
                }
                DB::table('metas')->where('id', $state->maps['metas'][$old])->update($update);
            }

            // Their parts, one kind at a time across all metas, in MetaVersions' order, so a
            // link can point at another meta's field or a transition at its statuses.
            foreach (MetaVersions::parts() as $name => $part) {
                if ($name === 'widgets') {
                    continue;
                }
                $table = $part['table'];
                $columns = Schema::getColumnListing($table);
                $selfLinks = [];
                foreach ($imported as $oldMeta => $snapshot) {
                    $to = $state->maps['metas'][$oldMeta];
                    $code = $snapshot['meta']['code'];
                    foreach ($snapshot['parts'][$name] ?? [] as $row) {
                        $old = $row['id'];
                        unset($row['id']);
                        if (isset(MetaCopy::OWNER[$name])) {
                            $row[MetaCopy::OWNER[$name]] = $to;
                        }
                        foreach ($part['refs'] as $column => [$target, $needed]) {
                            if (($row[$column] ?? null) === null) {
                                continue;
                            }
                            $row[$column] = self::lookup($state, $target, $row[$column]);
                            if ($row[$column] === null && $needed) {
                                $state->warnings[] = "{$code}: ".self::label($name, $row)." is left out: what it points at ({$column}) isn't in this app.";

                                continue 2;
                            }
                            if ($row[$column] === null) {
                                $state->warnings[] = "{$code}: ".self::label($name, $row)." lost its {$column}: what it points at isn't in this app.";
                            }
                        }
                        if ($name === 'translations') {
                            $row['row_id'] = $state->maps[$row['table_name']][$row['row_id']] ?? null;
                            if ($row['row_id'] === null) {
                                continue;
                            }
                        }
                        if (in_array($name, self::JSON_PARTS, true) && isset($row['json_config'])) {
                            $row['json_config'] = self::moveJson($state, $row['json_config'], $code);
                        }
                        foreach ($part['self'] as $column) {
                            if (($row[$column] ?? null) !== null) {
                                $selfLinks[$old][$column] = $row[$column];
                            }
                            $row[$column] = null;
                        }
                        foreach (['created_at', 'updated_at'] as $stamp) {
                            if (in_array($stamp, $columns, true)) {
                                $row[$stamp] = now();
                            }
                        }
                        $values = array_intersect_key($row, array_flip($columns));
                        // A replaced workflow keeps the statuses records have been in: the file's status of that code becomes it.
                        $kept = $name === 'wfm_statuses'
                            ? DB::table($table)->where('workflow_meta_id', $to)->where('code', $row['code'] ?? null)->value('id')
                            : null;
                        if ($kept) {
                            DB::table($table)->where('id', $kept)->update($values);
                            $state->maps[$table][$old] = $kept;
                        } else {
                            $state->maps[$table][$old] = DB::table($table)->insertGetId($values);
                        }
                    }
                }
                foreach ($selfLinks as $old => $links) {
                    $update = [];
                    foreach ($links as $column => $value) {
                        $update[$column] = $state->maps[$table][$value] ?? null;
                    }
                    DB::table($table)->where('id', $state->maps[$table][$old])->update($update);
                }
            }

            if ($dryRun) {
                throw new RuntimeException('dry run');
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            if (! $dryRun || $e->getMessage() !== 'dry run') {
                throw $e;
            }
        }

        foreach ($result as $entry) {
            if (! $dryRun && $entry['id']) {
                Translations::forget($entry['id']);
            }
        }

        return ['metas' => $result, 'warnings' => array_values(array_unique($state->warnings))];
    }

    /**
     * The metas this one uses: what its params look up, its buttons open, its steps run,
     * its links point at, and meta ids in its settings.
     *
     * @return list<int>
     */
    public static function uses(int $id): array
    {
        $snapshot = MetaVersions::snapshot($id);
        if ($snapshot === null) {
            return [];
        }
        $refs = [];
        self::collectRefs($snapshot, $refs);
        $used = array_keys($refs['metas'] ?? []);
        // The page a widget is on is not something it uses.
        $type = DB::table('meta_types')->where('id', $snapshot['meta']['type_id'])->value('code');
        if ($type === 'widget') {
            $used = array_diff($used, [(int) $snapshot['meta']['ref_meta_id']]);
        }

        return array_values(array_diff($used, [$id]));
    }

    /** Every id the snapshot points at, per table (table => [id => true]). */
    private static function collectRefs(array $snapshot, array &$refs): void
    {
        $add = function (string $table, mixed $id) use (&$refs) {
            if ($id !== null && $id !== '' && is_numeric($id)) {
                $refs[$table][(int) $id] = true;
            }
        };
        foreach (self::META_REFS as $column => [$table]) {
            $add($table, $snapshot['meta'][$column] ?? null);
        }
        foreach (self::jsonMetaIds($snapshot['meta']['json_config'] ?? null) as $id) {
            $add('metas', $id);
        }
        foreach (MetaVersions::parts() as $name => $part) {
            foreach ($snapshot['parts'][$name] ?? [] as $row) {
                foreach (array_keys($part['refs']) as $column) {
                    $add($part['refs'][$column][0], $row[$column] ?? null);
                }
                if (in_array($name, self::JSON_PARTS, true)) {
                    foreach (self::jsonMetaIds($row['json_config'] ?? null) as $id) {
                        $add('metas', $id);
                    }
                }
            }
        }
    }

    /** The names the file uses for the ids it points at (see the class comment). */
    private static function names(array $refs): array
    {
        $ids = fn (string $table) => array_keys($refs[$table] ?? []);

        return [
            'metas' => DB::table('metas')->whereIn('id', $ids('metas'))->pluck('code', 'id')->all(),
            'meta_types' => DB::table('meta_types')->whereIn('id', $ids('meta_types'))->pluck('code', 'id')->all(),
            'roles' => DB::table('roles')->whereIn('id', $ids('roles'))->pluck('code', 'id')->all(),
            'widgets' => DB::table('widgets')->whereIn('id', $ids('widgets'))->pluck('code', 'id')->all(),
            'tags' => DB::table('tags')->whereIn('id', $ids('tags'))->get(['id', 'name', 'color'])
                ->mapWithKeys(fn ($t) => [$t->id => ['name' => $t->name, 'color' => $t->color]])->all(),
            'meta_params' => DB::table('meta_params')->join('metas', 'metas.id', '=', 'meta_params.main_meta_id')
                ->whereIn('meta_params.id', $ids('meta_params'))->get(['meta_params.id', 'metas.code', 'meta_params.field_path'])
                ->mapWithKeys(fn ($p) => [$p->id => [$p->code, $p->field_path]])->all(),
            'wfm_status' => DB::table('wfm_status')->join('metas', 'metas.id', '=', 'wfm_status.workflow_meta_id')
                ->whereIn('wfm_status.id', $ids('wfm_status'))->get(['wfm_status.id', 'metas.code as workflow', 'wfm_status.code', 'wfm_status.name'])
                ->mapWithKeys(fn ($s) => [$s->id => [$s->workflow, $s->code, $s->name]])->all(),
        ];
    }

    /** A row's reference as an id here, with a warning when it can't be found. */
    private static function ref(object $state, string $table, mixed $old, string $what): ?int
    {
        if ($old === null) {
            return null;
        }
        $id = self::lookup($state, $table, $old);
        if ($id === null) {
            $state->warnings[] = "{$what} is cleared: what it points at isn't in this app.";
        }

        return $id;
    }

    /** The id here of what the file's id stood for: a row the file brought, or one found by its name. */
    private static function lookup(object $state, string $table, mixed $old): ?int
    {
        if ($old === null || $old === '') {
            return null;
        }
        if (isset($state->maps[$table][$old])) {
            return $state->maps[$table][$old];
        }
        $name = $state->refs[$table][$old] ?? null;
        if ($name === null) {
            return null;
        }
        $metaId = fn (string $code) => DB::table('metas')->where('code', $code)->value('id');
        $id = match ($table) {
            'metas', 'meta_types', 'roles', 'widgets' => DB::table($table)->where('code', $name)->value('id'),
            'tags' => DB::table('tags')->where('name', $name['name'])->value('id')
                ?? DB::table('tags')->insertGetId(['name' => $name['name'], 'color' => $name['color'] ?? null, 'created_at' => now(), 'updated_at' => now()]),
            'meta_params' => DB::table('meta_params')->where('main_meta_id', $metaId($name[0]))->where('field_path', $name[1])->orderBy('id')->value('id'),
            'wfm_status' => DB::table('wfm_status')->where('workflow_meta_id', $metaId($name[0]))
                ->where(fn ($q) => $name[1] !== null ? $q->where('code', $name[1]) : $q->where('name', $name[2]))->value('id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /** Meta ids in a json_config moved to their ids here; one not found is dropped. */
    private static function moveJson(object $state, mixed $json, string $code): mixed
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (! is_array($decoded)) {
            return $json;
        }
        $walk = function (array $value) use (&$walk, $state, $code) {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $walk($item);
                } elseif (self::isMetaKey($key) && is_numeric($item)) {
                    $value[$key] = self::ref($state, 'metas', (int) $item, "{$code}: {$key} in its settings");
                }
            }

            return $value;
        };

        return json_encode($walk($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<int> */
    private static function jsonMetaIds(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        $ids = [];
        $walk = function (mixed $value) use (&$walk, &$ids) {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $walk($item);
                } elseif (self::isMetaKey($key) && is_numeric($item)) {
                    $ids[] = (int) $item;
                }
            }
        };
        $walk($decoded);

        return $ids;
    }

    private static function isMetaKey(mixed $key): bool
    {
        return is_string($key) && ($key === 'meta_id' || str_ends_with($key, '_meta_id'));
    }

    /** How a part's row reads in a warning. */
    private static function label(string $part, array $row): string
    {
        return match ($part) {
            'params' => 'field '.($row['field_path'] ?? '?'),
            'actions' => 'button '.(($row['label'] ?? null) ?: ($row['action'] ?? '?')),
            'permissions' => 'access for a role',
            'wfm_statuses' => 'status '.($row['name'] ?? '?'),
            'wfm_transitions' => 'a workflow transition',
            'process_steps' => 'a process step',
            'process_transitions', 'process_behaviours' => 'a process flow line',
            'links' => ($row['type'] ?? 'a').' link',
            'notification' => 'what it watches',
            'tags' => 'a tag',
            default => 'a row',
        };
    }
}
