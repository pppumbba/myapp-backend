<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\WfmStatus;
use App\Models\WfmTransition;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A Procurement demo app built entirely from metas:
 *
 *   Procurement (app)
 *   ├─ Overview           -> dashboard of chart metas (ProcurementDashboardSeeder)
 *   ├─ Orders by supplier -> statement; Print an order -> report (ProcurementReportsSeeder)
 *   ├─ Purchasing (menu group)
 *   │  ├─ New request        -> Purchase request form
 *   │  ├─ Purchase requests  -> datagrid (opens the request form)
 *   │  └─ Purchase orders    -> query datagrid run by the order workflow (order form, lines as details)
 *   └─ Master data          -> tabs: Suppliers grid (with an orders subgrid), Items grid
 *
 * Grids open their forms through toolbar buttons (meta_actions, see SeedsActions). The order
 * buttons use get metas: a new order starts as a draft dated today, and editing loads the
 * picked order. The order form's "lines" param is a detail: each line is edited with the line
 * form, saved with the order, and quantity x unit price is summed into the order's total.
 *
 * Safe to run again: metas are matched by code and their params rebuilt;
 * sample records are only added to empty tables.
 */
class ProcurementDemoSeeder extends Seeder
{
    use SeedsActions;

    private const DEPARTMENTS = ['Operations', 'Finance', 'IT', 'Sales', 'HR'];

    private const UNITS = ['pcs', 'box', 'kg', 'l', 'm', 'pack'];

    /** The purchase order form's scripts: a worked-out total and a check before saving. */
    public const ORDER_FORM_SCRIPTS = [
        'var_fnc_expression_string' => <<<'JS'
            // Shared by the scripts below.
            function orderTotal() {
              return round(sum('lines', (l) => num(l.quantity) * num(l.unit_price)), 2);
            }
            JS,
        'load_expression_string' => <<<'JS'
            readonly('total_amount');
            set('total_amount', orderTotal());
            JS,
        'event_expression_string' => <<<'JS'
            // A line's quantity or unit price changed, or a line was added or removed.
            if (detail === 'lines' && (!field || field === 'quantity' || field === 'unit_price' || field === 'item_id')) {
              set('total_amount', orderTotal());
            }
            JS,
        'before_save_expression_string' => <<<'JS'
            if (orderTotal() <= 0) {
              error('Add at least one line with a quantity and a price.');
            }
            JS,
        'after_save_expression_string' => null,
    ];

