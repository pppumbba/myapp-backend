<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\User;
use App\Models\WfmStatusLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies a meta with all its parts (see MetaVersions::parts: params, buttons, permissions,
 * workflow, process flow, links, tags, a page's own widgets) under new ids, either as a new
 * meta or over another one ("replace": it becomes the same, keeping its id, code and name).
 * References between the copied rows (a param's parent, a transition's statuses, a link's
 * param) point at the copies; references to anything else stay as they are.
 */
class MetaCopy
{
    /** Which column of each part's rows names the meta they belong to. */
    public const OWNER = [
        'wfm_statuses' => 'workflow_meta_id', 'wfm_transitions' => 'workflow_meta_id', 'params' => 'main_meta_id',
        'actions' => 'grid_meta_id', 'permissions' => 'meta_id', 'process_steps' => 'main_process_id',
        'process_transitions' => 'main_process_id', 'links' => 'src_meta_id', 'notification' => 'notification_id', 'tags' => 'meta_id', 'translations' => 'meta_id',
    ];

    /** A new meta like the source, under a new code and name. */
    public static function copy(Meta $source, string $code, string $name, ?User $user): Meta
    {
        abort_if(Meta::where('code', $code)->exists(), 422, __('There\'s already a meta with the code ":code".', ['code' => $code]));

        return DB::transaction(function () use ($source, $code, $name, $user) {
            $row = self::metaRow($source->id);
            $row['code'] = $code;
            $row['name'] = $name;
            $row['created_by'] = $row['updated_by'] = $user?->id;
            $row['created_at'] = $row['updated_at'] = now();
            $id = DB::table('metas')->insertGetId($row);
            self::copyParts($source->id, $id, $user);
            Translations::forget($id);

            return Meta::findOrFail($id);
        });
    }

    /**
     * Makes the target the same as the source: its settings and all its parts, keeping its id,
     * code and name, so menus, links and records that point at it still do. The target's
     * state is kept as a version first. A workflow status records have been in stays.
     *
     * @return list<string> what could not be done as asked
     */
    public static function replace(Meta $target, Meta $source, ?User $user): array
    {
        abort_if($target->id === $source->id, 422, 'Pick another meta to copy from.');
        abort_if((int) $source->ref_meta_id === $target->id && $source->type?->code === 'widget', 422, 'A page can’t become one of its own widgets.');
        MetaVersions::saveIfChanged($target->id, $user, "Before replacing it with {$source->name}");

        return DB::transaction(function () use ($target, $source, $user) {
            $warnings = self::clear($target->id);
            $row = self::metaRow($source->id);
            unset($row['code'], $row['name'], $row['created_by'], $row['created_at']);
            $row['updated_by'] = $user?->id;
            $row['updated_at'] = now();
            DB::table('metas')->where('id', $target->id)->update($row);
            self::copyParts($source->id, $target->id, $user);
            Translations::forget($target->id);

            return $warnings;
        });
    }

    /** The meta's row as it is stored, without its id. */
    private static function metaRow(int $id): array
    {
        $row = (array) DB::table('metas')->find($id);
        unset($row['id']);

        return $row;
    }

    /** Copies every part of one meta onto another, the copies pointing at each other. */
    private static function copyParts(int $from, int $to, ?User $user): void
    {
        $parts = MetaVersions::parts();
        // Old id => new id, per table, for the references between copied rows.
        $maps = [];
        foreach ($parts as $name => $part) {
            $table = $part['table'];
            if ($name === 'widgets') {
                // A page's own widget metas are metas themselves: copied whole, pointing at the copy.
                foreach (($part['scope'])(DB::table($table), $from)->orderBy('id')->get() as $widget) {
                    $copy = self::copy(Meta::findOrFail($widget->id), self::freeCode($widget->code), $widget->name, $user);
                    DB::table('metas')->where('id', $copy->id)->update(['ref_meta_id' => $to]);
                    $maps['metas'][$widget->id] = $copy->id;
                }

                continue;
            }

            $rows = ($part['scope'])(DB::table($table), $from)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $columns = Schema::getColumnListing($table);
            $selfLinks = [];
            foreach ($rows as $row) {
                $old = $row['id'];
                unset($row['id']);
                if (isset(self::OWNER[$name])) {
                    $row[self::OWNER[$name]] = $to;
                }
                foreach (array_keys($part['refs']) as $column) {
                    $target = $part['refs'][$column][0];
                    if (isset($row[$column], $maps[$target][$row[$column]])) {
                        $row[$column] = $maps[$target][$row[$column]];
                    }
                }
                // A translation follows its row to the copy; the meta's own name is new, so its translations aren't.
                if ($name === 'translations') {
                    $row['row_id'] = $row['table_name'] === 'metas'
                        ? ((int) $row['row_id'] === $from && $row['field'] !== 'name' ? $to : null)
                        : ($maps[$row['table_name']][$row['row_id']] ?? null);
                    if ($row['row_id'] === null) {
                        continue;
                    }
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
                $maps[$table][$old] = DB::table($table)->insertGetId(array_intersect_key($row, array_flip($columns)));
            }
            // Rows of the part pointing at each other (a param's parent, a transition's neighbours).
            foreach ($selfLinks as $old => $links) {
                $update = [];
                foreach ($links as $column => $value) {
                    $update[$column] = $maps[$table][$value] ?? null;
                }
                DB::table($table)->where('id', $maps[$table][$old])->update($update);
            }
        }
    }

    /**
     * Removes a meta's parts, last first, so nothing points at what goes. Its own widget metas
     * go with theirs.
     *
     * @return list<string>
     */
    public static function clear(int $id): array
    {
        $warnings = [];
        foreach (array_reverse(MetaVersions::parts(), true) as $name => $part) {
            $ids = ($part['scope'])(DB::table($part['table']), $id)->pluck('id');
            if ($name === 'widgets') {
                foreach ($ids as $widget) {
                    self::clear($widget);
                }
            }
            if ($name === 'wfm_statuses') {
                $used = WfmStatusLog::whereIn('status_id', $ids)->pluck('status_id')
                    ->merge(WfmStatusLog::whereIn('prev_status_id', $ids)->pluck('prev_status_id'))->unique();
                foreach (DB::table('wfm_status')->whereIn('id', $used)->pluck('name') as $status) {
                    $warnings[] = "The status \"{$status}\" stays: records have been in it.";
                }
                $ids = $ids->diff($used);
            }
            if ($part['self']) {
                DB::table($part['table'])->whereIn('id', $ids)->update(array_fill_keys($part['self'], null));
            }
            DB::table($part['table'])->whereIn('id', $ids)->delete();
        }

        return $warnings;
    }

    /** The code with _copy (and a number when taken) added. */
    public static function freeCode(string $code): string
    {
        $base = mb_substr($code, 0, 90).'_copy';
        $free = $base;
        for ($n = 2; Meta::where('code', $free)->exists(); $n++) {
            $free = "{$base}_{$n}";
        }

        return $free;
    }
}
