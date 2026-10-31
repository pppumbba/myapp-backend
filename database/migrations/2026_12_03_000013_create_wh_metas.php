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
use App\Models\WfmStatus;
use App\Models\WfmTransition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The warehouse management app, built only from metas (tables: 2026_12_03_000012_create_wh_tables):
 *
 *   Warehouse (app wh_app)
 *   ├─ Dashboard        stock value, items in stock, below minimum, open works, requests to
 *   │                   issue, drafts; value by warehouse, in vs out by month, documents by
 *   │                   type, works by keeper, top items
 *   ├─ Stock            stock by location / by warehouse / below minimum, stock card (ledger)
 *   ├─ Receiving        receipts, purchase (from purchase orders), income, returns
 *   ├─ Issuing          sales (from sales orders), outcome, dispose
 *   ├─ Internal         movements, packaging, unpackaging
 *   ├─ Orders           inbound, outbound and transfer orders; Fulfil makes the document
 *   ├─ Requests         requisitions: draft -> submitted -> approved -> issued (workflow); Issue
 *   ├─ Works            jobs of store keepers: pick, put away, move, pack, count...; Start/Finish
 *   ├─ Warehouses       warehouses (workspace), locations (tree), store keepers
 *   └─ Setup            pack specs, stock levels (min / max / reorder)
 *
 * Every stock document is a wh_documents row with lines; its doc_type decides how its lines
 * move stock. Saving a new document fills its lines (from its order, request, purchase or
 * sales order, or pack spec) when it has none, posts it when "Post on save" is set, and makes
 * a work for the warehouse's head keeper when "Make a work" is set. Post / Unpost buttons
 * do the same later. Posting writes the ledger at average cost; the triggers keep balances
 * and refuse negative stock, so a document that would take more than there is fails whole.
 *
 * Idempotent: metas matched by code, their params, actions and flows rebuilt; workflow
 * statuses keep their ids. Role "warehouse" gets the permissions. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const DOC_TYPES = ['receipt', 'purchase', 'income', 'return_in', 'sales', 'outcome', 'return_out', 'dispose', 'movement', 'packaging', 'unpackaging'];

    private const PREFIXES = ['receipt' => 'RCV', 'purchase' => 'PUR', 'income' => 'INC', 'return_in' => 'RTI', 'sales' => 'SAL', 'outcome' => 'OUT',
        'return_out' => 'RTO', 'dispose' => 'DSP', 'movement' => 'MOV', 'packaging' => 'PCK', 'unpackaging' => 'UPK'];

    private const REASONS = ['internal_use', 'production', 'found', 'opening', 'damaged', 'expired', 'lost', 'theft', 'quality', 'wrong_item', 'excess', 'other'];

    private const LOCATION_KINDS = ['zone', 'aisle', 'rack', 'shelf', 'bin', 'dock', 'area'];

    private const WORK_KINDS = ['pick', 'putaway', 'move', 'pack', 'count', 'load', 'unload', 'dispose', 'inspect', 'other'];

    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> the metas made here, by code */
    private array $m = [];

    /** @var array<string, Meta> metas of other apps reused here */
    private array $ext = [];

    /** @var array<string, int> request status code => id */
    private array $status = [];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('wh_documents')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        $this->ext = Meta::whereIn('code', ['proc_suppliers_grid', 'crm_customers_grid'])->get()->keyBy('code')->all();

        DB::transaction(function () {
            $this->setup();
            $this->requestWorkflow();
            $this->lookups();
            $this->stockQueries();
            $this->documents();
            $this->orders();
            $this->requests();
            $this->works();
            $this->stocks();
            $this->workspace();
            $this->notifications();
            $this->dashboard();
            $this->app();
            $this->access();
        });

        // DDL commits on MySQL, so after the transaction: a new request starts as a draft.
        DB::statement(sprintf('ALTER TABLE `wh_requests` ALTER `status_id` SET DEFAULT %d', $this->status['draft']));
        DB::table('wh_requests')->whereNull('status_id')->update(['status_id' => $this->status['draft']]);
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        if (Schema::hasTable('wh_requests')) {
            DB::statement('ALTER TABLE `wh_requests` ALTER `status_id` DROP DEFAULT');
        }
        DB::transaction(function () {
            $ids = DB::table('metas')->where('code', 'like', 'wh\_%')->pluck('id');
            DB::table('meta_schedules')->whereIn('process_id', $ids)->orWhereIn('view_id', $ids)->delete();
            DB::table('wfm_status_log')->whereIn('workflow_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->whereNotIn('main_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('ref_meta_id', $ids)->whereNotIn('id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('roles')->where('code', 'warehouse')->delete();
            DB::table('tags')->where('name', 'warehouse')->delete();
            DB::table('autonumbers')->where('table_name', 'like', 'wh\_%')->delete();
        });
    }

    // ---------------------------------------------------------------- warehouses, locations, keepers, catalogue setup

    private function setup(): void
    {
        $users = $this->meta('wh_users_lookup', 'People', 'datagrid', null, 'Everyone who can sign in, to pick as a keeper, manager or requester.', null, null, 'SELECT id, name, email FROM users');
        $this->params($users, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
        ]);

        $form = $this->meta('wh_warehouse_form', 'Warehouse form', 'form', 'wh_warehouses', 'A warehouse or store: its manager and whether its stock may go below zero.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(['main', 'branch', 'transit', 'production', 'quarantine', 'virtual'], 'main')],
            ['address', 'Address', 'string'],
            ['manager_id', 'Manager', 'integer', $this->lookup('wh_users_lookup')],
            ['allow_negative', 'Stock may go below zero', 'boolean', ['default_value' => '0']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('wh_warehouses_grid', 'Warehouses', 'datagrid', 'wh_warehouses', 'Warehouses and stores.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['address', 'Address', 'string'],
            ['manager_id', 'Manager', 'integer', $this->lookup('wh_users_lookup')],
            ['allow_negative', 'Below zero', 'boolean'],
            ['is_active', 'Active', 'boolean', ['is_criteria' => true]],
        ]);

        $lookup = $this->meta('wh_locations_lookup', 'Locations (pick list)', 'datagrid', 'wh_locations', 'Locations with their warehouse, to pick on lines.', null, null, <<<'SQL'
            SELECT l.id, l.warehouse_id, l.code, l.name, l.kind, l.is_active,
                   CONCAT(w.code, ' / ', l.code, ' ', l.name) AS label
            FROM wh_locations l
            JOIN wh_warehouses w ON w.id = l.warehouse_id
            SQL);
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Location', 'string', ['is_label' => true, 'is_show' => false]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['kind', 'Kind', 'string'],
            ['is_active', 'Active', 'boolean'],
        ]);

        $form = $this->meta('wh_location_form', 'Location form', 'form', 'wh_locations', 'A place in a warehouse: zone, aisle, rack, shelf or bin, inside another.', null, null, null, [
            'before_save_expression_string' => "if (get('parent_id') && get('parent_id') == get('id')) {\n  error('A location cannot be inside itself.');\n}",
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['parent_id', 'Inside', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['code', 'Code', 'string', ['is_required' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(self::LOCATION_KINDS, 'bin')],
            ['capacity', 'Capacity', 'decimal', ['min_value' => '0']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('wh_locations_grid', 'Locations', 'datagrid', 'wh_locations', 'The locations of warehouses as a tree: zones hold aisles, racks, shelves and bins.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::LOCATION_KINDS]]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['parent_id', 'Inside', 'integer', ['is_parent' => true, 'is_show' => false]],
            ['capacity', 'Capacity', 'decimal'],
            ['is_active', 'Active', 'boolean'],
        ]);

        $form = $this->meta('wh_store_keeper_form', 'Store keeper form', 'form', 'wh_store_keepers', 'Someone who works in a warehouse; the head keeper gets its works unless a document names one.');
        $roles = ['head', 'keeper', 'picker', 'driver', 'controller'];
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['user_id', 'Person', 'integer', ['is_required' => true, 'is_label' => true] + $this->lookup('wh_users_lookup')],
            ['role', 'Role', 'string', ['is_required' => true] + $this->options($roles, 'keeper')],
            ['phone', 'Phone', 'string'],
            ['shift', 'Shift', 'string', $this->options(['day', 'night', 'rotating'], 'day')],
            ['since', 'Since', 'date', ['default_value' => '{today}']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('wh_store_keepers_grid', 'Store keepers', 'datagrid', 'wh_store_keepers', 'Who works in which warehouse, in what role.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['user_id', 'Person', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_users_lookup')],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['role', 'Role', 'string', ['is_criteria' => true, 'json_config' => ['options' => $roles]]],
            ['phone', 'Phone', 'string'],
            ['shift', 'Shift', 'string'],
            ['since', 'Since', 'date'],
            ['is_active', 'Active', 'boolean', ['is_criteria' => true]],
        ]);

        // Goods only: items whose category is not a service (or job).
        $items = $this->meta('wh_items_lookup', 'Goods (pick list)', 'datagrid', 'proc_items', 'Items of the shared catalogue that are kept in stock (not services or jobs), with what is on hand.', null, null, <<<'SQL'
            SELECT i.id, i.code, i.name, i.unit, i.unit_price, c.name AS category, COALESCE(c.is_asset, 0) AS is_asset,
                   COALESCE(s.on_hand, 0) AS on_hand, CONCAT(COALESCE(i.code, ''), ' ', i.name) AS label
            FROM proc_items i
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            LEFT JOIN (SELECT item_id, SUM(quantity) AS on_hand FROM wh_stocks GROUP BY item_id) s ON s.item_id = i.id
            WHERE COALESCE(c.is_service, 0) = 0 AND COALESCE(i.is_active, 1) = 1
            SQL);
        $this->params($items, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Item', 'string', ['is_label' => true, 'is_show' => false]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['unit_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['on_hand', 'On hand', 'decimal'],
            ['is_asset', 'Asset', 'boolean'],
        ]);

        $form = $this->meta('wh_pack_spec_form', 'Pack spec form', 'form', 'wh_pack_specs', 'What one package holds: a component and how many of it.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['package_item_id', 'Package', 'integer', ['is_required' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['component_item_id', 'Holds', 'integer', ['is_required' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['quantity', 'How many per package', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['notes', 'Notes', 'string'],
        ]);
        $grid = $this->meta('wh_pack_specs_grid', 'Pack specs', 'datagrid', 'wh_pack_specs', 'What packages hold; packaging and unpackaging documents take their lines from here.', null, ['group_by' => ['package_item_id']]);
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['package_item_id', 'Package', 'integer', ['is_criteria' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['component_item_id', 'Holds', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['quantity', 'Per package', 'decimal'],
            ['notes', 'Notes', 'string'],
        ]);

        $form = $this->meta('wh_stock_level_form', 'Stock level form', 'form', 'wh_stock_levels', 'How much of an item a warehouse should keep: below the minimum it shows as low; reorder how much.', null, null, null, [
            'before_save_expression_string' => "if (get('max_qty') !== null && get('max_qty') !== '' && num(get('max_qty')) < num(get('min_qty'))) {\n  error('The maximum is below the minimum.');\n}",
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['item_id', 'Item', 'integer', ['is_required' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['min_qty', 'Minimum', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0']],
            ['max_qty', 'Maximum', 'decimal', ['min_value' => '0']],
            ['reorder_qty', 'Reorder', 'decimal', ['min_value' => '0']],
        ]);
        $grid = $this->meta('wh_stock_levels_grid', 'Stock levels', 'datagrid', 'wh_stock_levels', 'Minimum, maximum and reorder quantities by item and warehouse.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['item_id', 'Item', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['min_qty', 'Minimum', 'decimal'],
            ['max_qty', 'Maximum', 'decimal'],
            ['reorder_qty', 'Reorder', 'decimal'],
        ]);
    }

    // ---------------------------------------------------------------- the request workflow

    private function requestWorkflow(): void
    {
        $workflow = $this->meta('wh_request_workflow', 'Request workflow', 'workflow', 'wh_requests', 'Draft → submitted → approved → issued (when an outcome for it is posted) → closed; or rejected.');
        $statuses = [
            ['draft', 'Draft', 'gray', [130, 130]],
            ['submitted', 'Submitted', 'amber', [310, 130]],
            ['approved', 'Approved', 'blue', [490, 130]],
            ['issued', 'Issued', 'green', [670, 130]],
            ['closed', 'Closed', 'gray', [850, 130]],
            ['rejected', 'Rejected', 'red', [490, 300]],
        ];
        $transitions = [
            [null, 'draft', null, true], ['draft', 'submitted', null, true], ['submitted', 'approved', null, true],
            // Drawn only: posting the outcome of an approved request moves it here.
            ['approved', 'issued', null, false], ['issued', 'closed', null, true],
            ['submitted', 'rejected', null, true], ['submitted', 'draft', null, true], ['rejected', 'draft', null, true], ['approved', 'closed', null, true],
        ];
        $ids = [];
        $layout = ['start' => ['x' => 30, 'y' => 150], 'statuses' => []];
        foreach ($statuses as [$code, $name, $color, [$x, $y]]) {
            $ids[$code] = WfmStatus::updateOrCreate(['workflow_meta_id' => $workflow->id, 'code' => $code], ['name' => $name, 'color' => $color, 'meta_id' => null])->id;
            $layout['statuses'][$ids[$code]] = ['x' => $x, 'y' => $y];
        }
        WfmStatus::where('workflow_meta_id', $workflow->id)->whereNotIn('code', array_keys($ids))->delete();
        WfmTransition::where('workflow_meta_id', $workflow->id)->delete();
        $path = [];
        foreach ($transitions as [$from, $to, $criteria, $live]) {
            $t = WfmTransition::create([
                'workflow_meta_id' => $workflow->id, 'prev_status_id' => $from ? $ids[$from] : null, 'next_status_id' => $ids[$to],
                'criteria' => $criteria, 'is_transition' => $live,
            ]);
            if (count($path) === 0 || $path[count($path) - 1]['to'] === $from) {
                $path[] = ['t' => $t, 'to' => $to];
            }
        }
        foreach ($path as $i => $step) {
            $step['t']->update(['prev_id' => $path[$i - 1]['t']->id ?? null, 'next_id' => $path[$i + 1]['t']->id ?? null]);
        }
        $workflow->update(['json_config' => ['layout' => $layout]]);
        $this->status = $ids;
    }

    // ---------------------------------------------------------------- pick lists of what documents come from

    private function lookups(): void
    {
        $orders = $this->meta('wh_orders_lookup', 'Warehouse orders (pick list)', 'datagrid', 'wh_orders', 'Orders still to fulfil.', null, null, <<<'SQL'
            SELECT o.id, o.order_no, o.kind, o.warehouse_id, o.status, o.expected_date,
                   CONCAT(o.order_no, ' · ', o.kind, ' · ', o.status) AS label
            FROM wh_orders o
            SQL);
        $this->params($orders, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Order', 'string', ['is_label' => true, 'is_show' => false]],
            ['order_no', 'No', 'string', ['is_criteria' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['expected_date', 'Expected', 'date'],
            ['status', 'Status', 'string', ['is_criteria' => true]],
        ]);

        $requests = $this->meta('wh_requests_lookup', 'Requests (pick list)', 'datagrid', 'wh_requests', 'Requests, to issue against.', null, null, <<<'SQL'
            SELECT r.id, r.request_no, r.warehouse_id, r.department, r.needed_by, s.code AS status_code,
                   CONCAT(r.request_no, ' · ', COALESCE(NULLIF(r.department, ''), u.name, ''), ' · ', COALESCE(s.name, '')) AS label
            FROM wh_requests r
            LEFT JOIN users u ON u.id = r.requested_by
            LEFT JOIN wfm_status s ON s.id = r.status_id
            SQL);
        $this->params($requests, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Request', 'string', ['is_label' => true, 'is_show' => false]],
            ['request_no', 'No', 'string', ['is_criteria' => true]],
            ['department', 'Department', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['needed_by', 'Needed by', 'date'],
            ['status_code', 'Status', 'string', ['is_criteria' => true]],
        ]);

        $po = $this->meta('wh_purchase_orders_lookup', 'Purchase orders (pick list)', 'datagrid', 'proc_purchase_orders', 'Purchase orders of Procurement, with what is still to come.', null, null, <<<'SQL'
            SELECT o.id, o.po_number, o.supplier_id, s.name AS supplier_name, o.order_date, o.expected_date,
                   COALESCE((SELECT SUM(GREATEST(l.quantity - l.delivered_quantity, 0)) FROM proc_purchase_order_lines l WHERE l.purchase_order_id = o.id), 0) AS to_come,
                   CONCAT(o.po_number, ' · ', s.name) AS label
            FROM proc_purchase_orders o
            JOIN proc_suppliers s ON s.id = o.supplier_id
            SQL);
        $this->params($po, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Purchase order', 'string', ['is_label' => true, 'is_show' => false]],
            ['po_number', 'PO', 'string', ['is_criteria' => true]],
            ['supplier_name', 'Supplier', 'string', ['is_criteria' => true]],
            ['order_date', 'Ordered', 'date'],
            ['expected_date', 'Expected', 'date'],
            ['to_come', 'Still to come', 'decimal'],
            ['supplier_id', 'Supplier', 'integer', ['is_show' => false]],
        ]);

        $so = $this->meta('wh_sales_orders_lookup', 'Sales orders (pick list)', 'datagrid', 'crm_sales_orders', 'Sales orders of the CRM, to issue goods for.', null, null, <<<'SQL'
            SELECT o.id, o.so_number, o.customer_id, c.name AS customer_name, o.order_date, o.delivery_date, o.status,
                   CONCAT(o.so_number, ' · ', COALESCE(c.name, '')) AS label
            FROM crm_sales_orders o
            LEFT JOIN crm_customers c ON c.id = o.customer_id
            SQL);
        $this->params($so, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Sales order', 'string', ['is_label' => true, 'is_show' => false]],
            ['so_number', 'SO', 'string', ['is_criteria' => true]],
            ['customer_name', 'Customer', 'string', ['is_criteria' => true]],
            ['order_date', 'Ordered', 'date'],
            ['delivery_date', 'Deliver by', 'date'],
            ['status', 'Status', 'string'],
            ['customer_id', 'Customer', 'integer', ['is_show' => false]],
        ]);

        $docs = $this->meta('wh_documents_lookup', 'Stock documents (pick list)', 'datagrid', 'wh_documents', 'Stock documents, to link works to.', null, null, <<<'SQL'
            SELECT d.id, d.doc_no, d.doc_type, d.warehouse_id, d.doc_date, d.status,
                   CONCAT(d.doc_no, ' · ', REPLACE(d.doc_type, '_', ' '), ' · ', d.status) AS label
            FROM wh_documents d
            SQL);
        $this->params($docs, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Document', 'string', ['is_label' => true, 'is_show' => false]],
            ['doc_no', 'No', 'string', ['is_criteria' => true]],
            ['doc_type', 'Type', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['doc_date', 'Date', 'date'],
            ['status', 'Status', 'string', ['is_criteria' => true]],
        ]);
    }

    // ---------------------------------------------------------------- fill, post and unpost

    private function stockQueries(): void
    {
        $fill = $this->stageQuery('wh_fill_document_lines', 'Fill document lines', 'wh_document_lines', <<<'SQL'
            -- A draft document without lines takes the first that applies: what its order, request,
            -- purchase order or sales order still needs, or its package's spec.
            INSERT INTO wh_document_lines (document_id, item_id, order_line_id, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, ol.item_id, ol.id, ol.quantity - COALESCE(x.done, 0), ol.unit_price, 0, NOW(), NOW()
            FROM wh_documents d
            JOIN wh_order_lines ol ON ol.order_id = d.order_id
            LEFT JOIN (SELECT l.order_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                       WHERE p.status = 'posted' AND l.order_line_id IS NOT NULL GROUP BY l.order_line_id) x ON x.order_line_id = ol.id
            WHERE d.id = :document_id AND d.status = 'draft' AND ol.quantity - COALESCE(x.done, 0) > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id)
            ORDER BY ol.id;
            INSERT INTO wh_document_lines (document_id, item_id, request_line_id, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, rl.item_id, rl.id, COALESCE(rl.approved_qty, rl.quantity) - COALESCE(x.done, 0), 0, 0, NOW(), NOW()
            FROM wh_documents d
            JOIN wh_request_lines rl ON rl.request_id = d.request_id
            LEFT JOIN (SELECT l.request_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                       WHERE p.status = 'posted' AND l.request_line_id IS NOT NULL GROUP BY l.request_line_id) x ON x.request_line_id = rl.id
            WHERE d.id = :document_id AND d.status = 'draft' AND COALESCE(rl.approved_qty, rl.quantity) - COALESCE(x.done, 0) > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id)
            ORDER BY rl.id;
            INSERT INTO wh_document_lines (document_id, item_id, po_line_id, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, pl.item_id, pl.id, pl.quantity - pl.delivered_quantity, pl.unit_price, 0, NOW(), NOW()
            FROM wh_documents d
            JOIN proc_purchase_order_lines pl ON pl.purchase_order_id = d.purchase_order_id
            WHERE d.id = :document_id AND d.status = 'draft' AND pl.item_id IS NOT NULL AND pl.quantity - pl.delivered_quantity > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id)
            ORDER BY pl.id;
            INSERT INTO wh_document_lines (document_id, item_id, so_line_id, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, sl.item_id, sl.id, sl.quantity - COALESCE(x.done, 0), 0, 0, NOW(), NOW()
            FROM wh_documents d
            JOIN crm_sales_order_lines sl ON sl.sales_order_id = d.sales_order_id
            JOIN proc_items i ON i.id = sl.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            LEFT JOIN (SELECT l.so_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                       WHERE p.status = 'posted' AND p.doc_type = 'sales' AND l.so_line_id IS NOT NULL GROUP BY l.so_line_id) x ON x.so_line_id = sl.id
            WHERE d.id = :document_id AND d.status = 'draft' AND COALESCE(c.is_service, 0) = 0 AND sl.quantity - COALESCE(x.done, 0) > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id)
            ORDER BY sl.id;
            INSERT INTO wh_document_lines (document_id, item_id, direction, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, s.component_item_id, CASE WHEN d.doc_type = 'packaging' THEN 'out' ELSE 'in' END, s.quantity * d.package_qty, 0, 0, NOW(), NOW()
            FROM wh_documents d
            JOIN wh_pack_specs s ON s.package_item_id = d.package_item_id
            WHERE d.id = :document_id AND d.status = 'draft' AND d.doc_type IN ('packaging', 'unpackaging') AND d.package_qty > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id)
            ORDER BY s.id;
            -- The package itself: made by packaging, taken apart by unpackaging.
            INSERT INTO wh_document_lines (document_id, item_id, direction, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, d.package_item_id, CASE WHEN d.doc_type = 'packaging' THEN 'in' ELSE 'out' END, d.package_qty, 0, 0, NOW(), NOW()
            FROM wh_documents d
            WHERE d.id = :document_id AND d.status = 'draft' AND d.doc_type IN ('packaging', 'unpackaging') AND d.package_qty > 0
              AND NOT EXISTS (SELECT 1 FROM wh_document_lines y WHERE y.document_id = d.id AND y.item_id = d.package_item_id);
            UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
            SET l.amount = ROUND(l.quantity * l.unit_cost, 2)
            WHERE d.id = :document_id AND d.status = 'draft';
            UPDATE wh_documents d
            SET d.total_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM wh_document_lines l WHERE l.document_id = d.id), d.updated_at = NOW()
            WHERE d.id = :document_id AND d.status = 'draft'
            SQL, 'A draft document without lines gets them from its order, request, purchase order, sales order or package spec (what is still to do).', [
            ['document_id', 'Document', 'integer', ['is_required' => true]],
        ]);

        $orderSync = <<<'SQL'
            UPDATE wh_orders o
            SET o.status = COALESCE((
                  SELECT CASE WHEN SUM(GREATEST(ol.quantity - COALESCE(x.done, 0), 0)) <= 0 THEN 'done'
                              WHEN SUM(COALESCE(x.done, 0)) > 0 THEN 'partial' ELSE 'open' END
                  FROM wh_order_lines ol
                  LEFT JOIN (SELECT l.order_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                             WHERE p.status = 'posted' AND l.order_line_id IS NOT NULL GROUP BY l.order_line_id) x ON x.order_line_id = ol.id
                  WHERE ol.order_id = o.id), o.status),
                o.updated_at = NOW()
            WHERE o.id = (SELECT d.order_id FROM wh_documents d WHERE d.id = :document_id) AND o.status <> 'cancelled'
            SQL;
        $requestSync = strtr(<<<'SQL'
            UPDATE wh_requests r
            SET r.status_id = CASE WHEN NOT EXISTS (
                  SELECT 1 FROM wh_request_lines rl
                  LEFT JOIN (SELECT l.request_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                             WHERE p.status = 'posted' AND l.request_line_id IS NOT NULL GROUP BY l.request_line_id) x ON x.request_line_id = rl.id
                  WHERE rl.request_id = r.id AND COALESCE(rl.approved_qty, rl.quantity) > COALESCE(x.done, 0)) THEN {issued} ELSE {approved} END,
                r.updated_at = NOW()
            WHERE r.id = (SELECT d.request_id FROM wh_documents d WHERE d.id = :document_id) AND r.status_id IN ({approved}, {issued})
            SQL, ['{issued}' => (string) $this->status['issued'], '{approved}' => (string) $this->status['approved']]);
        $deliveredSync = <<<'SQL'
            UPDATE proc_purchase_order_lines pl
            SET pl.delivered_quantity = (SELECT COALESCE(SUM(x.quantity), 0) FROM proc_order_line_deliveries x WHERE x.order_line_id = pl.id),
                pl.updated_at = NOW()
            WHERE pl.id IN (SELECT l.po_line_id FROM wh_document_lines l WHERE l.document_id = :document_id AND l.po_line_id IS NOT NULL)
            SQL;
        $move = fn (string $sign, string $warehouse, string $location, string $directions) => <<<SQL
            INSERT INTO wh_stock_moves (document_id, line_id, doc_type, moved_on, item_id, warehouse_id, location_id, quantity, unit_cost, amount, created_at, updated_at)
            SELECT d.id, l.id, d.doc_type, d.doc_date, l.item_id, {$warehouse}, {$location}, {$sign}l.quantity, l.unit_cost, 0, NOW(), NOW()
            FROM wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
            WHERE d.id = :document_id AND d.status = 'draft' AND l.direction IN ({$directions})
            ORDER BY l.id
            SQL;

        $this->stageQuery('wh_post_document', 'Post document', 'wh_documents', implode(";\n", [
            <<<'SQL'
                -- Which way each line goes: by the document type (packaging lines say so themselves).
                UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                SET l.direction = CASE
                      WHEN d.doc_type IN ('receipt', 'purchase', 'income', 'return_in') THEN 'in'
                      WHEN d.doc_type = 'movement' THEN 'move'
                      WHEN d.doc_type = 'packaging' THEN COALESCE(NULLIF(l.direction, ''), CASE WHEN l.item_id = d.package_item_id THEN 'in' ELSE 'out' END)
                      WHEN d.doc_type = 'unpackaging' THEN COALESCE(NULLIF(l.direction, ''), CASE WHEN l.item_id = d.package_item_id THEN 'out' ELSE 'in' END)
                      ELSE 'out' END
                WHERE d.id = :document_id AND d.status = 'draft'
                SQL,
            <<<'SQL'
                -- What leaves costs the warehouse's average.
                UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                LEFT JOIN (SELECT item_id, warehouse_id, SUM(amount) / NULLIF(SUM(quantity), 0) AS avg_cost FROM wh_stocks GROUP BY item_id, warehouse_id) s
                       ON s.item_id = l.item_id AND s.warehouse_id = d.warehouse_id
                SET l.unit_cost = ROUND(GREATEST(COALESCE(s.avg_cost, l.unit_cost), 0), 4)
                WHERE d.id = :document_id AND d.status = 'draft' AND l.direction IN ('out', 'move')
                SQL,
            <<<'SQL'
                -- What packaging makes (or unpackaging frees) costs what went in, shared by catalogue price.
                UPDATE wh_document_lines l
                JOIN wh_documents d ON d.id = l.document_id
                JOIN proc_items i ON i.id = l.item_id
                JOIN (SELECT o.document_id, SUM(o.quantity * o.unit_cost) AS spent FROM wh_document_lines o
                      WHERE o.document_id = :document_id AND o.direction = 'out' GROUP BY o.document_id) o ON o.document_id = d.id
                JOIN (SELECT w.document_id, SUM(w.quantity * COALESCE(NULLIF(wi.unit_price, 0), 1)) AS weight FROM wh_document_lines w
                      JOIN proc_items wi ON wi.id = w.item_id
                      WHERE w.document_id = :document_id AND w.direction = 'in' GROUP BY w.document_id) w ON w.document_id = d.id
                SET l.unit_cost = CASE WHEN w.weight > 0 THEN ROUND(o.spent * COALESCE(NULLIF(i.unit_price, 0), 1) / w.weight, 4) ELSE 0 END
                WHERE d.id = :document_id AND d.status = 'draft' AND d.doc_type IN ('packaging', 'unpackaging') AND l.direction = 'in'
                SQL,
            <<<'SQL'
                UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                SET l.amount = ROUND(l.quantity * l.unit_cost, 2)
                WHERE d.id = :document_id AND d.status = 'draft'
                SQL,
            "-- Out first, so a shortage stops it.\n".$move('-', 'd.warehouse_id', 'l.location_id', "'out', 'move'"),
            $move('', 'd.warehouse_id', 'l.location_id', "'in'"),
            $move('', 'COALESCE(d.to_warehouse_id, d.warehouse_id)', 'l.to_location_id', "'move'"),
            <<<'SQL'
                -- A purchase delivers its purchase order lines in Procurement.
                INSERT INTO proc_order_line_deliveries (order_line_id, delivery_date, quantity, note, created_at, updated_at)
                SELECT l.po_line_id, d.doc_date, ROUND(SUM(l.quantity)), CONCAT('Warehouse ', d.doc_no), NOW(), NOW()
                FROM wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                WHERE d.id = :document_id AND d.status = 'draft' AND l.po_line_id IS NOT NULL AND l.direction = 'in'
                GROUP BY l.po_line_id, d.doc_date, d.doc_no
                SQL,
            $deliveredSync,
            <<<'SQL'
                UPDATE wh_documents d
                SET d.status = 'posted', d.posted_at = NOW(), d.posted_by = :user_id, d.updated_at = NOW(),
                    d.total_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM wh_document_lines l
                                      WHERE l.document_id = d.id AND (l.direction <> 'in' OR d.doc_type NOT IN ('packaging', 'unpackaging')))
                WHERE d.id = :document_id AND d.status = 'draft'
                SQL,
            $orderSync,
            $requestSync,
        ]), 'Posts a draft document: its lines move stock (out at average cost), its order and request are brought up to date, and a purchase delivers its PO lines. Fails whole if stock would go below zero.', [
            ['document_id', 'Document', 'integer', ['is_required' => true]],
        ]);

        $this->stageQuery('wh_unpost_document', 'Unpost document', 'wh_documents', implode(";\n", [
            <<<'SQL'
                DELETE m FROM wh_stock_moves m JOIN wh_documents d ON d.id = m.document_id
                WHERE d.id = :document_id AND d.status = 'posted'
                SQL,
            <<<'SQL'
                DELETE x FROM proc_order_line_deliveries x JOIN wh_documents d ON x.note = CONCAT('Warehouse ', d.doc_no)
                WHERE d.id = :document_id AND d.status = 'posted'
                  AND x.order_line_id IN (SELECT l.po_line_id FROM wh_document_lines l WHERE l.document_id = :document_id AND l.po_line_id IS NOT NULL)
                SQL,
            $deliveredSync,
            <<<'SQL'
                UPDATE wh_documents SET status = 'draft', posted_at = NULL, posted_by = NULL, updated_at = NOW()
                WHERE id = :document_id AND status = 'posted'
                SQL,
            $orderSync,
            $requestSync,
        ]), 'Takes a posted document back to draft: its stock moves are undone (refused if that stock is gone), and its order, request and PO deliveries follow.', [
            ['document_id', 'Document', 'integer', ['is_required' => true]],
        ]);
    }

    // ---------------------------------------------------------------- stock documents

    private function documents(): void
    {
        $line = $this->meta('wh_document_line_form', 'Document line', 'form', 'wh_document_lines', 'An item on a stock document: how many, from or to which location, at what cost.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['document_id', 'Document', 'integer', ['is_show' => false]],
            ['item_id', 'Item', 'integer', ['is_required' => true] + $this->lookup('wh_items_lookup', 'label', ['json_config' => ['fill' => ['unit_cost' => 'unit_price']]])],
            ['location_id', 'Location', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['to_location_id', 'To location (movement)', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['direction', 'In / out (packaging)', 'string', ['render_type' => 'select', 'json_config' => ['options' => ['in', 'out', 'move']]]],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['unit_cost', 'Unit cost', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['lot_no', 'Lot', 'string'],
            ['expiry_date', 'Expires', 'date'],
            ['notes', 'Note', 'string'],
            ['order_line_id', 'Order line', 'integer', ['is_show' => false]],
            ['request_line_id', 'Request line', 'integer', ['is_show' => false]],
            ['po_line_id', 'PO line', 'integer', ['is_show' => false]],
            ['so_line_id', 'SO line', 'integer', ['is_show' => false]],
        ]);

        $printLine = $this->meta('wh_document_line_print', 'Document line (print)', 'form', 'wh_document_lines', 'The columns a printed stock document shows.');
        $this->params($printLine, [
            ['id', 'ID', 'integer', $this->key()],
            ['document_id', 'Document', 'integer', ['is_show' => false]],
            ['item_id', 'Item', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['wh_items_lookup']->id, 'display_field' => 'label']],
            ['location_id', 'Location', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['wh_locations_lookup']->id, 'display_field' => 'label']],
            ['direction', 'In/out', 'string'],
            ['quantity', 'Qty', 'decimal'],
            ['unit_cost', 'Unit cost', 'decimal', ['render_type' => 'money']],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money']],
        ]);
        $warehouse = fn (string $label) => ['is_select' => true, 'ref_meta_id' => $this->m['wh_warehouses_grid']->id, 'display_field' => 'name'];
        $report = $this->meta('wh_document_report', 'Stock document', 'report', 'wh_documents', 'A stock document to print and sign: receipt note, issue note, transfer, write-off.', null, [
            'title' => 'Stock document',
            'number_field' => 'doc_no',
            'issuer' => 'Blue Sky LLC · Warehouse',
            'note' => 'Goods counted and handed over in the quantities above.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['doc_no', 'No', 'string'],
            ['doc_type', 'Type', 'string'],
            ['doc_date', 'Date', 'date'],
            ['warehouse_id', 'Warehouse', 'integer', $warehouse('Warehouse')],
            ['to_warehouse_id', 'To warehouse', 'integer', $warehouse('To warehouse')],
            ['counterparty', 'From / to', 'string'],
            ['reason', 'Reason', 'string'],
            ['lines', 'Goods', 'detail', ['ref_meta_id' => $printLine->id, 'json_config' => ['foreign_key' => 'document_id', 'amount' => ['amount']]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['notes', 'Notes', 'text'],
            ['handed_over', 'Handed over by', 'signature'],
            ['received', 'Received by', 'signature'],
        ]);

        $form = $this->meta('wh_document_form', 'Stock document', 'form', 'wh_documents', 'Any stock document: its type decides how the lines move stock. Leave the lines empty to take them from the order, request, purchase or sales order, or pack spec.', null, ['print_meta_id' => $report->id], null, [
            'is_log' => true,
            'var_fnc_expression_string' => <<<'JS'
                // Only the fields the document type needs; amounts from qty x cost.
                function fit() {
                  const t = get('doc_type');
                  const is = (...types) => types.includes(t);
                  hide('to_warehouse_id', !is('movement'));
                  hide('order_id', !is('receipt', 'outcome', 'movement'));
                  hide('request_id', !is('outcome'));
                  hide('purchase_order_id', !is('purchase'));
                  hide('sales_order_id', !is('sales'));
                  hide('supplier_id', !is('receipt', 'purchase', 'return_out'));
                  hide('customer_id', !is('sales', 'return_in', 'outcome'));
                  hide('reason', !is('income', 'outcome', 'return_in', 'return_out', 'dispose'));
                  hide('package_item_id', !is('packaging', 'unpackaging'));
                  hide('package_qty', !is('packaging', 'unpackaging'));
                }
                function refresh() {
                  lines('lines').forEach((l, i) => setLine('lines', i, 'amount', round(num(l.quantity) * num(l.unit_cost), 2)));
                  set('total_amount', round(sum('lines', (l) => num(l.quantity) * num(l.unit_cost)), 2));
                }
                JS,
            'load_expression_string' => <<<'JS'
                readonly('status');
                readonly('total_amount');
                fit();
                refresh();
                if (get('status') === 'posted') {
                  ['doc_type', 'doc_date', 'warehouse_id', 'to_warehouse_id', 'lines'].forEach((f) => readonly(f));
                }
                JS,
            'event_expression_string' => "if (field === 'doc_type') fit();\nif (detail === 'lines') refresh();",
            'before_save_expression_string' => <<<'JS'
                const t = get('doc_type');
                const rows = lines('lines');
                if (get('status') === 'posted' && mode === 'edit') {
                  error('The document is posted: unpost it to change it.');
                }
                if (rows.some((l) => num(l.quantity) <= 0)) {
                  error('Every line needs a quantity above zero.');
                }
                if (t === 'movement' && !get('to_warehouse_id') && rows.some((l) => !l.to_location_id)) {
                  error('A movement needs a warehouse or location to move to.');
                }
                if (t === 'movement' && get('to_warehouse_id') == get('warehouse_id') && rows.some((l) => l.to_location_id && l.to_location_id == l.location_id)) {
                  error('A line moves to the location it is in.');
                }
                if ((t === 'packaging' || t === 'unpackaging') && (!get('package_item_id') || num(get('package_qty')) <= 0)) {
                  error('Pick the package and how many.');
                }
                if (t === 'dispose' && !get('reason')) {
                  error('Say why it is disposed.');
                }
                if (rows.length === 0 && !get('order_id') && !get('request_id') && !get('purchase_order_id') && !get('sales_order_id') && !get('package_item_id')) {
                  error('Add lines, or pick an order, request, purchase or sales order to take them from.');
                }
                JS,
        ]);
        $rules = [];
        foreach (self::PREFIXES as $type => $prefix) {
            $rules[] = ['field' => 'doc_type', 'value' => $type, 'format' => $prefix.'-{yy}{MM}{seq:4}'];
        }
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['doc_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'WH-{yy}{MM}{seq:4}', 'rules' => $rules]]]],
            ['doc_type', 'Type', 'string', ['is_required' => true, 'is_label' => true] + $this->options(self::DOC_TYPES, 'receipt')],
            ['doc_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['to_warehouse_id', 'To warehouse', 'integer', $this->lookup('wh_warehouses_grid')],
            ['order_id', 'Warehouse order', 'integer', $this->lookup('wh_orders_lookup', 'label', ['json_config' => ['fill' => ['warehouse_id' => 'warehouse_id']]])],
            ['request_id', 'Request', 'integer', $this->lookup('wh_requests_lookup', 'label', ['json_config' => ['fill' => ['warehouse_id' => 'warehouse_id']]])],
            ['purchase_order_id', 'Purchase order', 'integer', $this->lookup('wh_purchase_orders_lookup', 'label', ['json_config' => ['fill' => ['supplier_id' => 'supplier_id', 'counterparty' => 'supplier_name']]])],
            ['sales_order_id', 'Sales order', 'integer', $this->lookup('wh_sales_orders_lookup', 'label', ['json_config' => ['fill' => ['customer_id' => 'customer_id', 'counterparty' => 'customer_name']]])],
            ['supplier_id', 'Supplier', 'integer', isset($this->ext['proc_suppliers_grid']) ? $this->lookup($this->ext['proc_suppliers_grid'], 'name', ['json_config' => ['fill' => ['counterparty' => 'name']]]) : ['is_show' => false]],
            ['customer_id', 'Customer', 'integer', isset($this->ext['crm_customers_grid']) ? $this->lookup($this->ext['crm_customers_grid'], 'name', ['json_config' => ['fill' => ['counterparty' => 'name']]]) : ['is_show' => false]],
            ['counterparty', 'From / to (person or company)', 'string'],
            ['reason', 'Reason', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::REASONS]]],
            ['package_item_id', 'Package', 'integer', $this->lookup('wh_items_lookup', 'label')],
            ['package_qty', 'Packages', 'decimal', ['min_value' => '0']],
            ['keeper_id', 'Store keeper', 'integer', ['default_value' => '{user_id}'] + $this->lookup('wh_users_lookup')],
            ['post_on_save', 'Post on save', 'boolean', ['default_value' => '1']],
            ['create_work', 'Make a work for the keeper', 'boolean', ['default_value' => '0']],
            ['status', 'Status', 'string', ['default_value' => 'draft', 'render_type' => 'select', 'json_config' => ['options' => ['draft', 'posted', 'cancelled']]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['lines', 'Goods', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'document_id', 'amount' => ['quantity', 'unit_cost'], 'total_field' => 'total_amount']]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);

        $grid = $this->meta('wh_documents_grid', 'Stock documents', 'datagrid', 'wh_documents', 'Every stock document: receipts, purchases, income, returns, sales, outcome, disposals, movements and packaging.', null, [
            'filter_layout' => 'sidebar',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print'],
        ], <<<'SQL'
            SELECT d.id, d.doc_no, d.doc_type, d.doc_date, d.warehouse_id, d.to_warehouse_id, d.order_id, d.request_id,
                   d.counterparty, d.reason, d.keeper_id, d.status, d.total_amount, d.posted_at,
                   (SELECT COUNT(*) FROM wh_document_lines l WHERE l.document_id = d.id) AS line_count
            FROM wh_documents d
            WHERE (:from_date IS NULL OR d.doc_date >= :from_date)
              AND (:to_date IS NULL OR d.doc_date <= :to_date)
            SQL);
        $this->actions($grid, $form);
        $this->button($grid, 'stage_query', 'Post', 'check', $this->m['wh_post_document'], null, 4, ['document_id' => 'id']);
        $this->button($grid, 'stage_query', 'Unpost', 'file', $this->m['wh_unpost_document'], null, 5, ['document_id' => 'id']);
        $this->button($grid, 'stage_query', 'Fill lines', 'database', $this->m['wh_fill_document_lines'], null, 6, ['document_id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['doc_no', 'No', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['doc_type', 'Type', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DOC_TYPES]]],
            ['doc_date', 'Date', 'date'],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['to_warehouse_id', 'To', 'integer', $this->lookup('wh_warehouses_grid')],
            ['counterparty', 'From / to', 'string', ['is_criteria' => true]],
            ['reason', 'Reason', 'string', ['is_criteria' => true]],
            ['order_id', 'Order', 'integer', $this->lookup('wh_orders_lookup', 'label')],
            ['request_id', 'Request', 'integer', $this->lookup('wh_requests_lookup', 'label')],
            ['keeper_id', 'Keeper', 'integer', ['is_criteria' => true] + $this->lookup('wh_users_lookup')],
            ['line_count', 'Lines', 'integer'],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['draft', 'posted', 'cancelled']]]],
            ['posted_at', 'Posted', 'datetime'],
            ['from_date', 'From', 'date', ['is_criteria' => true, 'is_show' => false]],
            ['to_date', 'To', 'date', ['is_criteria' => true, 'is_show' => false]],
        ]);

        $tab = fn (string $field, string $label, string $type) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['doc_type' => $type], 'defaults' => ['doc_type' => $type]]]];
        $this->params($this->meta('wh_returns_tabs', 'Returns', 'tabs', null, 'Goods coming back from customers and going back to suppliers.'), [
            $tab('return_in', 'From customers', 'return_in'),
            $tab('return_out', 'To suppliers', 'return_out'),
        ]);

        // Lines of documents, as a register (read-only; edited in the document).
        $lines = $this->meta('wh_document_lines_grid', 'Document lines', 'datagrid', 'wh_document_lines', 'Every line of every stock document.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT l.id, l.document_id, d.doc_no, d.doc_type, d.doc_date, d.warehouse_id, d.status, l.item_id, l.direction,
                   l.location_id, l.to_location_id, l.quantity, l.unit_cost, l.amount, l.lot_no, l.expiry_date
            FROM wh_document_lines l
            JOIN wh_documents d ON d.id = l.document_id
            SQL);
        $this->params($lines, [
            ['id', 'ID', 'integer', $this->key()],
            ['doc_no', 'Document', 'string', ['is_criteria' => true]],
            ['doc_type', 'Type', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DOC_TYPES]]],
            ['doc_date', 'Date', 'date'],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['item_id', 'Item', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['direction', 'In/out', 'string', ['is_criteria' => true]],
            ['location_id', 'Location', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['to_location_id', 'To location', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['quantity', 'Qty', 'decimal'],
            ['unit_cost', 'Unit cost', 'decimal', ['render_type' => 'money']],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['lot_no', 'Lot', 'string', ['is_criteria' => true]],
            ['expiry_date', 'Expires', 'date'],
            ['status', 'Status', 'string', ['is_criteria' => true]],
            ['document_id', 'Document', 'integer', ['is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- warehouse orders

    private function orders(): void
    {
        $line = $this->meta('wh_order_line_form', 'Order line', 'form', 'wh_order_lines', 'An item a warehouse order asks for.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['order_id', 'Order', 'integer', ['is_show' => false]],
            ['item_id', 'Item', 'integer', ['is_required' => true] + $this->lookup('wh_items_lookup', 'label', ['json_config' => ['fill' => ['unit_price' => 'unit_price']]])],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['unit_price', 'Unit price', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['notes', 'Note', 'string'],
        ]);

        $kinds = ['inbound', 'outbound', 'transfer'];
        $statuses = ['open', 'partial', 'done', 'cancelled'];
        $form = $this->meta('wh_order_form', 'Warehouse order', 'form', 'wh_orders', 'An order to the warehouse: goods to receive (inbound), to ship (outbound) or to move to another warehouse (transfer). Fulfil it with a document.', null, null, null, [
            'var_fnc_expression_string' => <<<'JS'
                function fit() {
                  const k = get('kind');
                  hide('to_warehouse_id', k !== 'transfer');
                  hide('supplier_id', k !== 'inbound');
                  hide('customer_id', k !== 'outbound');
                }
                JS,
            'load_expression_string' => 'fit();',
            'event_expression_string' => "if (field === 'kind') fit();",
            'before_save_expression_string' => <<<'JS'
                if (lines('lines').length === 0) {
                  error('An order needs at least one line.');
                }
                if (get('kind') === 'transfer' && (!get('to_warehouse_id') || get('to_warehouse_id') == get('warehouse_id'))) {
                  error('A transfer goes to another warehouse.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['order_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'WO-{yy}{MM}{seq:4}']]]],
            ['kind', 'Kind', 'string', ['is_required' => true, 'is_label' => true] + $this->options($kinds, 'inbound')],
            ['order_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['expected_date', 'Expected', 'date'],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['to_warehouse_id', 'To warehouse', 'integer', $this->lookup('wh_warehouses_grid')],
            ['supplier_id', 'Supplier', 'integer', isset($this->ext['proc_suppliers_grid']) ? $this->lookup($this->ext['proc_suppliers_grid']) : ['is_show' => false]],
            ['customer_id', 'Customer', 'integer', isset($this->ext['crm_customers_grid']) ? $this->lookup($this->ext['crm_customers_grid']) : ['is_show' => false]],
            ['priority', 'Priority', 'string', $this->options(self::PRIORITIES, 'normal')],
            ['status', 'Status', 'string', $this->options($statuses, 'open')],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('wh_users_lookup')],
            ['lines', 'Goods', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'order_id']]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $fulfil = $this->meta('wh_document_from_order_get', 'Document for order', 'get', null, 'A receipt, outcome or movement for the picked order; its lines come from what is still to do.', null, null, <<<'SQL'
            SELECT CASE o.kind WHEN 'inbound' THEN 'receipt' WHEN 'outbound' THEN 'outcome' ELSE 'movement' END AS doc_type,
                   :today AS doc_date, o.warehouse_id, o.to_warehouse_id, o.id AS order_id, o.supplier_id, o.customer_id,
                   1 AS post_on_save, 0 AS create_work, 'draft' AS status
            FROM wh_orders o WHERE o.id = :id
            SQL);
        $this->params($fulfil, [['id', 'Order', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $grid = $this->meta('wh_orders_grid', 'Warehouse orders', 'datagrid', 'wh_orders', 'Orders to receive, ship or transfer, with how much is done.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT o.id, o.order_no, o.kind, o.order_date, o.expected_date, o.warehouse_id, o.to_warehouse_id, o.supplier_id, o.customer_id,
                   o.priority, o.status, o.owner_id,
                   COALESCE(t.ordered, 0) AS ordered_qty, COALESCE(t.done, 0) AS done_qty,
                   CASE WHEN t.ordered > 0 THEN ROUND(100 * LEAST(t.done, t.ordered) / t.ordered, 1) ELSE 0 END AS done_pct,
                   CASE WHEN o.status IN ('open', 'partial') AND o.expected_date < :today THEN 1 ELSE 0 END AS is_late
            FROM wh_orders o
            LEFT JOIN (SELECT ol.order_id, SUM(ol.quantity) AS ordered, SUM(COALESCE(x.done, 0)) AS done
                       FROM wh_order_lines ol
                       LEFT JOIN (SELECT l.order_line_id, SUM(l.quantity) AS done FROM wh_document_lines l JOIN wh_documents p ON p.id = l.document_id
                                  WHERE p.status = 'posted' AND l.order_line_id IS NOT NULL GROUP BY l.order_line_id) x ON x.order_line_id = ol.id
                       GROUP BY ol.order_id) t ON t.order_id = o.id
            SQL);
        $this->actions($grid, $form);
        $this->button($grid, 'custom', 'Fulfil', 'truck', $this->m['wh_document_form'], $fulfil, 4, ['id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['order_no', 'No', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => $kinds]]],
            ['order_date', 'Date', 'date'],
            ['expected_date', 'Expected', 'date'],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['to_warehouse_id', 'To', 'integer', $this->lookup('wh_warehouses_grid')],
            ['supplier_id', 'Supplier', 'integer', isset($this->ext['proc_suppliers_grid']) ? $this->lookup($this->ext['proc_suppliers_grid']) : ['is_show' => false]],
            ['customer_id', 'Customer', 'integer', isset($this->ext['crm_customers_grid']) ? $this->lookup($this->ext['crm_customers_grid']) : ['is_show' => false]],
            ['priority', 'Priority', 'string', ['is_criteria' => true]],
            ['ordered_qty', 'Ordered', 'decimal'],
            ['done_qty', 'Done', 'decimal'],
            ['done_pct', 'Done %', 'decimal'],
            ['is_late', 'Late', 'boolean', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $statuses]]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('wh_users_lookup')],
        ]);

        $tab = fn (string $field, string $label, array $config) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $config ?: null]];
        $this->params($this->meta('wh_orders_tabs', 'Warehouse orders', 'tabs', null, 'Orders by kind, still to do, and all.'), [
            $tab('inbound', 'Inbound', ['fixed' => ['kind' => 'inbound'], 'defaults' => ['kind' => 'inbound']]),
            $tab('outbound', 'Outbound', ['fixed' => ['kind' => 'outbound'], 'defaults' => ['kind' => 'outbound']]),
            $tab('transfer', 'Transfers', ['fixed' => ['kind' => 'transfer'], 'defaults' => ['kind' => 'transfer']]),
            $tab('late', 'Late', ['fixed' => ['is_late' => '1']]),
            $tab('done', 'Done', ['filters' => ['status' => 'done']]),
            $tab('all', 'All', []),
        ]);
    }

    // ---------------------------------------------------------------- requests

    private function requests(): void
    {
        $line = $this->meta('wh_request_line_form', 'Request line', 'form', 'wh_request_lines', 'An item asked for; the approver may give less.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['request_id', 'Request', 'integer', ['is_show' => false]],
            ['item_id', 'Item', 'integer', ['is_required' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['quantity', 'Asked', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['approved_qty', 'Approved', 'decimal', ['min_value' => '0']],
            ['notes', 'Note', 'string'],
        ]);

        $form = $this->meta('wh_request_form', 'Stock request', 'form', 'wh_requests', 'A request for goods from a warehouse: what, how many, by when and why. Approved, it is issued with an outcome document.', null, null, null, [
            'before_save_expression_string' => <<<'JS'
                const rows = lines('lines');
                if (rows.length === 0) {
                  error('A request needs at least one line.');
                }
                if (rows.some((l) => num(l.quantity) <= 0)) {
                  error('Every line needs a quantity above zero.');
                }
                if (rows.some((l) => l.approved_qty !== null && l.approved_qty !== '' && num(l.approved_qty) > num(l.quantity))) {
                  error('A line is approved for more than was asked.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['request_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'REQ-{yy}{MM}{seq:4}']]]],
            ['request_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['needed_by', 'Needed by', 'date'],
            ['requested_by', 'Requested by', 'integer', ['default_value' => '{user_id}'] + $this->lookup('wh_users_lookup')],
            ['department', 'Department', 'string'],
            ['warehouse_id', 'From warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['purpose', 'What for', 'string', ['is_label' => true]],
            ['lines', 'Goods', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'request_id']]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $issue = $this->meta('wh_document_from_request_get', 'Issue for request', 'get', null, 'An outcome for the picked request; its lines come from what is approved and not yet issued.', null, null, <<<'SQL'
            SELECT 'outcome' AS doc_type, :today AS doc_date, r.warehouse_id, r.id AS request_id, 'internal_use' AS reason,
                   COALESCE(NULLIF(r.department, ''), u.name) AS counterparty, 1 AS post_on_save, 0 AS create_work, 'draft' AS status
            FROM wh_requests r LEFT JOIN users u ON u.id = r.requested_by
            WHERE r.id = :id
            SQL);
        $this->params($issue, [['id', 'Request', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $grid = $this->meta('wh_requests_grid', 'Stock requests', 'datagrid', 'wh_requests', 'Requests for goods, from draft to issued.', $this->m['wh_request_workflow'], ['filter_layout' => 'sidebar']);
        $this->actions($grid, $form);
        $this->button($grid, 'custom', 'Issue', 'package', $this->m['wh_document_form'], $issue, 4, ['id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['request_no', 'No', 'string', ['is_criteria' => true]],
            ['request_date', 'Date', 'date'],
            ['needed_by', 'Needed by', 'date'],
            ['requested_by', 'Requested by', 'integer', ['is_criteria' => true] + $this->lookup('wh_users_lookup')],
            ['department', 'Department', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['purpose', 'What for', 'string', ['is_label' => true]],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);

        $s = $this->status;
        $tab = fn (string $field, string $label, array $config) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $config ?: null]];
        $this->params($this->meta('wh_requests_tabs', 'Stock requests', 'tabs', null, 'Requests to approve, to issue, your own, and all.'), [
            $tab('submitted', 'To approve', ['filters' => ['status_id' => (string) $s['submitted']]]),
            $tab('approved', 'To issue', ['filters' => ['status_id' => (string) $s['approved']]]),
            $tab('issued', 'Issued', ['filters' => ['status_id' => (string) $s['issued']]]),
            $tab('mine', 'Mine', ['fixed' => ['requested_by' => '{user_id}']]),
            $tab('all', 'All', []),
        ]);
    }

    // ---------------------------------------------------------------- works of store keepers

    private function works(): void
    {
        $statuses = ['todo', 'doing', 'done', 'cancelled'];
        $form = $this->meta('wh_work_form', 'Warehouse work', 'form', 'wh_works', 'A job of a store keeper: pick, put away, move, pack, count, load... Made for a document it takes its kind, title and keeper from there.', null, null, null, [
            'load_expression_string' => "readonly('started_at');\nreadonly('finished_at');",
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['work_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'WK-{yy}{MM}{seq:4}']]]],
            ['kind', 'Kind', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::WORK_KINDS]]],
            ['title', 'Work', 'string', ['is_label' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_required' => true] + $this->lookup('wh_warehouses_grid')],
            ['location_id', 'Location', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['document_id', 'Document', 'integer', $this->lookup('wh_documents_lookup', 'label', ['json_config' => ['fill' => ['warehouse_id' => 'warehouse_id']]])],
            ['order_id', 'Order', 'integer', $this->lookup('wh_orders_lookup', 'label')],
            ['assigned_to', 'Keeper', 'integer', $this->lookup('wh_users_lookup')],
            ['priority', 'Priority', 'string', $this->options(self::PRIORITIES, 'normal')],
            ['planned_on', 'Planned', 'date', ['default_value' => '{today}']],
            ['status', 'Status', 'string', $this->options($statuses, 'todo')],
            ['started_at', 'Started', 'datetime'],
            ['finished_at', 'Finished', 'datetime'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $start = $this->stageQuery('wh_start_work', 'Start work', 'wh_works', <<<'SQL'
            UPDATE wh_works SET status = 'doing', assigned_to = COALESCE(assigned_to, :user_id), updated_at = NOW()
            WHERE id = :work_id AND status = 'todo'
            SQL, 'Starts a work (the time is noted); whoever starts an unassigned work takes it.', [
            ['work_id', 'Work', 'integer', ['is_required' => true]],
        ]);
        $finish = $this->stageQuery('wh_finish_work', 'Finish work', 'wh_works', <<<'SQL'
            UPDATE wh_works SET status = 'done', assigned_to = COALESCE(assigned_to, :user_id), updated_at = NOW()
            WHERE id = :work_id AND status IN ('todo', 'doing')
            SQL, 'Finishes a work (the time is noted).', [
            ['work_id', 'Work', 'integer', ['is_required' => true]],
        ]);

        $grid = $this->meta('wh_works_grid', 'Warehouse works', 'datagrid', 'wh_works', 'Jobs of store keepers and how long they took.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT w.id, w.work_no, w.kind, w.title, w.warehouse_id, w.location_id, w.document_id, w.order_id, w.assigned_to,
                   w.priority, w.planned_on, w.started_at, w.finished_at, w.status,
                   CASE WHEN w.started_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, w.started_at, COALESCE(w.finished_at, NOW())) END AS minutes,
                   CASE WHEN w.status IN ('todo', 'doing') AND w.planned_on < :today THEN 1 ELSE 0 END AS is_late
            FROM wh_works w
            SQL);
        $this->actions($grid, $form);
        $this->button($grid, 'stage_query', 'Start', 'zap', $start, null, 4, ['work_id' => 'id']);
        $this->button($grid, 'stage_query', 'Finish', 'check', $finish, null, 5, ['work_id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['work_no', 'No', 'string', ['is_criteria' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::WORK_KINDS]]],
            ['title', 'Work', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['location_id', 'Location', 'integer', $this->lookup('wh_locations_lookup', 'label')],
            ['document_id', 'Document', 'integer', $this->lookup('wh_documents_lookup', 'label')],
            ['assigned_to', 'Keeper', 'integer', ['is_criteria' => true] + $this->lookup('wh_users_lookup')],
            ['priority', 'Priority', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::PRIORITIES]]],
            ['planned_on', 'Planned', 'date'],
            ['started_at', 'Started', 'datetime'],
            ['finished_at', 'Finished', 'datetime'],
            ['minutes', 'Minutes', 'integer', ['json_config' => ['aggregate' => 'sum']]],
            ['is_late', 'Late', 'boolean', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $statuses]]],
            ['order_id', 'Order', 'integer', ['is_show' => false]],
        ]);

        $tab = fn (string $field, string $label, array $config) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $config ?: null]];
        $this->params($this->meta('wh_works_tabs', 'Warehouse works', 'tabs', null, 'Works to do, in progress, done, and your own.'), [
            $tab('mine', 'My works', ['fixed' => ['assigned_to' => '{user_id}'], 'filters' => ['status' => 'todo']]),
            $tab('todo', 'To do', ['filters' => ['status' => 'todo']]),
            $tab('doing', 'In progress', ['filters' => ['status' => 'doing']]),
            $tab('done', 'Done', ['filters' => ['status' => 'done']]),
            $tab('all', 'All', []),
        ]);

        // A new document: lines filled when it has none, then posted if asked, and a work made if asked.
        $this->flow($this->m['wh_document_form'], [
            'fill' => ['process' => $this->m['wh_fill_document_lines'], 'map' => ['document_id' => 'id', 'post_on_save' => 'post_on_save']],
            'post' => ['process' => $this->m['wh_post_document'], 'after' => 'fill', 'criteria' => [['field' => 'post_on_save', 'op' => '=', 'value' => '1']], 'map' => ['document_id' => 'document_id']],
            'work' => ['process' => $form, 'criteria' => [['field' => 'create_work', 'op' => '=', 'value' => '1']], 'map' => [
                'document_id' => 'id', 'warehouse_id' => 'warehouse_id', 'order_id' => 'order_id', 'planned_on' => 'doc_date', 'status' => '=todo', 'priority' => '=normal',
            ]],
        ]);
    }

    // ---------------------------------------------------------------- stock

    private function stocks(): void
    {
        $byLocation = $this->meta('wh_stocks_grid', 'Stock by location', 'datagrid', 'wh_stocks', 'What is where: each item in each warehouse location, at average cost.', null, [
            'filter_layout' => 'sidebar', 'group_by' => ['warehouse_id'],
        ], <<<'SQL'
            SELECT s.id, s.item_id, i.code AS item_code, c.name AS category, s.warehouse_id, NULLIF(s.location_id, 0) AS location_id,
                   s.quantity, i.unit, CASE WHEN s.quantity <> 0 THEN ROUND(s.amount / s.quantity, 4) END AS avg_cost, s.amount, s.last_move_on
            FROM wh_stocks s
            JOIN proc_items i ON i.id = s.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            WHERE s.quantity <> 0
            SQL);
        $this->params($byLocation, [
            ['id', 'ID', 'integer', $this->key()],
            ['item_id', 'Item', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['item_code', 'Code', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['location_id', 'Location', 'integer', ['is_criteria' => true] + $this->lookup('wh_locations_lookup', 'label')],
            ['quantity', 'On hand', 'decimal'],
            ['unit', 'Unit', 'string'],
            ['avg_cost', 'Avg cost', 'decimal', ['render_type' => 'money']],
            ['amount', 'Value', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['last_move_on', 'Last move', 'date'],
        ]);

        $balances = $this->meta('wh_stock_balances', 'Stock by warehouse', 'datagrid', 'wh_stocks', 'Each item in each warehouse with its stock levels; below the minimum it is low and shows what to reorder.', null, [
            'filter_layout' => 'sidebar',
        ], <<<'SQL'
            SELECT b.id, b.item_id, i.code AS item_code, c.name AS category, b.warehouse_id, b.quantity, i.unit,
                   CASE WHEN b.quantity <> 0 THEN ROUND(b.amount / b.quantity, 4) END AS avg_cost, b.amount,
                   b.min_qty, b.max_qty,
                   CASE WHEN b.min_qty IS NOT NULL AND b.quantity < b.min_qty THEN 1 ELSE 0 END AS below_min,
                   CASE WHEN b.min_qty IS NOT NULL AND b.quantity < b.min_qty
                        THEN GREATEST(COALESCE(b.reorder_qty, b.max_qty - b.quantity, b.min_qty - b.quantity), b.min_qty - b.quantity) END AS to_order
            FROM (SELECT MIN(x.id) AS id, x.item_id, x.warehouse_id, SUM(x.quantity) AS quantity, SUM(x.amount) AS amount,
                         MAX(x.min_qty) AS min_qty, MAX(x.max_qty) AS max_qty, MAX(x.reorder_qty) AS reorder_qty
                  FROM (SELECT s.id, s.item_id, s.warehouse_id, s.quantity, s.amount, NULL AS min_qty, NULL AS max_qty, NULL AS reorder_qty FROM wh_stocks s
                        UNION ALL
                        SELECT 1000000000 + v.id, v.item_id, v.warehouse_id, 0, 0, v.min_qty, v.max_qty, v.reorder_qty FROM wh_stock_levels v) x
                  GROUP BY x.item_id, x.warehouse_id) b
            JOIN proc_items i ON i.id = b.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            WHERE b.quantity <> 0 OR b.min_qty IS NOT NULL
            SQL);
        $this->params($balances, [
            ['id', 'ID', 'integer', $this->key()],
            ['item_id', 'Item', 'integer', ['is_criteria' => true, 'is_label' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['item_code', 'Code', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['quantity', 'On hand', 'decimal'],
            ['unit', 'Unit', 'string'],
            ['avg_cost', 'Avg cost', 'decimal', ['render_type' => 'money']],
            ['amount', 'Value', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['min_qty', 'Minimum', 'decimal'],
            ['max_qty', 'Maximum', 'decimal'],
            ['below_min', 'Low', 'boolean', ['is_criteria' => true]],
            ['to_order', 'To order', 'decimal'],
        ]);

        $card = $this->meta('wh_stock_moves_grid', 'Stock card', 'datagrid', 'wh_stock_moves', 'Every move of stock: in and out by document, at cost. Pick an item and a warehouse for its card.', null, [
            'filter_layout' => 'sidebar',
            'sort' => [['field' => 'moved_on', 'dir' => 'desc']],
            'drilldown' => ['meta_id' => $this->m['wh_document_report']->id, 'params' => ['id' => 'document_id'], 'label' => 'Document'],
        ], <<<'SQL'
            SELECT m.id, m.moved_on, m.document_id, d.doc_no, m.doc_type, m.item_id, m.warehouse_id, m.location_id,
                   CASE WHEN m.quantity > 0 THEN m.quantity END AS qty_in,
                   CASE WHEN m.quantity < 0 THEN -m.quantity END AS qty_out,
                   m.unit_cost, m.amount, d.counterparty
            FROM wh_stock_moves m
            JOIN wh_documents d ON d.id = m.document_id
            WHERE (:from_date IS NULL OR m.moved_on >= :from_date)
              AND (:to_date IS NULL OR m.moved_on <= :to_date)
            SQL);
        $this->params($card, [
            ['id', 'ID', 'integer', $this->key()],
            ['moved_on', 'Date', 'date'],
            ['doc_no', 'Document', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['doc_type', 'Type', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DOC_TYPES]]],
            ['item_id', 'Item', 'integer', ['is_criteria' => true] + $this->lookup('wh_items_lookup', 'label')],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $this->lookup('wh_warehouses_grid')],
            ['location_id', 'Location', 'integer', ['is_criteria' => true] + $this->lookup('wh_locations_lookup', 'label')],
            ['qty_in', 'In', 'decimal', ['json_config' => ['aggregate' => 'sum']]],
            ['qty_out', 'Out', 'decimal', ['json_config' => ['aggregate' => 'sum']]],
            ['unit_cost', 'Unit cost', 'decimal', ['render_type' => 'money']],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['counterparty', 'From / to', 'string', ['is_criteria' => true]],
            ['document_id', 'Document', 'integer', ['is_show' => false]],
            ['from_date', 'From', 'date', ['is_criteria' => true, 'is_show' => false]],
            ['to_date', 'To', 'date', ['is_criteria' => true, 'is_show' => false]],
        ]);

        $this->params($this->meta('wh_stock_tabs', 'Stock', 'tabs', null, 'Stock by warehouse, by location, and what is below its minimum.'), [
            ['warehouse', 'By warehouse', 'string', ['ref_meta_id' => $balances->id]],
            ['location', 'By location', 'string', ['ref_meta_id' => $byLocation->id]],
            ['low', 'Below minimum', 'string', ['ref_meta_id' => $balances->id, 'json_config' => ['fixed' => ['below_min' => '1']]]],
        ]);
    }

    // ---------------------------------------------------------------- the warehouse workspace

    private function workspace(): void
    {
        $summary = $this->meta('wh_warehouse_summary', 'Warehouse summary', 'datagrid', null, 'One warehouse at a glance: stock, low items, open works, orders and requests.', null, null, <<<'SQL'
            SELECT w.id AS warehouse_id,
                   (SELECT COALESCE(SUM(s.amount), 0) FROM wh_stocks s WHERE s.warehouse_id = w.id) AS stock_value,
                   (SELECT COUNT(DISTINCT s.item_id) FROM wh_stocks s WHERE s.warehouse_id = w.id AND s.quantity > 0) AS items,
                   (SELECT COUNT(*) FROM wh_stock_levels v WHERE v.warehouse_id = w.id
                      AND v.min_qty > COALESCE((SELECT SUM(s.quantity) FROM wh_stocks s WHERE s.item_id = v.item_id AND s.warehouse_id = v.warehouse_id), 0)) AS below_min,
                   (SELECT COUNT(*) FROM wh_locations l WHERE l.warehouse_id = w.id) AS locations,
                   (SELECT COUNT(*) FROM wh_store_keepers k WHERE k.warehouse_id = w.id AND k.is_active = 1) AS keepers,
                   (SELECT COUNT(*) FROM wh_works x WHERE x.warehouse_id = w.id AND x.status IN ('todo', 'doing')) AS open_works,
                   (SELECT COUNT(*) FROM wh_documents d WHERE d.warehouse_id = w.id AND d.status = 'draft') AS drafts,
                   (SELECT COUNT(*) FROM wh_orders o WHERE (o.warehouse_id = w.id OR o.to_warehouse_id = w.id) AND o.status IN ('open', 'partial')) AS open_orders,
                   (SELECT COUNT(*) FROM wh_requests r WHERE r.warehouse_id = w.id AND r.status_id = {approved}) AS requests_to_issue,
                   (SELECT COUNT(*) FROM wh_documents d WHERE d.warehouse_id = w.id AND d.status = 'posted'
                      AND d.doc_date >= DATE_FORMAT(:today, '%Y-%m-01')) AS posted_this_month
            FROM wh_warehouses w
            WHERE w.id = :warehouse_id
            SQL);
        $summary->update(['query_string' => str_replace('{approved}', (string) $this->status['approved'], $summary->query_string)]);
        $this->params($summary, [
            ['warehouse_id', 'Warehouse', 'integer', ['is_primary' => true, 'is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
            ['stock_value', 'Stock value', 'decimal', ['render_type' => 'money']],
            ['items', 'Items in stock', 'integer'],
            ['below_min', 'Below minimum', 'integer'],
            ['locations', 'Locations', 'integer'],
            ['keepers', 'Keepers', 'integer'],
            ['open_works', 'Open works', 'integer'],
            ['drafts', 'Draft documents', 'integer'],
            ['open_orders', 'Open orders', 'integer'],
            ['requests_to_issue', 'Requests to issue', 'integer'],
            ['posted_this_month', 'Posted this month', 'integer'],
        ]);

        $workspace = $this->meta('wh_warehouse_workspace', 'Warehouse workspace', 'workspace', 'wh_warehouses', 'One warehouse and everything in it: stock, locations, keepers, documents, works, orders and requests.', $this->m['wh_warehouse_form'], [
            'header' => ['code', 'kind', 'manager_id', 'address'],
            'title_field' => 'name',
        ]);
        $scope = ['scope' => ['warehouse_id' => 'id']];
        $sections = [
            ['summary', 'Summary', 'wh_warehouse_summary'],
            ['stock', 'Stock', 'wh_stock_balances'],
            ['locations_stock', 'Stock by location', 'wh_stocks_grid'],
            ['documents', 'Documents', 'wh_documents_grid'],
            ['works', 'Works', 'wh_works_grid'],
            ['orders', 'Orders', 'wh_orders_grid'],
            ['requests', 'Requests', 'wh_requests_grid'],
            ['card', 'Stock card', 'wh_stock_moves_grid'],
            ['locations', 'Locations', 'wh_locations_grid'],
            ['keepers', 'Store keepers', 'wh_store_keepers_grid'],
            ['levels', 'Stock levels', 'wh_stock_levels_grid'],
        ];
        $this->params($workspace, array_map(fn ($x) => [$x[0], $x[1], 'string', ['ref_meta_id' => $this->m[$x[2]]->id, 'json_config' => $scope]], $sections));

        $this->m['wh_warehouses_grid']->update(['json_config' => ['drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace']]]);
    }

    // ---------------------------------------------------------------- notifications

    private function notifications(): void
    {
        $workflow = $this->m['wh_request_workflow'];
        $this->notification('wh_notify_request_submitted', 'Stock request to approve', 'Tells the warehouse team when a request is submitted.', [
            'event' => 'status_changed', 'workflow_meta_id' => $workflow->id, 'status_id' => $this->status['submitted'],
            'kind' => 'info', 'title' => 'Request {request_no} is waiting for approval',
            'body' => '{purpose} ({department}), needed by {needed_by}. Sent by {actor}.',
            'link_meta_id' => $this->m['wh_requests_grid']->id, 'link_record' => true,
        ], [['role', 'warehouse']]);
        $this->notification('wh_notify_request_approved', 'Stock request approved', 'Tells the requester when their request is approved.', [
            'event' => 'status_changed', 'workflow_meta_id' => $workflow->id, 'status_id' => $this->status['approved'],
            'kind' => 'success', 'title' => 'Request {request_no} is approved',
            'body' => 'The warehouse will issue it.',
            'link_meta_id' => $this->m['wh_requests_grid']->id, 'link_record' => true,
        ], [['field', 'requested_by']]);
        $this->notification('wh_notify_request_rejected', 'Stock request rejected', 'Tells the requester when their request is rejected.', [
            'event' => 'status_changed', 'workflow_meta_id' => $workflow->id, 'status_id' => $this->status['rejected'],
            'kind' => 'danger', 'title' => 'Request {request_no} was rejected',
            'body' => '{purpose}',
            'link_meta_id' => $this->m['wh_requests_grid']->id, 'link_record' => true,
        ], [['field', 'requested_by']]);
        $this->notification('wh_notify_work_assigned', 'Warehouse work assigned', 'Tells a store keeper about a new work for them.', [
            'event' => 'created', 'source_meta_id' => $this->m['wh_work_form']->id,
            'kind' => 'info', 'title' => 'New work: {title}',
            'body' => '{kind} in {warehouse_id}, planned {planned_on}, {priority} priority.',
            'link_meta_id' => $this->m['wh_works_grid']->id, 'link_record' => true,
        ], [['field', 'assigned_to']]);
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): void
    {
        $approved = (string) $this->status['approved'];
        $toGrid = fn (string $grid, array $params = []) => ['drilldown' => ['meta_id' => $this->m[$grid]->id, 'params' => $params]];
        $charts = [
            [$this->chart('wh_chart_stock_value', 'Stock value', 'wh_stocks', null, 'What everything in stock is worth, at average cost.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Value']], 'format' => 'money'] + $toGrid('wh_stock_balances')), 3, 1],
            [$this->chart('wh_chart_items_in_stock', 'Items in stock', null, 'SELECT item_id FROM wh_stocks GROUP BY item_id HAVING SUM(quantity) > 0', 'Different items on hand.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Items']]]), 3, 1],
            [$this->chart('wh_chart_below_min', 'Below minimum', null, <<<'SQL'
                SELECT v.id FROM wh_stock_levels v
                WHERE v.min_qty > COALESCE((SELECT SUM(s.quantity) FROM wh_stocks s WHERE s.item_id = v.item_id AND s.warehouse_id = v.warehouse_id), 0)
                SQL, 'Items a warehouse holds less of than its minimum.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Items']]]), 3, 1],
            [$this->chart('wh_chart_open_works', 'Open works', null, "SELECT id FROM wh_works WHERE status IN ('todo', 'doing')", 'Works to do or in progress.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Works']]] + $toGrid('wh_works_grid')), 3, 1],
            [$this->chart('wh_chart_requests_to_issue', 'Requests to issue', null, "SELECT id FROM wh_requests WHERE status_id = {$approved}", 'Approved requests not yet issued.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Requests']]] + $toGrid('wh_requests_grid')), 3, 1],
            [$this->chart('wh_chart_open_orders', 'Open orders', null, "SELECT id FROM wh_orders WHERE status IN ('open', 'partial')", 'Warehouse orders still to fulfil.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Orders']]] + $toGrid('wh_orders_grid')), 3, 1],
            [$this->chart('wh_chart_drafts', 'Draft documents', null, "SELECT id FROM wh_documents WHERE status = 'draft'", 'Documents saved but not posted.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Documents']]] + $toGrid('wh_documents_grid')), 3, 1],
            [$this->chart('wh_chart_posted', 'Posted documents', 'wh_documents', null, 'Documents posted in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Documents']], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'posted']], 'date_field' => 'doc_date']), 3, 1],
            [$this->chart('wh_chart_value_by_warehouse', 'Stock value by warehouse', null, <<<'SQL'
                SELECT w.name AS warehouse, s.amount, s.quantity FROM wh_stocks s JOIN wh_warehouses w ON w.id = s.warehouse_id
                SQL, 'What each warehouse holds, at cost.',
                ['type' => 'bar', 'x' => 'warehouse', 'sort' => 'value', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Value']], 'format' => 'money']), 6, 2],
            [$this->chart('wh_chart_in_out_by_month', 'In vs out by month', null, <<<'SQL'
                SELECT moved_on AS day, CASE WHEN amount > 0 THEN amount ELSE 0 END AS value_in, CASE WHEN amount < 0 THEN -amount ELSE 0 END AS value_out
                FROM wh_stock_moves
                SQL, 'Value of stock coming in and going out, month by month.',
                ['type' => 'column', 'x' => 'day', 'bucket' => 'month', 'date_field' => 'day', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'value_in', 'label' => 'In'],
                    ['agg' => 'sum', 'column' => 'value_out', 'label' => 'Out'],
                ]]), 6, 2],
            [$this->chart('wh_chart_documents_by_type', 'Documents by type', null, "SELECT REPLACE(doc_type, '_', ' ') AS type, doc_date, total_amount FROM wh_documents WHERE status = 'posted'", 'Posted documents of each type in the period.',
                ['type' => 'donut', 'x' => 'type', 'date_field' => 'doc_date', 'measures' => [['agg' => 'count', 'label' => 'Documents']]]), 4, 2],
            [$this->chart('wh_chart_works_by_keeper', 'Works by keeper', null, <<<'SQL'
                SELECT COALESCE(u.name, 'Unassigned') AS keeper, w.status, w.planned_on,
                       CASE WHEN w.started_at IS NOT NULL AND w.finished_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, w.started_at, w.finished_at) END AS minutes
                FROM wh_works w LEFT JOIN users u ON u.id = w.assigned_to
                SQL, 'Works per store keeper by status, in the period.',
                ['type' => 'column', 'x' => 'keeper', 'split' => 'status', 'date_field' => 'planned_on', 'measures' => [['agg' => 'count', 'label' => 'Works']]]), 4, 2],
            [$this->chart('wh_chart_top_items', 'Top items by value', null, <<<'SQL'
                SELECT CONCAT(COALESCE(i.code, ''), ' ', i.name) AS item, s.amount, s.quantity
                FROM wh_stocks s JOIN proc_items i ON i.id = s.item_id
                SQL, 'The items worth the most in stock.',
                ['type' => 'table', 'x' => 'item', 'limit' => 10, 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'amount', 'label' => 'Value'],
                    ['agg' => 'sum', 'column' => 'quantity', 'label' => 'Qty'],
                ]]), 4, 2],
        ];

        $dashboard = $this->meta('wh_dashboard', 'Warehouse dashboard', 'dashboard', null, 'Stock, documents, works, orders and requests at a glance.');
        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'wh_chart_');
            MetaParam::create([
                'main_meta_id' => $dashboard->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field,
                'data_type' => 'chart', 'ref_meta_id' => $chart->id, 'json_config' => ['w' => $w, 'h' => $h], 'is_show' => true,
            ]);
        }
    }

    // ---------------------------------------------------------------- the app and who may use it

    private function app(): void
    {
        $menu = fn (string $field, string $label, string $code, array $config = [], ?string $icon = null) => [$field, $label, 'string', array_filter([
            'ref_meta_id' => $this->m[$code]->id, 'icon_cls' => $icon, 'json_config' => $config ?: null,
        ], fn ($v) => $v !== null)];
        $group = fn (string $field, string $label, string $icon, array $children) => [$field, $label, 'string', ['icon_cls' => $icon, 'children' => $children]];
        $docs = fn (string $field, string $label, string $type) => $menu($field, $label, 'wh_documents_grid', ['fixed' => ['doc_type' => $type], 'defaults' => ['doc_type' => $type]]);

        $app = $this->meta('wh_app', 'Warehouse', 'app', null, 'Warehouse management: stock, receiving, issuing, movements and packaging, orders, requests, works, warehouses and locations.');
        $this->params($app, [
            $menu('dashboard', 'Dashboard', 'wh_dashboard', [], 'chart'),
            $group('stock', 'Stock', 'database', [
                $menu('stock', 'Stock', 'wh_stock_tabs'),
                $menu('card', 'Stock card', 'wh_stock_moves_grid'),
                $menu('documents', 'All documents', 'wh_documents_grid'),
                $menu('lines', 'Document lines', 'wh_document_lines_grid'),
            ]),
            $group('receiving', 'Receiving', 'truck', [
                $docs('receipts', 'Receipts', 'receipt'),
                $docs('purchase', 'Purchase', 'purchase'),
                $docs('income', 'Income', 'income'),
                $menu('returns', 'Returns', 'wh_returns_tabs'),
            ]),
            $group('issuing', 'Issuing', 'package', [
                $docs('sales', 'Sales', 'sales'),
                $docs('outcome', 'Outcome', 'outcome'),
                $docs('dispose', 'Dispose', 'dispose'),
            ]),
            $group('internal', 'Internal', 'zap', [
                $docs('movements', 'Movements', 'movement'),
                $docs('packaging', 'Packaging', 'packaging'),
                $docs('unpackaging', 'Unpackaging', 'unpackaging'),
            ]),
            $menu('orders', 'Orders', 'wh_orders_tabs', [], 'cart'),
            $menu('requests', 'Requests', 'wh_requests_tabs', [], 'file'),
            $menu('works', 'Works', 'wh_works_tabs', [], 'tool'),
            $group('warehouses', 'Warehouses', 'home', [
                $menu('warehouses', 'Warehouses', 'wh_warehouses_grid'),
                $menu('workspace', 'Warehouse workspace', 'wh_warehouse_workspace'),
                $menu('locations', 'Locations', 'wh_locations_grid'),
                $menu('keepers', 'Store keepers', 'wh_store_keepers_grid'),
            ]),
            $group('setup', 'Setup', 'settings', [
                $menu('pack_specs', 'Pack specs', 'wh_pack_specs_grid'),
                $menu('levels', 'Stock levels', 'wh_stock_levels_grid'),
            ]),
        ]);
    }

    /** The "warehouse" role: view on every warehouse meta, full use of its forms, running its stage queries; view on the reused lookups. */
    private function access(): void
    {
        $role = Role::updateOrCreate(['code' => 'warehouse'], ['name' => 'Warehouse keeper', 'description' => 'Runs warehouses: receives, issues, moves and packs stock, fulfils orders and requests, does works.']);
        $tag = Tag::firstOrCreate(['name' => 'warehouse'], ['color' => 'teal']);
        $types = array_flip($this->types);
        $ids = [];
        foreach ($this->m as $meta) {
            $type = $types[$meta->type_id] ?? null;
            $full = $type === 'form';
            MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], [
                'can_view' => true, 'can_create' => $full, 'can_edit' => $full || $type === 'stage_query', 'can_delete' => $full,
            ]);
            $ids[] = $meta->id;
        }
        foreach ($this->ext as $meta) {
            MetaPermission::firstOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]);
        }
        DB::table('meta_tag_map')->where('tag_id', $tag->id)->whereNotIn('meta_id', $ids)->delete();
        $tagged = DB::table('meta_tag_map')->where('tag_id', $tag->id)->pluck('meta_id')->all();
        $now = now();
        foreach (array_diff($ids, $tagged) as $id) {
            DB::table('meta_tag_map')->insert(['meta_id' => $id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000009)

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null, array $extra = []): Meta
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
        ] + $extra);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes (children nest)]. */
    private function params(Meta $meta, array $rows, ?int $parentId = null): void
    {
        if ($parentId === null) {
            MetaParam::where('main_meta_id', $meta->id)->delete();
        }
        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            $extra = $row[3] ?? [];
            $children = $extra['children'] ?? [];
            unset($extra['children']);
            $param = MetaParam::create($extra + [
                'main_meta_id' => $meta->id, 'parent_id' => $parentId, 'order_number' => $i + 1,
                'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => $type, 'is_show' => true,
            ]);
            if ($children) {
                $this->params($meta, $children, $param->id);
            }
        }
    }

    private function key(): array
    {
        return ['is_primary' => true, 'is_show' => false];
    }

    private function options(array $list, string $default): array
    {
        return ['render_type' => 'select', 'default_value' => $default, 'json_config' => ['options' => $list]];
    }

    /** A lookup into a meta made here (by code) or given; $extra wins (e.g. json_config.fill). */
    private function lookup(string|Meta $grid, string $display = 'name', array $extra = []): array
    {
        $meta = $grid instanceof Meta ? $grid : $this->m[$grid];

        return $extra + ['is_select' => true, 'ref_meta_id' => $meta->id, 'render_type' => 'lookup', 'display_field' => $display];
    }

    private function actions(Meta $grid, Meta $form, ?Meta $edit = null, ?Meta $add = null): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        foreach ([
            ['add', 'Add', 'plus', $add, false, false],
            ['edit', 'Edit', 'edit', $edit, true, true],
            ['delete', 'Delete', 'trash', null, true, true],
        ] as $i => [$action, $label, $icon, $get, $needsRow, $inRow]) {
            MetaAction::create([
                'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $form->id,
                'get_meta_id' => $get?->id, 'needs_row' => $needsRow, 'show_in_row' => $inRow, 'order_number' => $i + 1,
                'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
            ]);
        }
    }

    private function button(Meta $grid, string $action, string $label, string $icon, Meta $target, ?Meta $get, int $order, array $params): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->where('label', $label)->delete();
        MetaAction::create([
            'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $target->id,
            'get_meta_id' => $get?->id, 'needs_row' => true, 'show_in_row' => true, 'order_number' => $order, 'json_config' => ['params' => $params],
        ]);
    }

    private function stageQuery(string $code, string $name, string $table, string $sql, string $description, array $params): Meta
    {
        $query = $this->meta($code, $name, 'stage_query', $table, $description, null, null, $sql);
        $this->params($query, $params);

        return $query;
    }

    private function chart(string $code, string $name, ?string $table, ?string $query, string $description, array $config): Meta
    {
        return $this->meta($code, $name, 'chart', $table, $description, null, $config, $query);
    }

    /** Replaces a form's process flow (see 2026_12_03_000001). */
    private function flow(Meta $form, array $steps): void
    {
        MetaProcessTransition::where('main_process_id', $form->id)->delete();
        MetaProcessWorkflow::where('main_process_id', $form->id)->delete();
        $ids = [];
        $order = 0;
        foreach ($steps as $key => $step) {
            $workflow = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $step['process']->id, 'order_number' => ++$order]);
            $ids[$key] = $workflow->id;
            foreach ($step['map'] as $target => $source) {
                MetaProcessBehaviour::create(['workflow_id' => $workflow->id, 'source_path' => $source, 'target_path' => $target]);
            }
        }
        foreach ($steps as $key => $step) {
            $after = $step['after'] ?? null;
            if ($after !== null || ! empty($step['criteria'])) {
                MetaProcessTransition::create([
                    'main_process_id' => $form->id, 'source_id' => $after !== null ? $ids[$after] : null,
                    'target_id' => $ids[$key], 'criteria' => $step['criteria'] ?? [],
                ]);
            }
        }
    }

    /** @param  list<array{0: string, 1: string}>  $recipients  [kind, value] */
    private function notification(string $code, string $name, string $description, array $config, array $recipients): void
    {
        $meta = $this->meta($code, $name, 'notification', null, $description, null, $config + ['exclude_actor' => true]);
        MetaParam::where('main_meta_id', $meta->id)->delete();
        foreach ($recipients as $i => [$kind, $value]) {
            MetaParam::create([
                'main_meta_id' => $meta->id, 'order_number' => $i + 1, 'field_path' => $value, 'param_name' => $value, 'data_type' => $kind, 'is_show' => true,
            ]);
        }
    }
};
