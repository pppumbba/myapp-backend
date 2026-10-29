<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Support\Access;
use App\Support\Autonumber;
use App\Support\MetaVersions;
use App\Support\PageLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The builder studio's one-step saves. structure: a meta's params (an app's menu, a datagrid's
 * columns, a form's fields, a tab set's tabs) sent as one tree. app: an app with the screens
 * it is building — new ones are made, and their params, buttons or page layout saved — in one
 * transaction. A page's layout is PageLayout's, as in the page builder.
 */
class StructureController extends Controller
{
    /** The meta types whose params the studio lays out. */
    public const TYPES = ['app', 'datagrid', 'form', 'tabs'];

    /** The screens an app's studio makes and edits beside its menu. */
    public const SCREEN_TYPES = ['datagrid', 'form', 'tabs', 'page', 'dashboard'];

    private const MAX_DEPTH = 4;

    /** The param columns an item may set; the rest of a kept param stays as it was. */
    private const FIELDS = [
        'field_path', 'param_name', 'column_path', 'label_name', 'data_type', 'data_mask', 'render_type', 'icon_cls',
        'ref_meta_id', 'is_primary', 'is_show', 'is_required', 'is_criteria', 'is_unique', 'is_select', 'is_label',
        'default_value', 'min_value', 'max_value', 'column_width', 'json_config', 'display_field', 'value_field',
    ];

    private const BOOLEANS = ['is_primary', 'is_show', 'is_required', 'is_criteria', 'is_unique', 'is_select', 'is_label'];

    /** The meta columns the studio edits. */
    private const META_FIELDS = ['name', 'label_name', 'description', 'table_name', 'is_active'];

    /**
     * PUT /metas/{meta}/structure — items: [{id?, ...param fields, children?}], in order.
     * An item with the id of one of this meta's params updates it; without one, a param is made.
     * Params left out are removed. Returns the params as a tree, like GET /metas/{meta}/params.
     */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_unless(in_array($meta->type?->code, self::TYPES, true), 422, __('The studio lays out apps, datagrids, forms and tab sets only.'));

        $data = $request->validate([
            'items' => ['present', 'array'],
        ]);

        DB::transaction(fn () => $this->saveParams($meta, $data['items'], []));

