<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\Role;
use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items and services as one list, by category (tables: 2026_12_03_000006). The category's
 * flags decide how an item behaves:
 *
 *   is_service  listed under Services; a new item of it starts with the category's unit (hour)
 *   is_asset    listed under Assets
 *   is_job      listed under Jobs; a new sales order with a job item gets a follow-up task
 *               per job line (crm_create_job_tasks, in the order form's process flow)
 *
 *   - Item categories (crm_item_categories_grid / crm_item_category_form): in CRM Setup and
 *     Procurement's master data.
 *   - Items & services (crm_items_tabs over crm_items_grid): All, Goods, Services, Jobs,
 *     Assets; Add in a tab starts with that tab's category.
 *   - Sales lines and price list lines pick one item (an item or a service); the price
 *     finder and the copy steps no longer know service_id.
 *   - Procurement's item form and grid pick the category too (the text column follows it).
 *   - crm_service_form and crm_services_grid are deleted (their table is gone).
 *
 * Idempotent: metas matched by code, params matched by field_path or rebuilt.
 */
return new class extends Migration
{
    private const UNITS = ['pcs', 'box', 'kg', 'l', 'm', 'pack', 'set', 'hour', 'day', 'month', 'visit', 'job'];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> existing metas by code */
    private array $x = [];

    /** @var array<string, Meta> metas made or changed here */
    private array $m = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('proc_item_categories')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        $this->x = Meta::where('code', 'like', 'crm\_%')->orWhereIn('code', ['proc_item_form', 'proc_items_grid', 'proc_master_data'])->get()->keyBy('code')->all();
        foreach (['crm_app', 'crm_setup', 'crm_item_form', 'crm_items_grid', 'crm_price_lookup', 'crm_price_list_form', 'crm_price_list_line_form', 'crm_sales_order_form', 'crm_copy_quotation_lines', 'crm_copy_order_lines'] as $code) {
            if (! isset($this->x[$code])) {
                return;
            }
        }

        DB::transaction(function () {
            $this->categories();
            $this->items();
            $this->lines();
            $this->jobTasks();
            $this->procurement();
            $this->removeServices();
            $this->access();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        // The service screens come back only by running 2026_12_03_000001 again.
        DB::transaction(function () {
            $ids = DB::table('metas')->whereIn('code', ['crm_item_category_form', 'crm_item_categories_grid', 'crm_items_tabs', 'crm_create_job_tasks'])->pluck('id');
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('id', $ids)->delete();
        });
    }

    // ---------------------------------------------------------------- categories

    private function categories(): void
    {
        $form = $this->meta('crm_item_category_form', 'Item category form', 'form', 'proc_item_categories', 'A category of items; its flags decide whether its items are services, fixed assets or jobs.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true]],
            ['is_service', 'Service (work, not goods)', 'boolean', ['default_value' => '0']],
            ['is_asset', 'Fixed asset', 'boolean', ['default_value' => '0']],
            ['is_job', 'Job (a sale makes a follow-up task)', 'boolean', ['default_value' => '0']],
            ['default_unit', 'Default unit', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::UNITS]]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('crm_item_categories_grid', 'Item categories', 'datagrid', 'proc_item_categories', 'Categories of items and services, and how their items behave.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['is_service', 'Service', 'boolean', ['is_criteria' => true]],
            ['is_asset', 'Asset', 'boolean', ['is_criteria' => true]],
            ['is_job', 'Job', 'boolean', ['is_criteria' => true]],
            ['default_unit', 'Default unit', 'string'],
            ['notes', 'Notes', 'text'],
        ]);

        $this->row($this->x['crm_setup'], null, 'item_categories', 'Item categories', $grid);
        if (isset($this->x['proc_master_data'])) {
            $this->row($this->x['proc_master_data'], null, 'item_categories', 'Item categories', $grid);
        }
    }

    // ---------------------------------------------------------------- items and services

    private function items(): void
    {
        $categories = $this->m['crm_item_categories_grid'];
        $category = ['is_select' => true, 'ref_meta_id' => $categories->id, 'render_type' => 'lookup', 'display_field' => 'name'];

        $form = $this->x['crm_item_form'];
        $form->update(['name' => 'Item / service form', 'description' => 'A product or a service we buy or sell (shared with Procurement); its category decides how it behaves.']);
        $this->m['crm_item_form'] = $form;
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true, 'is_label' => true]],
            ['category_id', 'Category', 'integer', ['is_required' => true, 'json_config' => ['fill' => ['unit' => 'default_unit']]] + $category],
            ['unit', 'Unit', 'string', ['render_type' => 'select', 'default_value' => 'pcs', 'json_config' => ['options' => self::UNITS]]],
            ['unit_price', 'Base price / rate', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['description', 'Description', 'text', ['render_type' => 'textarea']],
        ]);

        // Read through a query so the category's flags are columns the tabs can keep to.
        $grid = $this->x['crm_items_grid'];
        $grid->update([
            'name' => 'Items & services',
            'description' => 'Products and services, shared with Procurement; the category says whether an item is a service, an asset or a job.',
            'query_string' => <<<'SQL'
                SELECT i.id, i.code, i.name, i.category_id,
                       COALESCE(c.is_service, 0) AS is_service, COALESCE(c.is_asset, 0) AS is_asset, COALESCE(c.is_job, 0) AS is_job,
                       i.unit, i.unit_price, i.is_active
                FROM proc_items i
                LEFT JOIN proc_item_categories c ON c.id = i.category_id
                SQL,
            'json_config' => ['filter_layout' => 'sidebar'],
        ]);
        $this->m['crm_items_grid'] = $grid;
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['category_id', 'Category', 'integer', ['is_criteria' => true] + $category],
            ['is_service', 'Service', 'boolean', ['is_criteria' => true]],
            ['is_asset', 'Asset', 'boolean', ['is_criteria' => true]],
            ['is_job', 'Job', 'boolean', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['unit_price', 'Base price / rate', 'decimal', ['render_type' => 'money']],
            ['is_active', 'Status', 'boolean', ['is_criteria' => true, 'render_type' => 'active']],
        ]);

        $start = DB::table('proc_item_categories')->whereIn('code', ['SERVICE', 'JOB', 'ASSET'])->pluck('id', 'code');
        $tab = fn (string $field, string $label, array $fixed, ?string $category = null) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => array_filter([
            'fixed' => $fixed ?: null,
            'defaults' => $category && isset($start[$category]) ? ['category_id' => (string) $start[$category]] : null,
        ]) ?: null]];
        $tabs = $this->meta('crm_items_tabs', 'Items & services', 'tabs', null, 'Items by how they behave: goods, services, jobs and fixed assets.');
        $this->params($tabs, [
            $tab('all', 'All', []),
            $tab('goods', 'Goods', ['is_service' => '0']),
            $tab('services', 'Services', ['is_service' => '1'], 'SERVICE'),
            $tab('jobs', 'Jobs', ['is_job' => '1'], 'JOB'),
            $tab('assets', 'Assets', ['is_asset' => '1'], 'ASSET'),
        ]);

        // The menu: one entry for both, and the old Services entry goes.
        $catalogue = MetaParam::where('main_meta_id', $this->x['crm_app']->id)->whereNull('parent_id')->where('field_path', 'catalogue')->first();
        if ($catalogue) {
            MetaParam::where('parent_id', $catalogue->id)->where('field_path', 'services')->delete();
            $this->row($this->x['crm_app'], $catalogue->id, 'items', 'Items & services', $tabs);
        }
    }

    // ---------------------------------------------------------------- lines, price finder, copying

    private function lines(): void
    {
        $items = $this->m['crm_items_grid'];
        foreach (['crm_quotation_line_form', 'crm_sales_order_line_form', 'crm_invoice_line_form'] as $code) {
            if (! isset($this->x[$code])) {
                continue;
            }
            $id = $this->x[$code]->id;
            MetaParam::where('main_meta_id', $id)->where('field_path', 'service_id')->delete();
            MetaParam::where('main_meta_id', $id)->where('field_path', 'item_id')->first()?->update([
                'label_name' => 'Item / service', 'ref_meta_id' => $items->id,
                'json_config' => ['fill' => ['unit_price' => 'unit_price', 'description' => 'name']],
            ]);
            MetaParam::where('main_meta_id', $id)->where('field_path', 'price_line_id')->first()?->update([
                'json_config' => ['fill' => ['item_id' => 'item_id', 'description' => 'item_name', 'unit_price' => 'price']],
            ]);
        }

        $lineForm = $this->x['crm_price_list_line_form'];
        MetaParam::where('main_meta_id', $lineForm->id)->where('field_path', 'service_id')->delete();
        MetaParam::where('main_meta_id', $lineForm->id)->where('field_path', 'item_id')->first()?->update([
            'label_name' => 'Item / service', 'is_required' => true, 'ref_meta_id' => $items->id, 'json_config' => ['fill' => ['price' => 'unit_price']],
        ]);
        $this->x['crm_price_list_form']->update(['before_save_expression_string' => <<<'JS'
            if (lines('lines').some((l) => !l.item_id)) {
              error('Each line needs an item or a service.');
            }
            if (get('valid_from') && get('valid_to') && get('valid_to') < get('valid_from')) {
              error('"Valid to" is before "Valid from".');
            }
            JS]);

        $finder = $this->x['crm_price_lookup'];
        $finder->update(['query_string' => <<<'SQL'
            SELECT l.id, l.price_list_id, l.item_id, i.name AS item_name, i.unit, i.category_id,
                   COALESCE(c.is_service, 0) AS is_service,
                   l.price, l.min_qty, p.name AS price_list, p.currency, p.customer_category_id,
                   p.valid_from, p.valid_to,
                   CONCAT(i.name, ' · ', p.name, ' · ', FORMAT(l.price, 0), ' ', p.currency) AS label
            FROM crm_price_list_lines l
            JOIN crm_price_lists p ON p.id = l.price_list_id
            JOIN proc_items i ON i.id = l.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            WHERE p.is_active = 1 AND i.is_active = 1
              AND (p.valid_from IS NULL OR p.valid_from <= :today)
              AND (p.valid_to IS NULL OR p.valid_to >= :today)
            SQL]);
        $this->m['crm_price_lookup'] = $finder;
        $this->params($finder, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Price', 'string', ['is_show' => false, 'is_label' => true]],
            ['item_name', 'Item / service', 'string', ['is_criteria' => true]],
            ['category_id', 'Category', 'integer', ['is_criteria' => true, 'is_select' => true, 'ref_meta_id' => $this->m['crm_item_categories_grid']->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['is_service', 'Service', 'boolean', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['price', 'Price', 'decimal', ['render_type' => 'money']],
            ['min_qty', 'From qty', 'decimal'],
            ['price_list', 'Price list', 'string', ['is_criteria' => true]],
            ['customer_category_id', 'Customer category', 'integer', ['is_criteria' => true, 'is_select' => true, 'ref_meta_id' => $this->x['crm_customer_categories_grid']->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['currency', 'Currency', 'string'],
            ['valid_from', 'Valid from', 'date'],
            ['valid_to', 'Valid to', 'date'],
            ['item_id', 'Item', 'integer', ['is_show' => false]],
            ['price_list_id', 'Price list id', 'integer', ['is_show' => false]],
        ]);

        $this->x['crm_copy_quotation_lines']->update(['query_string' => <<<'SQL'
            INSERT INTO crm_sales_order_lines (sales_order_id, price_line_id, item_id, description, quantity, unit_price, discount_pct, line_total, created_at, updated_at)
            SELECT :sales_order_id, l.price_line_id, l.item_id, l.description, l.quantity, l.unit_price, l.discount_pct, l.line_total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_quotation_lines l
            WHERE l.quotation_id = :quotation_id
              AND NOT EXISTS (SELECT 1 FROM crm_sales_order_lines x WHERE x.sales_order_id = :sales_order_id)
            ORDER BY l.id;
            UPDATE crm_sales_orders
            SET total_amount = (SELECT COALESCE(SUM(x.line_total), 0) FROM crm_sales_order_lines x WHERE x.sales_order_id = :sales_order_id),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :sales_order_id;
            UPDATE crm_quotations SET sales_order_id = :sales_order_id, updated_at = CURRENT_TIMESTAMP WHERE id = :quotation_id;
            UPDATE crm_opportunities SET stage = 'won', probability = 100, updated_at = CURRENT_TIMESTAMP
            WHERE id = (SELECT q.opportunity_id FROM crm_quotations q WHERE q.id = :quotation_id)
            SQL]);
        $this->x['crm_copy_order_lines']->update(['query_string' => <<<'SQL'
            INSERT INTO crm_invoice_lines (invoice_id, price_line_id, item_id, description, quantity, unit_price, discount_pct, line_total, created_at, updated_at)
            SELECT :invoice_id, l.price_line_id, l.item_id, l.description, l.quantity, l.unit_price, l.discount_pct, l.line_total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_sales_order_lines l
            WHERE l.sales_order_id = :sales_order_id
              AND NOT EXISTS (SELECT 1 FROM crm_invoice_lines x WHERE x.invoice_id = :invoice_id)
            ORDER BY l.id;
            UPDATE crm_invoices
            SET total_amount = (SELECT COALESCE(SUM(x.line_total), 0) FROM crm_invoice_lines x WHERE x.invoice_id = :invoice_id),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :invoice_id;
            UPDATE crm_sales_orders SET status = 'invoiced', updated_at = CURRENT_TIMESTAMP WHERE id = :sales_order_id AND status <> 'cancelled'
            SQL]);
    }

    // ---------------------------------------------------------------- job items make tasks

    private function jobTasks(): void
    {
        $jobs = $this->meta('crm_create_job_tasks', 'Make job tasks', 'stage_query', 'crm_tasks', 'A follow-up task for each job line of a sales order (an item whose category is a job), for the order’s owner by its delivery date.', null, null, <<<'SQL'
            INSERT INTO crm_tasks (title, customer_id, due_on, owner_id, priority, status, notes, created_at, updated_at)
            SELECT CONCAT('Job: ', COALESCE(NULLIF(l.description, ''), i.name), ' (', o.so_number, ')'),
                   o.customer_id, COALESCE(o.delivery_date, o.order_date), o.owner_id, 'normal', 'open',
                   CONCAT('From sales order ', o.so_number, ', quantity ', l.quantity, ' ', i.unit),
                   CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_sales_order_lines l
            JOIN crm_sales_orders o ON o.id = l.sales_order_id
            JOIN proc_items i ON i.id = l.item_id
            JOIN proc_item_categories c ON c.id = i.category_id
            WHERE l.sales_order_id = :sales_order_id AND c.is_job = 1
            ORDER BY l.id
            SQL);
        $this->params($jobs, [['sales_order_id', 'Sales order', 'integer', ['is_required' => true]]]);

        // The order form's flow: copy an accepted quotation's lines first (when there is one),
        // then make the job tasks from the order's lines either way.
        $form = $this->x['crm_sales_order_form'];
        MetaProcessTransition::where('main_process_id', $form->id)->delete();
        MetaProcessWorkflow::where('main_process_id', $form->id)->delete();
        $copy = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $this->x['crm_copy_quotation_lines']->id, 'order_number' => 1]);
        MetaProcessBehaviour::create(['workflow_id' => $copy->id, 'source_path' => 'id', 'target_path' => 'sales_order_id']);
        MetaProcessBehaviour::create(['workflow_id' => $copy->id, 'source_path' => 'quotation_id', 'target_path' => 'quotation_id']);
        $task = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $jobs->id, 'order_number' => 2]);
        MetaProcessBehaviour::create(['workflow_id' => $task->id, 'source_path' => 'main.id', 'target_path' => 'sales_order_id']);
        MetaProcessTransition::create(['main_process_id' => $form->id, 'source_id' => null, 'target_id' => $copy->id, 'criteria' => [['field' => 'quotation_id', 'op' => 'not_empty']]]);
        MetaProcessTransition::create(['main_process_id' => $form->id, 'source_id' => null, 'target_id' => $task->id, 'criteria' => [['field' => 'quotation_id', 'op' => 'empty']]]);
        MetaProcessTransition::create(['main_process_id' => $form->id, 'source_id' => $copy->id, 'target_id' => $task->id, 'criteria' => []]);
    }

    // ---------------------------------------------------------------- Procurement picks the category too

    private function procurement(): void
    {
        $lookup = ['is_select' => true, 'ref_meta_id' => $this->m['crm_item_categories_grid']->id, 'render_type' => 'lookup', 'display_field' => 'name'];
        if (isset($this->x['proc_item_form'])) {
            MetaParam::where('main_meta_id', $this->x['proc_item_form']->id)->whereIn('field_path', ['category', 'category_id'])->first()?->update([
                'field_path' => 'category_id', 'param_name' => 'category_id', 'label_name' => 'Category', 'data_type' => 'integer',
                'json_config' => ['fill' => ['unit' => 'default_unit']],
            ] + $lookup);
            $unit = MetaParam::where('main_meta_id', $this->x['proc_item_form']->id)->where('field_path', 'unit')->first();
            $unit?->update(['json_config' => ['options' => self::UNITS]]);
        }
        if (isset($this->x['proc_items_grid'])) {
            MetaParam::where('main_meta_id', $this->x['proc_items_grid']->id)->whereIn('field_path', ['category', 'category_id'])->first()?->update([
                'field_path' => 'category_id', 'param_name' => 'category_id', 'label_name' => 'Category', 'data_type' => 'integer', 'is_criteria' => true,
            ] + $lookup);
        }
    }

    private function removeServices(): void
    {
        $ids = Meta::whereIn('code', ['crm_service_form', 'crm_services_grid'])->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('meta_params')->whereIn('ref_meta_id', $ids)->delete();
        DB::table('metas')->whereIn('id', $ids)->delete();
    }

    // ---------------------------------------------------------------- who may use it

    private function access(): void
    {
        $role = Role::where('code', 'crm')->first();
        $tag = Tag::firstOrCreate(['name' => 'crm'], ['color' => 'blue']);
        $types = array_flip($this->types);
        $now = now();
        foreach ($this->m as $meta) {
            $type = $types[$meta->type_id] ?? null;
            if ($role) {
                $full = $type === 'form';
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], [
                    'can_view' => true, 'can_create' => $full, 'can_edit' => $full || $type === 'stage_query', 'can_delete' => $full,
                ]);
            }
            if (! DB::table('meta_tag_map')->where('tag_id', $tag->id)->where('meta_id', $meta->id)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $meta->id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000001)

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null): Meta
    {
        return $this->m[$code] = Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'query_string' => $query,
            'is_active' => true,
        ]);
    }

    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create(($row[3] ?? []) + [
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
                'is_show' => true,
            ]);
        }
    }

    private function key(): array
    {
        return ['is_primary' => true, 'is_show' => false];
    }

    /** Replaces a grid's Add, Edit and Delete buttons (see SeedsActions). */
    private function actions(Meta $grid, Meta $form): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->whereIn('action', ['add', 'edit', 'delete'])->delete();
        foreach ([['add', 'Add', 'plus', false], ['edit', 'Edit', 'edit', true], ['delete', 'Delete', 'trash', true]] as $i => [$action, $label, $icon, $needsRow]) {
            MetaAction::create([
                'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $form->id,
                'needs_row' => $needsRow, 'show_in_row' => $needsRow, 'order_number' => $i + 1,
                'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
            ]);
        }
    }

    /** Adds or refreshes one row pointing at $target (a tab or a menu entry), matched by field_path; new rows go last. */
    private function row(Meta $meta, ?int $parentId, string $field, string $label, Meta $target): void
    {
        $scope = fn ($q) => $q->where('main_meta_id', $meta->id)->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId));
        $existing = MetaParam::where($scope)->where('field_path', $field)->first();
        if ($existing) {
            $existing->update(['label_name' => $label, 'ref_meta_id' => $target->id]);

            return;
        }
        MetaParam::create([
            'main_meta_id' => $meta->id, 'parent_id' => $parentId, 'order_number' => (int) MetaParam::where($scope)->max('order_number') + 1,
            'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => 'string', 'ref_meta_id' => $target->id, 'is_show' => true,
        ]);
    }
};
