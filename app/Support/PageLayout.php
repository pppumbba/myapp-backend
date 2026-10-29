<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Support\Collection;

/**
 * A page's (or a widget_template's) layout of widgets. Its params place widget metas:
 * ref_meta_id is the widget meta, order_number its place, parent_id the section it sits in,
 * json_config.w its width on the 12-column grid. A widget meta's widget_id is its library
 * entry and its json_config the prop values. Widgets made in the page builder belong to their
 * page (their ref_meta_id is the page) and go when the page stops using them; a widget meta
 * made on its own can be placed on several pages. Saved by the page builder and the studio.
 */
class PageLayout
{
    public const WIDTHS = [3, 4, 6, 8, 9, 12];

    /** The meta types laid out from widgets. */
    public const TYPES = ['page', 'widget_template'];

    private const MAX_DEPTH = 3;

    /**
     * The page and the widgets the user may view, as a tree.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(Meta $page, ?User $user): array
    {
        $params = MetaParam::where('main_meta_id', $page->id)->where('is_show', true)->orderBy('order_number')->get();
        $widgets = Meta::with(['widget', 'links', 'notificationLink'])->whereIn('id', $params->pluck('ref_meta_id')->filter())->get()
            ->filter(fn (Meta $w) => Access::can($user, $w, 'view'))->keyBy('id');

        return self::tree($params, $widgets, null);
    }

    /**
     * Saves the whole layout; run it inside a transaction. items: [{param_id?, meta_id?,
     * widget_id, config, w, label?, name?, children?}]. An item with meta_id updates that widget
     * meta (on every page that shows it); without one, a widget meta is made for the page.
     * Params left out are removed, and so are this page's own widgets nothing uses anymore.
     *
     * @param  array<int, mixed>  $items
     */
    public static function save(Meta $page, array $items, User $user): void
    {
        $widgets = Widget::all()->keyBy('id');
        $widgetType = MetaType::where('code', 'widget')->value('id');
        abort_unless($widgetType, 422, 'The widget meta type is missing. Run the MetaTypeSeeder.');

        $existing = MetaParam::where('main_meta_id', $page->id)->get()->keyBy('id');
        $kept = [];
        $order = 0;

        $walk = function (array $items, ?int $parentId, int $depth) use (&$walk, &$kept, &$order, $page, $user, $existing, $widgets, $widgetType) {
            abort_if($depth > self::MAX_DEPTH, 422, 'Sections can only be nested '.self::MAX_DEPTH.' deep.');
            foreach ($items as $i => $item) {
                abort_unless(is_array($item), 422, 'Each item must be an object.');
                $widget = $widgets->get((int) ($item['widget_id'] ?? 0));
                abort_unless($widget, 422, __('Item :number has no widget from the library.', ['number' => $i]));
                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                $children = is_array($item['children'] ?? null) ? $item['children'] : [];
                abort_if($children && ! $widget->isContainer(), 422, __('A :name can\'t hold other widgets; put them in a section.', ['name' => $widget->name]));
                abort_if($widget->template_meta_id === $page->id, 422, __('The :name widget can\'t be one of its own parts.', ['name' => $widget->name]));

                $widgetMeta = self::widgetMeta($page, $item, $widget, $config, $widgetType, $user);

                $w = (int) ($item['w'] ?? 12);
                $values = [
                    'parent_id' => $parentId,
                    'order_number' => ++$order,
                    'ref_meta_id' => $widgetMeta->id,
                    'label_name' => is_string($item['label'] ?? null) ? mb_substr($item['label'], 0, 255) : null,
                    'is_show' => true,
                    'json_config' => ['w' => in_array($w, self::WIDTHS, true) ? $w : 12],
                ];
                $param = isset($item['param_id']) ? $existing->get((int) $item['param_id']) : null;
                if ($param && ! in_array($param->id, $kept, true)) {
                    $param->update($values);
                } else {
                    $param = MetaParam::create($values + [
                        'main_meta_id' => $page->id,
                        'field_path' => $widgetMeta->code,
                        'param_name' => $widgetMeta->code,
                        'data_type' => 'widget',
                    ]);
                }
                $kept[] = $param->id;

                $walk($children, $param->id, $depth + 1);
            }
        };
        $walk($items, null, 1);

        // Kept params already point at their new parents, so cascading deletes spare them.
        MetaParam::where('main_meta_id', $page->id)->whereNotIn('id', $kept)->get()->each->delete();

        // The page's own widgets that nothing places anymore.
        Meta::where('ref_meta_id', $page->id)->where('type_id', $widgetType)
            ->whereNotExists(fn ($q) => $q->from('meta_params')->whereColumn('meta_params.ref_meta_id', 'metas.id'))
            ->delete();
    }

    /**
     * The widget meta an item places: an existing one updated, or a new one owned by the page.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $config
     */
    private static function widgetMeta(Meta $page, array $item, Widget $widget, array $config, int $widgetType, User $user): Meta
    {
        $name = is_string($item['name'] ?? null) && trim($item['name']) !== '' ? mb_substr(trim($item['name']), 0, 255) : null;

        if (isset($item['meta_id'])) {
            $existing = Meta::whereKey((int) $item['meta_id'])->where('type_id', $widgetType)->first();
            abort_unless($existing, 422, 'A widget on this page no longer exists. Reload the page builder.');
            abort_if($existing->id === $page->id, 422, 'A page cannot contain itself.');
            $existing->update(['widget_id' => $widget->id, 'json_config' => $config, 'updated_by' => $user->id] + ($name ? ['name' => $name] : []));

            return $existing;
        }

        $base = $page->code.'_'.$widget->code;
        $code = $base;
        for ($n = 2; Meta::where('code', $code)->exists(); $n++) {
            $code = "{$base}_{$n}";
        }

        return Meta::create([
            'code' => $code,
            'name' => $name ?? $widget->name.' on '.$page->name,
            'type_id' => $widgetType,
            'widget_id' => $widget->id,
            'ref_meta_id' => $page->id,
            'json_config' => $config,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function tree(Collection $params, Collection $widgets, ?int $parentId): array
    {
        return $params->where('parent_id', $parentId)->values()
            ->filter(fn (MetaParam $p) => $widgets->has($p->ref_meta_id) && $widgets[$p->ref_meta_id]->widget)
            ->map(fn (MetaParam $p) => [
                'param_id' => $p->id,
                'label' => $p->label_name,
                'w' => (int) ($p->json_config['w'] ?? 12),
                'meta' => $widgets[$p->ref_meta_id]->only(['id', 'code', 'name', 'ref_meta_id', 'json_config', 'widget_id']),
                'widget' => $widgets[$p->ref_meta_id]->widget,
                'children' => self::tree($params, $widgets, $p->id),
            ])->values()->all();
    }
}
