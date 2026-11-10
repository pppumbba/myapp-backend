<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset audits in the Assets app (EamDemoSeeder calls this; so does migration
 * 2026_12_03_000034, which makes the tables): a physical count of one location. The audit's
 * lines are the assets seen there, picked many at once with the lines' Choose button (on the
 * phone: hold a card and slide to tick a span), each marked found or missing, with its
 * condition.
 *
 *   Assets ─ Asset audits   eam_audits_grid → eam_audit_form (lines: eam_audit_line_form)
 *
 * Idempotent: metas matched by code, their params and actions rebuilt; the menu entry is
 * matched by its field path, last under Assets. Whoever sees work orders sees audits, with
 * the same rights.
 */
class EamAuditSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $types = [];

    public function run(): void
    {
        $assets = Meta::where('code', 'eam_assets_lookup')->first();
        $locations = Meta::where('code', 'eam_locations_grid')->first();
        if (! Schema::hasTable('eam_audits') || ! $assets || ! $locations) {
            return;
        }
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () use ($assets, $locations) {
            $lookup = fn (Meta $grid) => ['is_select' => true, 'ref_meta_id' => $grid->id, 'render_type' => 'lookup', 'display_field' => 'name'];
            $options = fn (array $list, string $default) => ['render_type' => 'select', 'default_value' => $default, 'json_config' => ['options' => $list]];

            $line = $this->meta('eam_audit_line_form', 'Audit line', 'form', 'eam_audit_lines', 'An asset counted in an audit: found or missing, and in what condition.');
            $this->params($line, [
                ['id', 'ID', 'integer', $this->key()],
                ['audit_id', 'Audit', 'integer', ['is_show' => false]],
                ['asset_id', 'Asset', 'integer', ['is_required' => true, 'is_label' => true] + $lookup($assets)],
                ['found', 'Found', 'boolean', ['default_value' => '1']],
                ['condition', 'Condition', 'string', $options(['good', 'fair', 'poor', 'broken'], 'good')],
                ['note', 'Note', 'string'],
            ]);

            $form = $this->meta('eam_audit_form', 'Asset audit', 'form', 'eam_audits', 'A count of the assets in one location. Choose picks many assets at once as lines.');
            $this->params($form, [
                ['id', 'ID', 'integer', $this->key()],
                ['audit_no', 'Audit no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'AUD-{yy}{MM}{seq:3}']]]],
                ['audit_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
                ['location_id', 'Location', 'integer', ['is_required' => true, 'is_label' => true] + $lookup($locations)],
                ['auditor', 'Auditor', 'string'],
                ['status', 'Status', 'string', ['is_required' => true] + $options(['open', 'done'], 'open')],
                ['lines', 'Assets counted', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'audit_id']]],
                ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ]);

            $grid = $this->meta('eam_audits_grid', 'Asset audits', 'datagrid', 'eam_audits', 'Counts of the assets in a location, open and done.', ['filter_layout' => 'sidebar']);
            $this->params($grid, [
                ['id', 'ID', 'integer', $this->key()],
                ['audit_no', 'Audit no', 'string', ['is_criteria' => true]],
                ['audit_date', 'Date', 'date'],
                ['location_id', 'Location', 'integer', ['is_criteria' => true, 'is_label' => true] + $lookup($locations)],
                ['auditor', 'Auditor', 'string', ['is_criteria' => true]],
                ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['open', 'done']]]],
            ]);
            MetaAction::where('grid_meta_id', $grid->id)->delete();
            foreach ([['add', 'Add', 'plus', false], ['edit', 'Edit', 'edit', true], ['delete', 'Delete', 'trash', true]] as $i => [$action, $label, $icon, $needsRow]) {
                MetaAction::create([
                    'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $form->id,
                    'needs_row' => $needsRow, 'show_in_row' => $needsRow, 'order_number' => $i + 1,
                    'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
                ]);
            }

            $this->menu($grid);
            $this->access([$grid, $form, $line]);
        });
    }

    /** "Asset audits", last under the Assets menu group. */
    private function menu(Meta $grid): void
    {
        $app = Meta::where('code', 'eam_app')->first();
        $group = $app ? MetaParam::where('main_meta_id', $app->id)->whereNull('parent_id')->where('field_path', 'assets')->first() : null;
        if (! $group) {
            return;
        }
        MetaParam::updateOrCreate(['main_meta_id' => $app->id, 'field_path' => 'asset_audits'], [
            'parent_id' => $group->id, 'param_name' => 'asset_audits', 'label_name' => 'Asset audits', 'data_type' => 'string',
            'ref_meta_id' => $grid->id, 'is_show' => true,
            'order_number' => (int) MetaParam::where('parent_id', $group->id)->where('field_path', '!=', 'asset_audits')->max('order_number') + 1,
        ]);
    }

    /**
     * The work orders grid's permission rows, copied onto these metas.
     *
     * @param  list<Meta>  $metas
     */
    private function access(array $metas): void
    {
        $like = DB::table('meta_permissions')->whereIn('meta_id', Meta::where('code', 'eam_work_orders_grid')->pluck('id'))->get();
        foreach ($like as $p) {
            foreach ($metas as $meta) {
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $p->role_id], [
                    'can_view' => $p->can_view, 'can_create' => $p->can_create, 'can_edit' => $p->can_edit, 'can_delete' => $p->can_delete,
                ]);
            }
        }
    }

    private function meta(string $code, string $name, string $type, string $table, string $description, ?array $config = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }

    /**
     * Replaces a meta's params.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3?: array<string, mixed>}>  $rows
     */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create(($row[3] ?? []) + [
                'main_meta_id' => $meta->id, 'order_number' => $i + 1,
                'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => $type, 'is_show' => true,
            ]);
        }
    }

    /** @return array<string, bool> */
    private function key(): array
    {
        return ['is_primary' => true, 'is_show' => false];
    }
}