    private const REQUEST_STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'ordered'];

    private const ORDER_STATUSES = ['draft', 'sent', 'partially received', 'received', 'cancelled'];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->sampleData();
            $this->metas();
        });

        // The statement, report, Overview dashboard, Excel imports, Welcome page and notifications, each also runnable on its own.
        $this->call([ProcurementReportsSeeder::class, ProcurementDashboardSeeder::class, ProcurementImportSeeder::class, ProcurementPageSeeder::class, ProcurementNotificationsSeeder::class, ProcurementStageQueriesSeeder::class, ProcurementQueriesSeeder::class]);
    }

    private function metas(): void
    {
        // Forms first, so the grids can point at them.
        // Payment terms apply only to foreign suppliers (the terms detail's criteria).
        $foreignType = DB::table('proc_supplier_types')->where('code', 'foreign')->value('id');
        $typeGrid = $this->meta('proc_supplier_types_grid', 'Supplier types', 'datagrid', 'proc_supplier_types', 'Kinds of supplier.');
        $this->params($typeGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['code', 'Code', 'string'],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
        ]);

        // One-to-one: each supplier has at most one row of payment terms, edited inside its form.
        $termsForm = $this->meta('proc_supplier_terms_form', 'Supplier payment terms', 'form', 'proc_supplier_terms', 'How and how much a supplier lets us pay.');
        $this->params($termsForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['supplier_id', 'Supplier', 'integer', ['is_show' => false]],
            ['payment_terms', 'Payment terms', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'net_30', 'json_config' => ['options' => ['prepaid', 'net_15', 'net_30', 'net_60']]]],
            ['currency', 'Currency', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'MNT', 'json_config' => ['options' => ['MNT', 'USD', 'CNY', 'EUR']]]],
            ['credit_limit', 'Credit limit', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['tax_number', 'Tax number', 'string'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $supplierForm = $this->meta('proc_supplier_form', 'Supplier form', 'form', 'proc_suppliers', 'Add or edit a supplier.');
        $this->params($supplierForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Company name', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['supplier_type_id', 'Type', 'integer', ['is_select' => true, 'ref_meta_id' => $typeGrid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['contact_name', 'Contact person', 'string'],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['country', 'Country', 'string'],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['terms', 'Payment terms', 'detail', ['ref_meta_id' => $termsForm->id, 'json_config' => ['foreign_key' => 'supplier_id', 'one' => true, 'criteria' => "supplierTypeId == {$foreignType}"]]],
        ]);

        $itemForm = $this->meta('proc_item_form', 'Item form', 'form', 'proc_items', 'Add or edit a catalog item.');
        $this->params($itemForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['code', 'Item code', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['category', 'Category', 'string'],
            ['unit', 'Unit', 'string', ['render_type' => 'select', 'default_value' => 'pcs', 'json_config' => ['options' => self::UNITS]]],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
        ]);

        $supplierGrid = $this->meta('proc_suppliers_grid', 'Suppliers', 'datagrid', 'proc_suppliers', 'Companies we buy from.');
        // Numbered rows in a fixed 720×320 px box that scrolls both ways; the company stays in view.
        $supplierGrid->update(['json_config' => [...($supplierGrid->json_config ?? []), 'row_numbers' => true, 'frozen_columns' => 1, 'height' => 320, 'width' => 720]]);
        $this->actions($supplierGrid, $supplierForm);
        $this->params($supplierGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Company', 'string', ['is_criteria' => true]],
            ['contact_name', 'Contact', 'string', ['is_criteria' => true]],
            ['email', 'Email', 'string'],
            ['country', 'Country', 'string', ['is_criteria' => true]],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        $itemGrid = $this->meta('proc_items_grid', 'Items', 'datagrid', 'proc_items', 'Everything we can order.');
        $this->actions($itemGrid, $itemForm);
        $this->params($itemGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['unit_price', 'Unit price', 'decimal', ['render_type' => 'money']],
        ]);

        $requestForm = $this->meta('proc_request_form', 'Purchase request form', 'form', 'proc_purchase_requests', 'Ask for something to be bought.');
        $this->params($requestForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['request_no', 'Request number', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['requested_by', 'Requested by', 'string'],
            ['department', 'Department', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::DEPARTMENTS]]],
            ['item_id', 'Item', 'integer', ['is_select' => true, 'is_required' => true, 'ref_meta_id' => $itemGrid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['quantity', 'Quantity', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '1']],
            ['needed_by', 'Needed by', 'date'],
            ['status', 'Status', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'draft', 'json_config' => ['options' => self::REQUEST_STATUSES]]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $deliveryForm = $this->meta('proc_order_line_delivery_form', 'Order line delivery', 'form', 'proc_order_line_deliveries', 'One delivery of an order line: when, and how many came.');
        $this->params($deliveryForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['order_line_id', 'Order line', 'integer', ['is_show' => false]],
            ['delivery_date', 'Delivered on', 'date', ['is_required' => true]],
            ['quantity', 'Qty', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '1']],
            ['note', 'Note', 'string'],
        ]);

        $lineForm = $this->meta('proc_order_line_form', 'Purchase order line', 'form', 'proc_purchase_order_lines', 'One item on a purchase order.');
        $this->params($lineForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['purchase_order_id', 'Order', 'integer', ['is_show' => false]],
            // Picking an item copies its price into the line.
            ['item_id', 'Item', 'integer', ['is_select' => true, 'is_required' => true, 'ref_meta_id' => $itemGrid->id, 'render_type' => 'lookup', 'display_field' => 'name', 'json_config' => ['fill' => ['unit_price' => 'unit_price']]]],
            ['description', 'Description', 'string'],
            ['quantity', 'Qty', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '1']],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['delivered_quantity', 'Delivered', 'integer'],
            // Each line's own lines: its deliveries, adding up into delivered_quantity.
            ['deliveries', 'Deliveries', 'detail', ['ref_meta_id' => $deliveryForm->id, 'json_config' => [
                'foreign_key' => 'order_line_id',
                'amount' => ['quantity'],
                'total_field' => 'delivered_quantity',
            ]]],
        ]);

        $orderForm = $this->meta('proc_order_form', 'Purchase order form', 'form', 'proc_purchase_orders', 'Place or update an order with a supplier.');
        // Its scripts keep the total in step while lines are edited (the server still sums them on save).
        $orderForm->update(self::ORDER_FORM_SCRIPTS);
        $this->params($orderForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            // Numbered when the order is added: PO-261021001, PO-261021002, ... starting at 001 each day.
            ['po_number', 'PO number', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true, 'json_config' => ['autonumber' => ['format' => 'PO-{yy}{MM}{dd}{seq:3}']]]],
            ['supplier_id', 'Supplier', 'integer', ['is_select' => true, 'is_required' => true, 'ref_meta_id' => $supplierGrid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['order_date', 'Order date', 'date', ['is_required' => true]],
            ['expected_date', 'Expected delivery', 'date'],
            ['total_amount', 'Total amount', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['lines', 'Order lines', 'detail', ['ref_meta_id' => $lineForm->id, 'json_config' => [
                'foreign_key' => 'purchase_order_id',
                'amount' => ['quantity', 'unit_price'],
                'total_field' => 'total_amount',
            ]]],
        ]);

        $requestGrid = $this->meta('proc_requests_grid', 'Purchase requests', 'datagrid', 'proc_purchase_requests', 'Requests from departments, from draft to ordered.');
        $this->actions($requestGrid, $requestForm);
        $this->params($requestGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['request_no', 'Request', 'string', ['is_criteria' => true]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['department', 'Department', 'string', ['is_criteria' => true]],
            ['requested_by', 'Requested by', 'string'],
            ['item_id', 'Item', 'integer', ['is_select' => true, 'ref_meta_id' => $itemGrid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['quantity', 'Qty', 'integer'],
            ['needed_by', 'Needed by', 'date'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true]],
        ]);

        // The same grid in tabs, each handing it values (a tab's json_config): Drafts and Waiting
        // start filtered (people can change it); Mine always keeps to the signed-in person, and a
        // request added there is theirs.
        $requestTabs = $this->meta('proc_requests_tabs', 'Purchase requests', 'tabs', null, 'Requests by where they stand, and your own.');
        $this->params($requestTabs, [
            ['all', 'All', 'string', ['ref_meta_id' => $requestGrid->id]],
            ['drafts', 'Drafts', 'string', ['ref_meta_id' => $requestGrid->id, 'json_config' => ['filters' => ['status' => 'draft']]]],
            ['submitted', 'Waiting for approval', 'string', ['ref_meta_id' => $requestGrid->id, 'json_config' => ['filters' => ['status' => 'submitted']]]],
            ['mine', 'Mine', 'string', ['ref_meta_id' => $requestGrid->id, 'json_config' => ['fixed' => ['requested_by' => '{user_name}']]]],
        ]);

        // Get metas decide what the order form opens with: a new order is dated today; editing
        // loads the selected order.
        $newOrder = $this->meta('proc_order_new_get', 'New order values', 'get', null, 'A new order is dated today.', null,
            'SELECT :today AS order_date');
        $this->params($newOrder, []);
        $loadOrder = $this->meta('proc_order_get', 'Load order', 'get', null, 'The order picked in the grid.', null,
            'SELECT * FROM proc_purchase_orders WHERE id = :id');
        $this->params($loadOrder, [['id', 'Order', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $workflow = $this->orderWorkflow();

        // A query grid: the supplier's name comes from a join, and :status_id, :from_date
        // and :to_date are named parameters filled from the filter panel. The workflow (its
        // ref_meta_id) moves orders between statuses.
        $orderGrid = $this->meta('proc_orders_grid', 'Purchase orders', 'datagrid', 'proc_purchase_orders', 'Orders placed with suppliers.', $workflow, <<<'SQL'
            SELECT o.id, o.po_number, s.name AS supplier_name, o.order_date, o.expected_date, o.total_amount, o.status_id
            FROM proc_purchase_orders o
            JOIN proc_suppliers s ON s.id = o.supplier_id
            WHERE (:status_id IS NULL OR o.status_id = :status_id)
              AND (:from_date IS NULL OR o.order_date >= :from_date)
              AND (:to_date IS NULL OR o.order_date <= :to_date)
            SQL);
        $this->actions($orderGrid, $orderForm, $loadOrder, $newOrder);
        $this->params($orderGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            // The footer adds up the orders' totals, over every matching order.
            ['po_number', 'PO number', 'string', ['is_criteria' => true]],
            ['supplier_name', 'Supplier', 'string', ['is_criteria' => true]],
            ['order_date', 'Ordered', 'date'],
            ['expected_date', 'Expected', 'date'],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
            ['from_date', 'Ordered from', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'Ordered to', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        // Reuses the orders grid (ref_meta_id): same query, workflow, buttons and footer. Its own
        // params only change columns: no order date, the expected date as "Due", filterable.
        $scheduleGrid = $this->meta('proc_order_schedule_grid', 'Delivery schedule', 'datagrid', null, 'Purchase orders by when they are due.', $orderGrid);
        $scheduleGrid->update(['json_config' => [...($scheduleGrid->json_config ?? []), 'row_numbers' => true]]);
        $this->params($scheduleGrid, [
            ['order_date', 'Ordered', 'date', ['is_show' => false]],
            ['expected_date', 'Due', 'date', ['is_criteria' => true]],
        ]);
        // Overrides keep the base column's place.
        MetaParam::where('main_meta_id', $scheduleGrid->id)->where('field_path', 'order_date')->update(['order_number' => 4]);
        MetaParam::where('main_meta_id', $scheduleGrid->id)->where('field_path', 'expected_date')->update(['order_number' => 5]);

        // Shown under Suppliers: pick a supplier and its orders appear. :supplier_id comes
        // from the picked row (see the subgrid param below) and is required.
        $supplierOrders = $this->meta('proc_supplier_orders_grid', 'Orders from this supplier', 'datagrid', 'proc_purchase_orders', 'Every order placed with the supplier picked above.', $workflow, <<<'SQL'
            SELECT o.id, o.po_number, o.order_date, o.expected_date, o.total_amount, o.status_id
            FROM proc_purchase_orders o
            WHERE o.supplier_id = :supplier_id
            SQL);
        $this->actions($supplierOrders, $orderForm, $loadOrder, $newOrder);
        $this->params($supplierOrders, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['po_number', 'PO number', 'string', ['is_criteria' => true]],
            ['order_date', 'Ordered', 'date'],
            ['expected_date', 'Expected', 'date'],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money']],
            ['status_id', 'Status', 'integer'],
            ['supplier_id', 'Supplier', 'integer', ['is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
        ]);
        MetaParam::create([
            'main_meta_id' => $supplierGrid->id,
            'order_number' => 100,
            'field_path' => 'orders',
            'param_name' => 'orders',
            'label_name' => 'Purchase orders',
            'data_type' => 'subgrid',
            'ref_meta_id' => $supplierOrders->id,
            // Opens inside each supplier's row (its expand arrow) instead of under the grid.
            'json_config' => ['params' => ['supplier_id' => 'id'], 'display' => 'nested'],
        ]);

        $masterData = $this->meta('proc_master_data', 'Master data', 'tabs', null, 'Suppliers and items in one place.');
        $this->params($masterData, [
            ['suppliers', 'Suppliers', 'string', ['ref_meta_id' => $supplierGrid->id]],
            ['items', 'Items', 'string', ['ref_meta_id' => $itemGrid->id]],
        ]);

        $app = $this->meta('proc_app', 'Procurement', 'app', null, 'Requests, orders, suppliers and items.');
        $this->params($app, [
            ['purchasing', 'Purchasing', 'string', ['icon_cls' => 'cart', 'children' => [
                // A menu item hands its screen values too: a new request is needed by today unless changed.
                ['new_request', 'New request', 'string', ['icon_cls' => 'plus', 'ref_meta_id' => $requestForm->id, 'json_config' => ['defaults' => ['needed_by' => '{today}']]]],
                ['purchase_requests', 'Purchase requests', 'string', ['icon_cls' => 'file', 'ref_meta_id' => $requestTabs->id]],
                ['purchase_orders', 'Purchase orders', 'string', ['icon_cls' => 'receipt', 'ref_meta_id' => $orderGrid->id]],
                ['delivery_schedule', 'Delivery schedule', 'string', ['icon_cls' => 'calendar', 'ref_meta_id' => $scheduleGrid->id]],
            ]]],
            ['master_data', 'Master data', 'string', ['icon_cls' => 'database', 'ref_meta_id' => $masterData->id]],
        ]);
    }

    /**
     * The purchase order workflow:
     *
     *   (start) -> Draft -> Pending approval -> Approved -> Sent -> Received
     *                            |  \ over 5,000,000      ^
     *                            |   -> Director approval -/
     *                            v           v
     *                         Rejected  <----            (asks why; back to Draft to rework)
     *
     * Statuses keep their ids across runs (history points at them); transitions and the
     * diagram layout are rebuilt. Orders without a status get one from their old text status.
     */
    private function orderWorkflow(): Meta
    {
        $workflow = $this->meta('proc_order_workflow', 'Purchase order workflow', 'workflow', 'proc_purchase_orders', 'From draft to received, with director approval for large orders.');

        $rejectForm = $this->meta('proc_order_reject_form', 'Rejection note', 'form', 'proc_purchase_orders', 'Why the order is rejected.');
        $this->params($rejectForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['notes', 'Why is it rejected?', 'text', ['is_required' => true, 'render_type' => 'textarea']],
        ]);

        $status = [];
        foreach ([
            ['draft', 'Draft', 'gray', null],
            ['pending', 'Pending approval', 'amber', null],
            ['director', 'Director approval', 'purple', null],
            ['approved', 'Approved', 'green', null],
            ['rejected', 'Rejected', 'red', $rejectForm->id],
            ['sent', 'Sent', 'blue', null],
            ['received', 'Received', 'green', null],
        ] as [$code, $name, $color, $form]) {
            $status[$code] = WfmStatus::updateOrCreate(
                ['workflow_meta_id' => $workflow->id, 'code' => $code],
                ['name' => $name, 'color' => $color, 'meta_id' => $form],
            )->id;
        }

        WfmTransition::where('workflow_meta_id', $workflow->id)->delete();
        $move = fn (?string $from, string $to, ?string $criteria = null) => WfmTransition::create([
            'workflow_meta_id' => $workflow->id,
            'prev_status_id' => $from ? $status[$from] : null,
            'next_status_id' => $status[$to],
            'criteria' => $criteria,
            'is_transition' => true,
        ]);

        // The usual path, linked in order for the diagram (prev_id / next_id).
        $path = [
            $move(null, 'draft'),
            $move('draft', 'pending'),
            $move('pending', 'approved', 'totalAmount <= 5000000'),
            $move('approved', 'sent'),
            $move('sent', 'received'),
        ];
        foreach ($path as $i => $t) {
            $t->update(['prev_id' => $path[$i - 1]->id ?? null, 'next_id' => $path[$i + 1]->id ?? null]);
        }
        $move('pending', 'director', 'totalAmount > 5000000');
        $move('director', 'approved');
        $move('pending', 'rejected');
        $move('director', 'rejected');
        $move('rejected', 'draft');

        $at = fn (int $x, int $y) => ['x' => $x, 'y' => $y];
        $workflow->update(['json_config' => ['layout' => [
            'start' => $at(30, 150),
            'statuses' => [
                $status['draft'] => $at(130, 130),
                $status['pending'] => $at(330, 130),
                $status['director'] => $at(530, 260),
                $status['approved'] => $at(730, 130),
                $status['rejected'] => $at(330, 330),
                $status['sent'] => $at(930, 130),
                $status['received'] => $at(930, 300),
            ],
        ]]]);

        $byText = ['draft' => 'draft', 'sent' => 'sent', 'partially received' => 'sent', 'received' => 'received', 'cancelled' => 'rejected'];
        foreach ($byText as $text => $code) {
            DB::table('proc_purchase_orders')->whereNull('status_id')->where('status', $text)->update(['status_id' => $status[$code]]);
        }

        return $workflow;
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?string $query = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'query_string' => $query,
            'is_active' => true,
        ]);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
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

            $param = MetaParam::create([
                'main_meta_id' => $meta->id,
                'parent_id' => $parentId,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
            ] + $extra);

            if ($children) {
                $this->params($meta, $children, $param->id);
            }
        }
    }

    private function sampleData(): void
    {
        $now = now();

        foreach (['local' => 'Local', 'foreign' => 'Foreign', 'service' => 'Service provider'] as $code => $typeName) {
            DB::table('proc_supplier_types')->updateOrInsert(['code' => $code], ['name' => $typeName, 'created_at' => $now, 'updated_at' => $now]);
        }

        if (! DB::table('proc_suppliers')->exists()) {
            DB::table('proc_suppliers')->insert(array_map(fn ($s) => $s + ['created_at' => $now, 'updated_at' => $now], [
                ['name' => 'Nomin Trading LLC', 'contact_name' => 'Bat-Erdene', 'email' => 'sales@nomin-trading.example', 'phone' => '+976 7011 2233', 'country' => 'Mongolia', 'is_active' => true],
                ['name' => 'Steppe Office Supply', 'contact_name' => 'Oyunaa', 'email' => 'orders@steppe-office.example', 'phone' => '+976 7700 4455', 'country' => 'Mongolia', 'is_active' => true],
                ['name' => 'Northwind Electronics', 'contact_name' => 'Li Wei', 'email' => 'b2b@northwind.example', 'phone' => '+86 10 5555 0101', 'country' => 'China', 'is_active' => true],
                ['name' => 'Baltic Furniture Co.', 'contact_name' => 'Anna Ozola', 'email' => 'export@baltic-furniture.example', 'phone' => '+371 6700 1122', 'country' => 'Latvia', 'is_active' => true],
                ['name' => 'Gobi Cleaning Products', 'contact_name' => 'Tuvshin', 'email' => 'info@gobi-clean.example', 'phone' => '+976 9911 8877', 'country' => 'Mongolia', 'is_active' => false],
            ]));
        }

        if (! DB::table('proc_items')->exists()) {
            DB::table('proc_items')->insert(array_map(fn ($i) => $i + ['created_at' => $now, 'updated_at' => $now], [
                ['code' => 'IT-001', 'name' => 'Laptop 14"', 'category' => 'IT equipment', 'unit' => 'pcs', 'unit_price' => 2450000],
                ['code' => 'IT-002', 'name' => '27" Monitor', 'category' => 'IT equipment', 'unit' => 'pcs', 'unit_price' => 780000],
                ['code' => 'IT-003', 'name' => 'Wireless keyboard and mouse', 'category' => 'IT equipment', 'unit' => 'pcs', 'unit_price' => 95000],
                ['code' => 'OF-001', 'name' => 'A4 copy paper', 'category' => 'Office supplies', 'unit' => 'box', 'unit_price' => 62000],
                ['code' => 'OF-002', 'name' => 'Ballpoint pens', 'category' => 'Office supplies', 'unit' => 'pack', 'unit_price' => 8500],
                ['code' => 'FU-001', 'name' => 'Office chair', 'category' => 'Furniture', 'unit' => 'pcs', 'unit_price' => 420000],
                ['code' => 'CL-001', 'name' => 'Floor cleaner', 'category' => 'Cleaning', 'unit' => 'l', 'unit_price' => 12000],
            ]));
        }

        if (! DB::table('proc_purchase_requests')->exists()) {
            $item = DB::table('proc_items')->pluck('id', 'code');
            DB::table('proc_purchase_requests')->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], [
                ['request_no' => 'PR-2026-001', 'title' => 'Laptops for new hires', 'requested_by' => 'Saraa', 'department' => 'IT', 'item_id' => $item['IT-001'], 'quantity' => 4, 'needed_by' => '2026-11-03', 'status' => 'approved', 'notes' => 'Three developers and one designer start in November.'],
                ['request_no' => 'PR-2026-002', 'title' => 'Monitors for finance team', 'requested_by' => 'Ganbold', 'department' => 'Finance', 'item_id' => $item['IT-002'], 'quantity' => 6, 'needed_by' => '2026-11-10', 'status' => 'submitted', 'notes' => null],
                ['request_no' => 'PR-2026-003', 'title' => 'Paper restock Q4', 'requested_by' => 'Enkhjin', 'department' => 'Operations', 'item_id' => $item['OF-001'], 'quantity' => 20, 'needed_by' => '2026-10-30', 'status' => 'ordered', 'notes' => null],
                ['request_no' => 'PR-2026-004', 'title' => 'Chairs for meeting room', 'requested_by' => 'Munkh', 'department' => 'HR', 'item_id' => $item['FU-001'], 'quantity' => 10, 'needed_by' => '2026-12-01', 'status' => 'draft', 'notes' => 'Waiting on the room layout.'],
                ['request_no' => 'PR-2026-005', 'title' => 'Keyboards for sales', 'requested_by' => 'Anu', 'department' => 'Sales', 'item_id' => $item['IT-003'], 'quantity' => 8, 'needed_by' => '2026-11-15', 'status' => 'rejected', 'notes' => 'Use existing stock first.'],
            ]));
        }

        // Suppliers outside Mongolia are foreign.
        $types = DB::table('proc_supplier_types')->pluck('id', 'code');
        DB::table('proc_suppliers')->whereNull('supplier_type_id')->where('country', 'Mongolia')->update(['supplier_type_id' => $types['local']]);
        DB::table('proc_suppliers')->whereNull('supplier_type_id')->update(['supplier_type_id' => $types['foreign']]);

        if (! DB::table('proc_supplier_terms')->exists()) {
            $supplier = DB::table('proc_suppliers')->pluck('id', 'name');
            DB::table('proc_supplier_terms')->insert(array_map(fn ($t) => $t + ['created_at' => $now, 'updated_at' => $now], [
                ['supplier_id' => $supplier['Nomin Trading LLC'], 'payment_terms' => 'net_30', 'currency' => 'MNT', 'credit_limit' => 50000000, 'tax_number' => '5123456', 'notes' => null],
                ['supplier_id' => $supplier['Northwind Electronics'], 'payment_terms' => 'prepaid', 'currency' => 'USD', 'credit_limit' => null, 'tax_number' => null, 'notes' => 'Pays before shipping.'],
            ]));
        }

        if (! DB::table('proc_purchase_orders')->exists()) {
            $supplier = DB::table('proc_suppliers')->pluck('id', 'name');
            DB::table('proc_purchase_orders')->insert(array_map(fn ($o) => $o + ['created_at' => $now, 'updated_at' => $now], [
                ['po_number' => 'PO-2026-0101', 'supplier_id' => $supplier['Steppe Office Supply'], 'order_date' => '2026-10-12', 'expected_date' => '2026-10-20', 'status' => 'received', 'total_amount' => 1240000, 'notes' => 'Paper restock for PR-2026-003.'],
                ['po_number' => 'PO-2026-0102', 'supplier_id' => $supplier['Northwind Electronics'], 'order_date' => '2026-10-15', 'expected_date' => '2026-11-01', 'status' => 'sent', 'total_amount' => 9800000, 'notes' => 'Four laptops for PR-2026-001.'],
                ['po_number' => 'PO-2026-0103', 'supplier_id' => $supplier['Baltic Furniture Co.'], 'order_date' => '2026-10-18', 'expected_date' => '2026-12-05', 'status' => 'draft', 'total_amount' => 4200000, 'notes' => null],
                ['po_number' => 'PO-2026-0104', 'supplier_id' => $supplier['Nomin Trading LLC'], 'order_date' => '2026-10-02', 'expected_date' => '2026-10-09', 'status' => 'partially received', 'total_amount' => 610000, 'notes' => 'Second delivery pending.'],
            ]));
        }

        if (! DB::table('proc_purchase_order_lines')->exists()) {
            $order = DB::table('proc_purchase_orders')->pluck('id', 'po_number');
            $item = DB::table('proc_items')->pluck('id', 'code');
            $lines = [
                ['PO-2026-0101', 'OF-001', 'Paper restock for PR-2026-003', 20, 62000],
                ['PO-2026-0102', 'IT-001', null, 4, 2450000],
                ['PO-2026-0102', 'IT-003', 'Bundled with the laptops', 4, 95000],
                ['PO-2026-0103', 'FU-001', 'Meeting room chairs', 10, 420000],
                ['PO-2026-0104', 'OF-002', null, 50, 8500],
                ['PO-2026-0104', 'CL-001', 'Second delivery pending', 15, 12000],
            ];
            $lines = array_filter($lines, fn ($l) => isset($order[$l[0]], $item[$l[1]]));
            DB::table('proc_purchase_order_lines')->insert(array_map(fn ($l) => [
                'purchase_order_id' => $order[$l[0]],
                'item_id' => $item[$l[1]],
                'description' => $l[2],
                'quantity' => $l[3],
                'unit_price' => $l[4],
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($lines)));

            // Totals follow from the lines.
            foreach (array_unique(array_column($lines, 0)) as $po) {
                $total = DB::table('proc_purchase_order_lines')->where('purchase_order_id', $order[$po])->get()
                    ->sum(fn ($l) => $l->quantity * $l->unit_price);
                DB::table('proc_purchase_orders')->where('id', $order[$po])->update(['total_amount' => $total]);
            }
        }
    }
}
