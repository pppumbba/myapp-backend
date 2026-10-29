<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metas as parents and children, for handing out permissions: an app holds its menus and the
 * screens they open, a tabs meta its tabs, a grid the forms its buttons open, a form its line
 * forms and lookups, a page or dashboard what its widgets show. A child is whatever the meta
 * uses (MetaTransfer::uses); a page's widgets are passed through to what they show.
 *
 * Worked out for every meta at once, so it is cached until a meta or one of its parts changes
 * (each change stamps metas.updated_at, see MetaVersions::keepBefore).
 */
class MetaTree
{
    /**
     * children: meta id => the metas it uses; menus: app id => its menu as a tree of items,
     * each {label, meta_id (null for a group), children}.
     *
     * @return array{children: array<int, list<int>>, menus: array<int, list<array{label: string, meta_id: ?int, children: list<array<string, mixed>>}>>}
     */
    public static function get(): array
    {
        $key = 'meta-tree:'.implode(':', [
            DB::table('metas')->count(), DB::table('metas')->max('updated_at'),
            DB::table('meta_params')->max('updated_at'), DB::table('meta_actions')->max('updated_at'),
        ]);

        return Cache::remember($key, now()->addDay(), fn () => self::build());
    }

    private static function build(): array
    {
        $widgets = DB::table('metas')->whereIn('type_id', DB::table('meta_types')->where('code', 'widget')->select('id'))->pluck('id')->flip();
        $uses = [];
        $usesOf = function (int $id) use (&$uses): array {
            return $uses[$id] ??= MetaTransfer::uses($id);
        };
        // A widget is not a meta anyone opens: what it shows is.
        $through = function (int $id, array $seen = []) use (&$through, $usesOf, $widgets): array {
            $out = [];
            foreach ($usesOf($id) as $used) {
                if (isset($widgets[$used])) {
                    if (! isset($seen[$used])) {
                        $out = [...$out, ...$through($used, $seen + [$used => true])];
                    }
                } else {
                    $out[] = $used;
                }
            }

            return $out;
        };

        $children = [];
        foreach (DB::table('metas')->orderBy('id')->pluck('id') as $id) {
            if (! isset($widgets[$id])) {
                $children[$id] = array_values(array_unique(array_diff($through($id), [$id])));
            }
        }

        $menus = [];
        $apps = DB::table('metas')->whereIn('type_id', DB::table('meta_types')->where('code', 'app')->select('id'))->pluck('id');
        $params = DB::table('meta_params')->whereIn('main_meta_id', $apps)->orderBy('order_number')->orderBy('id')
            ->get(['id', 'main_meta_id', 'parent_id', 'ref_meta_id', 'label_name', 'param_name'])->groupBy('main_meta_id');
        foreach ($apps as $app) {
            $menus[$app] = self::menu(($params[$app] ?? collect())->groupBy(fn ($p) => (int) $p->parent_id), 0);
        }

        return ['children' => $children, 'menus' => $menus];
    }

    /** An app's menu items under one parent (0: the top), each with the items under it. */
    private static function menu($byParent, int $parent, int $depth = 0): array
    {
        if ($depth > 5) {
            return [];
        }

        return ($byParent[$parent] ?? collect())->map(fn ($p) => [
            'label' => $p->label_name ?: $p->param_name,
            'meta_id' => $p->ref_meta_id ? (int) $p->ref_meta_id : null,
            'children' => self::menu($byParent, (int) $p->id, $depth + 1),
        ])->filter(fn ($item) => $item['meta_id'] || $item['children'])->values()->all();
    }
}