        return response()->json($this->paramTree($meta));
    }

    /**
     * PUT /metas/{meta}/app — an app and the screens built inside it, at once:
     *   meta?:  the app's own fields
     *   items:  its menu; an item opens ref_meta_id, or ref_key: a screen in metas
     *   metas:  [{key, id?, type, name, code?, ...fields, items, actions?}] — without id one is
     *           made. items are its params (ref_key works there too), or a page's widgets as
     *           PUT /metas/{id}/page takes them; a datagrid's actions are its buttons (form_key: a
     *           form in metas), left-out ones removed.
     * Screens not sent are not touched. Returns the app, its menu and each screen sent, by key.
     */
    public function saveApp(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        abort_unless($meta->type?->code === 'app', 422, __('Only an app is built with its screens.'));

        $data = $request->validate([
            'meta' => ['sometimes', 'array'],
            'meta.name' => ['sometimes', 'string', 'max:255'],
            'items' => ['present', 'array'],
            'metas' => ['sometimes', 'array'],
            'metas.*.key' => ['required', 'string', 'max:50', 'distinct'],
            'metas.*.id' => ['nullable', 'integer'],
            'metas.*.type' => ['required_without:metas.*.id', 'nullable', 'in:'.implode(',', self::SCREEN_TYPES)],
            'metas.*.name' => ['required_without:metas.*.id', 'nullable', 'string', 'max:255'],
            'metas.*.code' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'metas.*.label_name' => ['nullable', 'string', 'max:255'],
            'metas.*.table_name' => ['nullable', 'string', 'max:191'],
            'metas.*.description' => ['nullable', 'string'],
            'metas.*.is_active' => ['sometimes', 'boolean'],
            'metas.*.items' => ['present', 'array'],
            'metas.*.actions' => ['sometimes', 'array'],
        ]);
        $screens = $data['metas'] ?? [];
        $user = $request->user();

        // How each existing screen was, kept as a version once the save went through.
        $before = [];
        foreach ($screens as $s) {
            if (! empty($s['id'])) {
                $screen = Meta::with('type')->find($s['id']);
                abort_unless($screen && in_array($screen->type?->code, self::SCREEN_TYPES, true), 422, __('":name" is not a datagrid, form, tab set, page or dashboard of this studio.', ['name' => $s['name'] ?? $s['id']]));
                abort_if($screen->id === $meta->id, 422, __('An app cannot be one of its own screens.'));
                $before[] = MetaVersions::snapshot($screen->id);
            }
        }

        $made = DB::transaction(function () use ($meta, $data, $screens, $user) {
            if (! empty($data['meta'])) {
                $meta->update(array_intersect_key($data['meta'], array_flip(self::META_FIELDS)) + ['updated_by' => $user->id]);
            }

            // First every screen exists, so menus, tabs, lookups and buttons can point at new ones.
            $keys = [];
            $metas = [];
            $codes = [];
            foreach ($screens as $s) {
                $fields = array_intersect_key($s, array_flip(self::META_FIELDS));
                if (! empty($s['id'])) {
                    $screen = Meta::findOrFail($s['id']);
                    if ($fields) {
                        $screen->update($fields + ['updated_by' => $user->id]);
                    }
                } else {
                    $type = MetaType::where('code', $s['type'])->firstOrFail();
                    $screen = Meta::create($fields + [
                        'code' => $this->code($s['code'] ?? null, $s['name'], $s['type'], $codes),
                        'type_id' => $type->id,
                        'is_active' => true,
                        'created_by' => $user->id,
                        'updated_by' => $user->id,
                    ]);
                }
                $keys[$s['key']] = $screen->id;
                $metas[$s['key']] = $screen;
            }

            foreach ($screens as $s) {
                $screen = $metas[$s['key']];
                if ($this->isPage($screen)) {
                    abort_if(array_key_exists('actions', $s), 422, __('Only a datagrid has buttons.'));
                    PageLayout::save($screen, $s['items'], $user);

                    continue;
                }
                $this->saveParams($screen, $s['items'], $keys);
                if (array_key_exists('actions', $s)) {
                    abort_unless($screen->type()->value('code') === 'datagrid', 422, __('Only a datagrid has buttons.'));
                    $this->saveActions($screen, $s['actions'], $keys);
                }
            }

            $this->saveParams($meta, $data['items'], $keys);

            return $metas;
        });

        foreach ($before as $snapshot) {
            try {
                MetaVersions::keepBefore($snapshot, $user);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'meta' => $meta->fresh()->load('type'),
            'items' => $this->paramTree($meta),
            'metas' => collect($made)->map(fn (Meta $m, string $key) => [
                'key' => $key,
                'meta' => $m->fresh()->load('type'),
                'items' => $this->isPage($m) ? PageLayout::items($m, $user) : $this->paramTree($m),
                'actions' => $this->actionsOf($m),
            ])->values(),
        ]);
    }

    /**
     * Replaces a meta's params with the tree: kept by id, made without one, the rest removed.
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, int>  $keys  screen keys of this save → their meta ids
     */
    private function saveParams(Meta $meta, array $items, array $keys): void
    {
        $existing = MetaParam::where('main_meta_id', $meta->id)->get()->keyBy('id');
        $kept = [];
        $order = 0;

        $walk = function (array $items, ?int $parentId, int $depth) use (&$walk, &$kept, &$order, $meta, $existing, $keys) {
            abort_if($depth > self::MAX_DEPTH, 422, __('Items can only be nested :depth deep.', ['depth' => self::MAX_DEPTH]));
            foreach ($items as $item) {
                abort_unless(is_array($item), 422, __('Each item must be an object.'));
                $values = $this->values($item, $meta, $keys);
                $values['parent_id'] = $parentId;
                $values['order_number'] = ++$order;

                $param = isset($item['id']) ? $existing->get((int) $item['id']) : null;
                if ($param && ! in_array($param->id, $kept, true)) {
                    $param->update($values);
                } else {
                    $param = MetaParam::create($values + [
                        'main_meta_id' => $meta->id,
                        'data_type' => 'string',
                        'is_show' => true,
                    ]);
                }
                $kept[] = $param->id;

                $walk(is_array($item['children'] ?? null) ? $item['children'] : [], $param->id, $depth + 1);
            }
        };
        $walk($items, null, 1);

        // Kept params already point at their new parents, so cascading deletes spare them.
        MetaParam::where('main_meta_id', $meta->id)->whereNotIn('id', $kept)->get()->each->delete();
    }

    /**
     * One item's param columns, checked.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $keys
     * @return array<string, mixed>
     */
    private function values(array $item, Meta $meta, array $keys): array
    {
        $values = array_intersect_key($item, array_flip(self::FIELDS));

        // A screen made in this same save.
        if (! empty($item['ref_key'])) {
            abort_unless(isset($keys[$item['ref_key']]), 422, __('An item opens a screen that is not part of this save.'));
            $values['ref_meta_id'] = $keys[$item['ref_key']];
        }

        foreach ($values as $key => $value) {
            if (in_array($key, self::BOOLEANS, true)) {
                $values[$key] = (bool) $value;
            } elseif ($key === 'json_config') {
                abort_unless($value === null || is_array($value), 422, __('Settings must be an object.'));
            } elseif ($key === 'ref_meta_id') {
                $values[$key] = $value ? (int) $value : null;
            } else {
                abort_unless($value === null || is_scalar($value), 422, __('The :field of an item must be text.', ['field' => $key]));
                $values[$key] = $value === null ? null : mb_substr((string) $value, 0, 255);
            }
        }

        $name = trim((string) ($values['field_path'] ?? $values['param_name'] ?? ''));
        abort_if($name === '' && ! isset($item['id']), 422, __('Every item needs a field name.'));
        if ($name !== '') {
            $values['field_path'] ??= $name;
            $values['param_name'] ??= $name;
        }

        if (! empty($values['ref_meta_id'])) {
            abort_if($values['ref_meta_id'] === $meta->id, 422, __('An item cannot open the meta it belongs to.'));
            abort_unless(Meta::whereKey($values['ref_meta_id'])->exists(), 422, __('An item refers to a meta that no longer exists.'));
        }

        $problem = Autonumber::problem($values['json_config']['autonumber'] ?? null);
        abort_if($problem !== null, 422, $problem ?? '');

        return $values;
    }

    /**
     * Replaces a datagrid's buttons, in order: kept by id, made without one, the rest removed.
     *
     * @param  array<int, mixed>  $actions
     * @param  array<string, int>  $keys
     */
    private function saveActions(Meta $grid, array $actions, array $keys): void
    {
        $existing = MetaAction::where('grid_meta_id', $grid->id)->get()->keyBy('id');
        $kept = [];

        foreach (array_values($actions) as $i => $a) {
            abort_unless(is_array($a), 422, __('Each button must be an object.'));
            $kind = $a['action'] ?? null;
            abort_unless(in_array($kind, MetaAction::ACTIONS, true), 422, __('A button does something unknown.'));
            $label = trim((string) ($a['label'] ?? ''));
            abort_if($label === '', 422, __('Every button needs a label.'));

            $formId = ! empty($a['form_key']) ? ($keys[$a['form_key']] ?? null) : (isset($a['form_meta_id']) ? (int) $a['form_meta_id'] : null);
            abort_unless($formId, 422, __('The button ":label" needs a form.', ['label' => $label]));
            $formType = Meta::with('type')->find($formId)?->type?->code;
            abort_unless($formType, 422, __('The button ":label" opens a meta that no longer exists.', ['label' => $label]));
            $wanted = match ($kind) {
                'import' => 'excel_import',
                'stage_query' => 'stage_query',
                default => 'form',
            };
            abort_unless($formType === $wanted, 422, __('The button ":label" needs a :type.', ['label' => $label, 'type' => $wanted]));

            $getId = ! empty($a['get_meta_id']) ? (int) $a['get_meta_id'] : null;
            abort_if($getId && ! Meta::whereKey($getId)->exists(), 422, __('The button ":label" uses a get that no longer exists.', ['label' => $label]));
            $config = $a['json_config'] ?? null;
            abort_unless($config === null || is_array($config), 422, __('Settings must be an object.'));

            $values = [
                'action' => $kind,
                'label' => mb_substr($label, 0, 255),
                'icon_cls' => isset($a['icon_cls']) && $a['icon_cls'] !== '' ? mb_substr((string) $a['icon_cls'], 0, 50) : null,
                'form_meta_id' => $formId,
                'get_meta_id' => $getId,
                'needs_row' => (bool) ($a['needs_row'] ?? false),
                'show_in_toolbar' => (bool) ($a['show_in_toolbar'] ?? true),
                'show_in_row' => (bool) ($a['show_in_row'] ?? false),
                'order_number' => $i + 1,
                'json_config' => $config ?: null,
            ];

            $action = isset($a['id']) ? $existing->get((int) $a['id']) : null;
            if ($action && ! in_array($action->id, $kept, true)) {
                $action->update($values);
            } else {
                $action = MetaAction::create($values + ['grid_meta_id' => $grid->id]);
            }
            $kept[] = $action->id;
        }

        MetaAction::where('grid_meta_id', $grid->id)->whereNotIn('id', $kept)->delete();
    }

    /**
     * A free meta code: the one asked for (it must be free), else made from the name.
     *
     * @param  array<int, string>  $taken  codes given out earlier in this save
     */
    private function code(?string $asked, string $name, string $type, array &$taken): string
    {
        if ($asked !== null && trim($asked) !== '') {
            abort_if(in_array($asked, $taken, true) || Meta::where('code', $asked)->exists(), 422, __('The code ":code" is already used.', ['code' => $asked]));

            return $taken[] = $asked;
        }

        $base = Str::slug(Str::ascii($name), '_') ?: 'screen';
        $base = Str::limit($base, 80, '').'_'.($type === 'datagrid' ? 'grid' : $type);
        $code = $base;
        for ($i = 2; in_array($code, $taken, true) || Meta::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $taken[] = $code;
    }

    private function isPage(Meta $meta): bool
    {
        return $meta->type()->value('code') === 'page';
    }

    private function actionsOf(Meta $meta)
    {
        return MetaAction::where('grid_meta_id', $meta->id)
            ->with(['form:id,code,name', 'get:id,code,name'])
            ->orderBy('order_number')->orderBy('id')
            ->get();
    }

    private function paramTree(Meta $meta)
    {
        $rows = MetaParam::where('main_meta_id', $meta->id)->with('links')->orderBy('order_number')->get();

        return $this->tree($rows, null);
    }

    /** The rows under $parentId with their children, recursively. */
    private function tree($rows, ?int $parentId)
    {
        return $rows->where('parent_id', $parentId)->values()->map(function (MetaParam $row) use ($rows) {
            $row->setAttribute('children', $this->tree($rows, $row->id));

            return $row;
        });
    }
}
