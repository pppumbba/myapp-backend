<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use Illuminate\Support\Collection;

/**
 * A datagrid can reuse another: its ref_meta_id names that datagrid. It then reads the same
 * records (the base's table_name or query_string, and the base's workflow) and starts from
 * the base's columns. Its own params change them by field_path: a param with a base column's
 * field_path replaces it (label, order, is_show to hide it, filter, footer aggregate), and a
 * new field_path adds a column of the same records. A grid without buttons uses its base's.
 * Bases can reuse bases, up to DEPTH grids.
 */
class GridReuse
{
    public const DEPTH = 5;

    /**
     * The grids this one reuses, nearest first; empty when it reuses none. A grid that comes
     * round to itself, or a chain longer than DEPTH, stops with 422.
     *
     * @return list<Meta>
     */
    public static function bases(Meta $meta): array
    {
        $bases = [];
        $seen = [$meta->id];
        $current = $meta;
        while (($next = self::base($current)) !== null) {
            abort_if(in_array($next->id, $seen, true), 422, __('":name" reuses a grid that reuses it back.', ['name' => $meta->name]));
            abort_if(count($bases) >= self::DEPTH, 422, 'A grid can reuse grids only '.self::DEPTH.' deep.');
            $bases[] = $next;
            $seen[] = $next->id;
            $current = $next;
        }

        return $bases;
    }

    /** The grid whose records this one shows: the last base, or itself. */
    public static function origin(Meta $meta): Meta
    {
        $bases = self::bases($meta);

        return $bases ? $bases[count($bases) - 1] : $meta;
    }

    /**
     * The params this grid shows with: the origin's, changed by each grid on the way back to
     * this one (a later grid's param replaces an earlier one's with the same field_path),
     * in order_number order.
     *
     * @return Collection<int, MetaParam>
     */
    public static function params(Meta $meta): Collection
    {
        $chain = array_reverse([$meta, ...self::bases($meta)]);
        $byField = [];
        foreach ($chain as $grid) {
            foreach (MetaParam::where('main_meta_id', $grid->id)->whereNull('parent_id')->with('links')->orderBy('order_number')->get() as $param) {
                $byField[$param->field_path] = $param;
            }
        }

        return collect(array_values($byField))->sortBy('order_number')->values();
    }

    /** The grid whose buttons this one uses: the nearest in the chain that has any. */
    public static function actionsOf(Meta $meta): Meta
    {
        foreach ([$meta, ...self::bases($meta)] as $grid) {
            if (MetaAction::where('grid_meta_id', $grid->id)->exists()) {
                return $grid;
            }
        }

        return $meta;
    }

    /** Whether this grid reuses another. */
    public static function reuses(Meta $meta): bool
    {
        return self::base($meta) !== null;
    }

    /** The datagrid this datagrid's ref_meta_id names, if it names one. */
    private static function base(Meta $meta): ?Meta
    {
        $meta->loadMissing('type');
        if ($meta->type?->code !== 'datagrid' || ! $meta->ref_meta_id) {
            return null;
        }
        $ref = Meta::with('type')->find($meta->ref_meta_id);

        return $ref?->type?->code === 'datagrid' ? $ref : null;
    }
}
