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
use Illuminate\Support\Str;

/**
 * The point of sale app, built only from metas (tables and rules: 2026_12_03_000022 / 000023):
 *
 *   Point of sale (app pos_app)
 *   ├─ New sale        the sale form: scan or pick items, split payments, change, receipt
 *   ├─ Sales           held, today, completed, void, all; Complete, Return, Cancel, Void
 *   ├─ Returns         against a sale; its lines fill themselves from what is left of it
 *   ├─ Shifts          open with a float, close with the counted cash; Z report
 *   ├─ Dashboard       today, payment methods, top products, cashiers, shifts, by day
 *   ├─ Customers       the CRM's customers, added here with just what a till needs
 *   ├─ Products        products (catalogue items with barcode, price, tax), categories,
 *   │                  tax rates, stock on hand, sold items
 *   └─ Setup           registers, payment methods
 *
 * A new sale completes straight away (process flow -> pos_complete_sale) unless "Complete now"
 * is off, which holds it. Process flows only run for new records, so a held sale is finished
 * with the Complete button on its row. Everything that can be refused is refused by the
 * triggers, and stage queries carry their messages to the screen.
 *
 * Roles: pos_cashier sells, holds, takes returns and runs a shift of their own; pos_manager
 * also voids, closes anyone's shift, sees the dashboard and keeps products and setup.
 *
 * Idempotent: metas matched by code, their params, actions and flows rebuilt. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const SALE_STATUSES = ['held', 'completed', 'void'];

    private const RETURN_STATUSES = ['draft', 'completed'];

    private const SHIFT_STATUSES = ['open', 'closed'];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> the metas made here, by code */
    private array $m = [];

    /** @var array<string, Meta> metas of other apps reused here */
    private array $ext = [];

    private ?int $cashId = null;

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('pos_sales')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        if (! $this->types && ! DB::pretending()) {
            return;
        }
        $this->ext = Meta::whereIn('code', ['wh_warehouses_grid', 'wh_locations_lookup'])->get()->keyBy('code')->all();
        $this->cashId = DB::table('pos_payment_methods')->where('code', 'CASH')->value('id');

        DB::transaction(function () {
            $this->setup();
            $this->products();
            $this->gets();
            $this->stageQueries();
            $this->shifts();
            $this->sales();
            $this->returns();
            $this->lists();
            $this->dashboard();
            $this->app();
            $this->access();
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::transaction(function () {
            $ids = DB::table('metas')->where('code', 'like', 'pos\_%')->pluck('id');
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->whereNotIn('main_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('ref_meta_id', $ids)->whereNotIn('id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('roles')->whereIn('code', ['pos_cashier', 'pos_manager'])->delete();
            DB::table('tags')->where('name', 'pos')->delete();
            DB::table('autonumbers')->where('table_name', 'like', 'pos\_%')->delete();
        });
    }

    // ---------------------------------------------------------------- tax rates, payment methods, categories, registers, customers

    private function setup(): void
    {
        $form = $this->meta('pos_tax_rate_form', 'Tax rate form', 'form', 'pos_tax_rates', 'A tax rate, in percent. Prices include it.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['rate', 'Rate %', 'decimal', ['is_required' => true, 'min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ]);
        $grid = $this->meta('pos_tax_rates_grid', 'Tax rates', 'datagrid', 'pos_tax_rates', 'The tax rates products can have.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['rate', 'Rate %', 'decimal'],
            ['is_active', 'Active', 'boolean'],
        ]);

        $kinds = ['cash', 'card', 'qr', 'transfer', 'voucher'];
        $form = $this->meta('pos_payment_method_form', 'Payment method form', 'form', 'pos_payment_methods', 'A way of paying. Only cash counts towards the drawer and can give change.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options($kinds, 'card')],
            ['is_cash', 'Cash (goes in the drawer)', 'boolean', ['default_value' => '0']],
            ['order_number', 'Order', 'integer', ['min_value' => '1', 'default_value' => '1']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ]);
        $grid = $this->meta('pos_payment_methods_grid', 'Payment methods', 'datagrid', 'pos_payment_methods', 'How customers can pay.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => $kinds]]],
            ['is_cash', 'Cash', 'boolean'],
            ['order_number', 'Order', 'integer'],
            ['is_active', 'Active', 'boolean'],
        ]);
        $lookup = $this->meta('pos_payment_methods_lookup', 'Payment methods (pick list)', 'datagrid', 'pos_payment_methods', 'The active payment methods, in order.', null, null,
            'SELECT id, name, kind, is_cash, order_number FROM pos_payment_methods WHERE is_active = 1');
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string'],
            ['is_cash', 'Cash', 'boolean'],
        ]);

        $form = $this->meta('pos_category_form', 'Category form', 'form', 'proc_item_categories', 'A group of products, shared with the rest of the catalogue. Services move no stock.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true]],
            ['default_unit', 'Default unit', 'string'],
            ['is_service', 'Service (no stock)', 'boolean', ['default_value' => '0']],
        ]);
        $grid = $this->meta('pos_categories_grid', 'Categories', 'datagrid', 'proc_item_categories', 'The categories of the shared catalogue.');
        $this->actions($grid, $form, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['default_unit', 'Default unit', 'string'],
            ['is_service', 'Service', 'boolean'],
        ]);

        $warehouse = $this->lookup($this->ext['wh_warehouses_grid'] ?? new Meta);
        $location = $this->lookup($this->ext['wh_locations_lookup'] ?? new Meta, 'label');
        $form = $this->meta('pos_register_form', 'Register form', 'form', 'pos_registers', 'A till: which warehouse it sells from, how far it may discount, and what its receipts say.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['warehouse_id', 'Sells from warehouse', 'integer', ['is_required' => true] + $warehouse],
            ['location_id', 'Location in it (optional)', 'integer', $location],
            ['max_discount_pct', 'Largest discount %', 'decimal', ['is_required' => true, 'min_value' => '0', 'max_value' => '100', 'default_value' => '10']],
            ['receipt_header', 'Receipt header', 'string'],
            ['receipt_footer', 'Receipt footer', 'string'],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('pos_registers_grid', 'Registers', 'datagrid', 'pos_registers', 'The tills of the shop.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['warehouse_id', 'Warehouse', 'integer', ['is_criteria' => true] + $warehouse],
            ['max_discount_pct', 'Largest discount %', 'decimal'],
            ['is_active', 'Active', 'boolean'],
        ]);
        $lookup = $this->meta('pos_registers_lookup', 'Registers (pick list)', 'datagrid', 'pos_registers', 'The active registers.', null, null,
            'SELECT id, code, name FROM pos_registers WHERE is_active = 1');
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
        ]);

        $statuses = ['prospect', 'active', 'inactive', 'blocked'];
        $form = $this->meta('pos_customer_form', 'Customer form', 'form', 'crm_customers', 'A customer, as the till needs one. The CRM has the rest of their details.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'json_config' => ['autonumber' => ['format' => 'CUS-{seq:4}']]]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(['company', 'person'], 'person')],
            ['phone', 'Phone', 'string'],
            ['email', 'Email', 'string'],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options($statuses, 'active')],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('pos_customers_grid', 'Customers', 'datagrid', 'crm_customers', 'The customers of the CRM.');
        $this->actions($grid, $form, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string'],
            ['phone', 'Phone', 'string', ['is_criteria' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'json_config' => ['options' => $statuses]]],
        ]);
    }

    // ---------------------------------------------------------------- products

    private function products(): void
    {
        $sale = $this->meta('pos_product_sale_form', 'Selling details', 'form', 'pos_products', 'What the till needs to know: barcode, selling price and tax. Prices include tax.');
        $this->params($sale, [
            ['id', 'ID', 'integer', $this->key()],
            ['item_id', 'Item', 'integer', ['is_show' => false]],
            ['barcode', 'Barcode', 'string'],
            ['sale_price', 'Selling price', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['tax_rate_id', 'Tax rate', 'integer', $this->lookup('pos_tax_rates_grid')],
            ['on_sale', 'On sale', 'boolean', ['default_value' => '1']],
        ]);
        $form = $this->meta('pos_product_form', 'Product form', 'form', 'proc_items', 'A catalogue item with its selling details. The cost is the catalogue price; stock comes from the warehouse.', null, ['save_and_add' => true], null, [
            'is_log' => true,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'POS-{seq:5}', 'editable' => true]]]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['category_id', 'Category', 'integer', $this->lookup('pos_categories_grid')],
            ['unit', 'Unit', 'string', ['is_required' => true, 'default_value' => 'pcs']],
            ['unit_price', 'Cost', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['is_active', 'Active in the catalogue', 'boolean', ['default_value' => '1']],
            ['sale', 'Selling details', 'detail', ['ref_meta_id' => $sale->id, 'json_config' => ['foreign_key' => 'item_id', 'one' => true]]],
        ]);

        $grid = $this->meta('pos_products_grid', 'Products', 'datagrid', 'proc_items', 'The catalogue with selling prices and what is on hand. Edit an item to put it on sale.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT i.id, i.code, i.name, c.name AS category, i.unit, i.unit_price AS cost, p.barcode, p.sale_price, t.name AS tax,
                   COALESCE(p.on_sale, 0) AS on_sale, COALESCE(s.on_hand, 0) AS on_hand
            FROM proc_items i
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            LEFT JOIN pos_products p ON p.item_id = i.id
            LEFT JOIN pos_tax_rates t ON t.id = p.tax_rate_id
            LEFT JOIN (SELECT item_id, SUM(quantity) AS on_hand FROM wh_stocks GROUP BY item_id) s ON s.item_id = i.id
            WHERE COALESCE(i.is_active, 1) = 1
            SQL);
        $this->actions($grid, $form, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['barcode', 'Barcode', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['cost', 'Cost', 'decimal', ['render_type' => 'money']],
            ['sale_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['tax', 'Tax', 'string'],
            ['on_hand', 'On hand', 'decimal'],
            ['on_sale', 'On sale', 'boolean', ['is_criteria' => true]],
        ]);
        $this->params($this->meta('pos_products_tabs', 'Products', 'tabs', null, 'What the till sells, and the rest of the catalogue.'), [
            ['on_sale', 'On sale', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['on_sale' => '1']]]],
            ['all', 'All catalogue items', 'string', ['ref_meta_id' => $grid->id]],
        ]);

        $lookup = $this->meta('pos_products_lookup', 'Products (pick list)', 'datagrid', 'proc_items', 'What is on sale, found by name, code or barcode.', null, null, <<<'SQL'
            SELECT i.id, i.code, i.name, p.barcode, c.name AS category, i.unit, p.sale_price, COALESCE(t.rate, 0) AS tax_rate,
                   COALESCE(s.on_hand, 0) AS on_hand, CONCAT(i.name, ' (', i.code, ')') AS label
            FROM pos_products p
            JOIN proc_items i ON i.id = p.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            LEFT JOIN pos_tax_rates t ON t.id = p.tax_rate_id
            LEFT JOIN (SELECT item_id, SUM(quantity) AS on_hand FROM wh_stocks GROUP BY item_id) s ON s.item_id = i.id
            WHERE p.on_sale = 1
            SQL);
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Product', 'string', ['is_label' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['barcode', 'Barcode', 'string', ['is_criteria' => true]],
            ['sale_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['on_hand', 'On hand', 'decimal'],
            ['tax_rate', 'Tax %', 'decimal', ['is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- what forms ask the server while they are open

    private function gets(): void
    {
        $this->meta('pos_scan_get', 'Product by barcode', 'get', null, 'The product with this barcode or code, for the scan field.', null, null, <<<'SQL'
            SELECT i.id AS item_id, i.name, p.sale_price, COALESCE(t.rate, 0) AS tax_rate
            FROM pos_products p
            JOIN proc_items i ON i.id = p.item_id
            LEFT JOIN pos_tax_rates t ON t.id = p.tax_rate_id
            WHERE p.on_sale = 1 AND (p.barcode = :code OR i.code = :code)
            ORDER BY p.barcode = :code DESC
            LIMIT 1
            SQL);
        $this->meta('pos_current_shift_get', 'My open shift', 'get', null, 'The shift the signed-in user has open.', null, null, <<<'SQL'
            SELECT s.id AS shift_id, s.shift_no, r.name AS register_name, CONCAT(s.shift_no, ' · ', r.name) AS label
            FROM pos_shifts s
            JOIN pos_registers r ON r.id = s.register_id
            WHERE s.cashier_id = :user_id AND s.status = 'open'
            ORDER BY s.id DESC
            LIMIT 1
            SQL);
        $this->meta('pos_shift_close_get', 'Shift cash so far', 'get', null, 'What the drawer of a shift should hold, for the Close shift form.', null, null, <<<'SQL'
            SELECT s.id,
                   COALESCE(s.sales_total, (SELECT COALESCE(SUM(x.total_amount), 0) FROM pos_sales x WHERE x.shift_id = s.id AND x.status = 'completed')) AS sales_total,
                   COALESCE(s.cash_sales, c.taken - c.given) AS cash_sales,
                   COALESCE(s.cash_refunds, c.refunded) AS cash_refunds,
                   COALESCE(s.expected_cash, s.opening_float + c.taken - c.given - c.refunded) AS expected_cash
            FROM pos_shifts s
            JOIN (SELECT
                    (SELECT COALESCE(SUM(p.amount), 0) FROM pos_sale_payments p JOIN pos_sales x ON x.id = p.sale_id JOIN pos_payment_methods m ON m.id = p.method_id
                     WHERE x.shift_id = :id AND x.status = 'completed' AND m.is_cash = 1) AS taken,
                    (SELECT COALESCE(SUM(x.change_due), 0) FROM pos_sales x WHERE x.shift_id = :id AND x.status = 'completed') AS given,
                    (SELECT COALESCE(SUM(r.total_amount), 0) FROM pos_returns r JOIN pos_payment_methods m ON m.id = r.refund_method_id
                     WHERE r.shift_id = :id AND r.status = 'completed' AND m.is_cash = 1) AS refunded) c
            WHERE s.id = :id
            SQL);
        $this->meta('pos_return_start_get', 'Return of a sale', 'get', null, 'Starts a return from a row of the sales grid.', null, null,
            "SELECT s.id AS sale_id FROM pos_sales s WHERE s.id = :id AND s.status = 'completed'");
        $this->meta('pos_return_line_get', 'What is left to return', 'get', null, 'The n-th item of a sale with what can still come back; the return form reads them one by one.', null, null, <<<'SQL'
            SELECT y.item_id, y.left_qty, y.unit_price
            FROM (SELECT x.item_id, x.left_qty, x.unit_price, ROW_NUMBER() OVER (ORDER BY x.item_id) AS row_no
                  FROM (SELECT l.item_id, SUM(l.quantity) - COALESCE(MAX(d.done), 0) AS left_qty, ROUND(SUM(l.line_total) / SUM(l.quantity), 2) AS unit_price
                        FROM pos_sale_lines l
                        LEFT JOIN (SELECT rl.item_id, SUM(rl.quantity) AS done FROM pos_return_lines rl JOIN pos_returns r ON r.id = rl.return_id
                                   WHERE r.sale_id = :sale_id AND r.status = 'completed' GROUP BY rl.item_id) d ON d.item_id = l.item_id
                        WHERE l.sale_id = :sale_id
                        GROUP BY l.item_id) x
                  WHERE 0 < x.left_qty) y
            WHERE y.row_no = :n
            SQL);
    }

    // ---------------------------------------------------------------- what stage queries do: the triggers hold the rules

    private function stageQueries(): void
    {
        $shift = "(SELECT x.id FROM pos_shifts x WHERE x.cashier_id = :user_id AND x.status = 'open' ORDER BY x.id DESC LIMIT 1)";
        $id = fn (string $field, string $label) => [$field, $label, 'integer', ['is_required' => true]];

        $this->stageQuery('pos_claim_shift', 'Take the shift', 'pos_shifts',
            'UPDATE pos_shifts SET cashier_id = :user_id, updated_at = NOW() WHERE id = :shift_id AND cashier_id IS NULL',
            'Makes whoever opened a shift its cashier. A register or person with a shift already open is refused.', [$id('shift_id', 'Shift')]);

        $this->stageQuery('pos_complete_sale', 'Complete sale', 'pos_sales', <<<SQL
            UPDATE pos_sales s
            SET s.shift_id = {$shift}, s.cashier_id = :user_id, s.status = 'completed', s.updated_at = NOW()
            WHERE s.id = :sale_id AND s.status = 'held'
            SQL, 'Finishes a held sale on your open shift: checks payment and discounts, and takes the goods out of stock.', [$id('sale_id', 'Sale')]);

        $this->stageQuery('pos_cancel_held_sale', 'Cancel held sale', 'pos_sales', <<<'SQL'
            UPDATE pos_sales s
            SET s.status = 'void', s.void_reason = 'Cancelled while held', s.voided_by = :user_id, s.updated_at = NOW()
            WHERE s.id = :sale_id AND s.status = 'held'
            SQL, 'Drops a held sale. No stock or money has moved, and the receipt number stays used.', [$id('sale_id', 'Sale')]);

        $this->stageQuery('pos_void_sale', 'Void sale', 'pos_sales', <<<'SQL'
            UPDATE pos_sales s
            SET s.status = 'void', s.void_reason = :reason, s.voided_by = :user_id, s.updated_at = NOW()
            WHERE s.id = :sale_id AND s.status = 'completed'
            SQL, 'Undoes a completed sale while its shift is open: the goods go back into stock. Later, make a return instead.', [
            $id('sale_id', 'Sale'),
            ['reason', 'Why', 'string', ['is_required' => true]],
        ]);

        $this->stageQuery('pos_complete_return', 'Complete return', 'pos_returns', <<<SQL
            UPDATE pos_returns r
            SET r.shift_id = {$shift}, r.cashier_id = :user_id, r.status = 'completed', r.updated_at = NOW()
            WHERE r.id = :return_id AND r.status = 'draft'
            SQL, 'Finishes a return on your open shift: checks the quantities, refunds, and puts goods marked restock back into stock.', [$id('return_id', 'Return')]);
    }

    // ---------------------------------------------------------------- shifts

    private function shifts(): void
    {
        $current = $this->m['pos_current_shift_get']->id;
        $form = $this->meta('pos_shift_form', 'Open shift', 'form', 'pos_shifts', 'Starts your time on a register with the cash in the drawer.', null, ['save_and_add' => false], null, [
            'var_fnc_expression_string' => "const SHIFT_GET = {$current};",
            'before_save_expression_string' => <<<'JS'
                const mine = await fill(SHIFT_GET);
                if (mine.shift_id) {
                  error('You already have shift ' + mine.shift_no + ' open on ' + mine.register_name + ': close it first.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['shift_no', 'Shift', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'SH-{yy}{MM}{dd}{seq:3}']]]],
            ['register_id', 'Register', 'integer', ['is_required' => true] + $this->lookup('pos_registers_lookup')],
            ['opening_float', 'Cash in the drawer', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $this->flow($form, [
            'claim' => ['process' => $this->m['pos_claim_shift'], 'map' => ['shift_id' => 'id']],
        ]);

        $close = $this->meta('pos_shift_close_form', 'Close shift', 'form', 'pos_shifts', 'Count the drawer: the difference from what it should hold is kept with the shift.', null, ['save_and_add' => false], <<<'SQL'
            SELECT s.id, s.shift_no, s.status, s.opened_at, s.closed_at, s.opening_float, s.sales_total, s.cash_sales, s.cash_refunds,
                   s.expected_cash, s.counted_cash, s.cash_difference, s.notes
            FROM pos_shifts s
            WHERE s.cashier_id = :user_id
               OR EXISTS (SELECT 1 FROM user_roles ur JOIN roles ro ON ro.id = ur.role_id WHERE ur.user_id = :user_id AND ro.code = 'pos_manager')
            SQL, [
            'var_fnc_expression_string' => <<<'JS'
                function difference() {
                  const counted = get('counted_cash');
                  set('cash_difference', counted === '' ? '' : round(num(counted) - num(get('expected_cash')), 2));
                }
                JS,
            'load_expression_string' => <<<'JS'
                ['shift_no', 'opened_at', 'opening_float', 'sales_total', 'cash_sales', 'cash_refunds', 'expected_cash', 'cash_difference'].forEach((f) => readonly(f));
                hide('status');
                hide('closed_at');
                if (get('closed_at')) {
                  readonly('counted_cash');
                  readonly('notes');
                  message('This shift is closed.');
                } else {
                  set('status', 'closed');
                }
                difference();
                JS,
            'event_expression_string' => "if (field === 'counted_cash') difference();",
            'before_save_expression_string' => <<<'JS'
                if (get('closed_at')) {
                  error('This shift is closed and cannot be changed.');
                }
                JS,
        ]);
        $this->params($close, [
            ['id', 'ID', 'integer', $this->key()],
            ['shift_no', 'Shift', 'string', ['is_label' => true]],
            ['opened_at', 'Opened', 'datetime'],
            ['opening_float', 'Cash at the start', 'decimal', ['render_type' => 'money']],
            ['sales_total', 'Sales', 'decimal', ['render_type' => 'money']],
            ['cash_sales', 'Cash taken, less change', 'decimal', ['render_type' => 'money']],
            ['cash_refunds', 'Cash refunded', 'decimal', ['render_type' => 'money']],
            ['expected_cash', 'Should be in the drawer', 'decimal', ['render_type' => 'money']],
            ['counted_cash', 'Counted', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['cash_difference', 'Difference', 'decimal', ['render_type' => 'money']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['status', 'Status', 'string', ['json_config' => ['options' => self::SHIFT_STATUSES]]],
            ['closed_at', 'Closed', 'datetime'],
        ]);

        $report = $this->meta('pos_shift_report', 'Z report', 'report', 'pos_shifts', 'One shift: the cash that should be there, what was counted, and the difference.', null, [
            'title' => 'Z report', 'number_field' => 'shift_no', 'note' => 'Counted and handed over.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['shift_no', 'Shift', 'string'],
            ['register_id', 'Register', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_registers_grid']->id, 'display_field' => 'name']],
            ['status', 'Status', 'string'],
            ['opened_at', 'Opened', 'datetime'],
            ['closed_at', 'Closed', 'datetime'],
            ['opening_float', 'Cash at the start', 'decimal', ['render_type' => 'money']],
            ['sales_total', 'Sales', 'decimal', ['render_type' => 'money']],
            ['cash_sales', 'Cash taken, less change', 'decimal', ['render_type' => 'money']],
            ['cash_refunds', 'Cash refunded', 'decimal', ['render_type' => 'money']],
            ['expected_cash', 'Should be in the drawer', 'decimal', ['render_type' => 'money']],
            ['counted_cash', 'Counted', 'decimal', ['render_type' => 'money']],
            ['cash_difference', 'Difference', 'decimal', ['render_type' => 'money']],
            ['notes', 'Notes', 'text'],
            ['cashier_signature', 'Cashier', 'signature'],
            ['manager_signature', 'Manager', 'signature'],
        ]);

        $grid = $this->meta('pos_shifts_grid', 'Shifts', 'datagrid', 'pos_shifts', 'Who was on which register, and how the drawer came out.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Z report'],
        ], <<<'SQL'
            SELECT s.id, s.shift_no, s.status, s.register_id, r.name AS register_name, u.name AS cashier_name, s.opened_at, s.closed_at, s.opening_float,
                   COALESCE(s.sales_total, x.sales) AS sales_total, COALESCE(x.receipts, 0) AS receipts, s.expected_cash, s.counted_cash, s.cash_difference
            FROM pos_shifts s
            JOIN pos_registers r ON r.id = s.register_id
            LEFT JOIN users u ON u.id = s.cashier_id
            LEFT JOIN (SELECT shift_id, SUM(total_amount) AS sales, COUNT(*) AS receipts FROM pos_sales WHERE status = 'completed' GROUP BY shift_id) x ON x.shift_id = s.id
            SQL);
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'add', 'Open shift', 'plus', $form, null, 1, null, false, false);
        $this->action($grid, 'edit', 'Close shift', 'check', $close, $this->m['pos_shift_close_get'], 2, ['id' => 'id'], true, true);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['shift_no', 'Shift', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::SHIFT_STATUSES]]],
            ['register_name', 'Register', 'string', ['is_criteria' => true]],
            ['cashier_name', 'Cashier', 'string', ['is_criteria' => true]],
            ['opened_at', 'Opened', 'datetime'],
            ['closed_at', 'Closed', 'datetime'],
            ['opening_float', 'Float', 'decimal', ['render_type' => 'money']],
            ['receipts', 'Receipts', 'integer'],
            ['sales_total', 'Sales', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['expected_cash', 'Expected', 'decimal', ['render_type' => 'money']],
            ['counted_cash', 'Counted', 'decimal', ['render_type' => 'money']],
            ['cash_difference', 'Difference', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);
    }

    // ---------------------------------------------------------------- the sale, its receipt

    private function sales(): void
    {
        $line = $this->meta('pos_sale_line_form', 'Sale line', 'form', 'pos_sale_lines', 'A product on a sale. The server works the amounts out again from the catalogue tax rate.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['sale_id', 'Sale', 'integer', ['is_show' => false]],
            ['item_id', 'Product', 'integer', ['is_required' => true] + $this->lookup('pos_products_lookup', 'label', ['json_config' => ['fill' => ['unit_price' => 'sale_price', 'tax_rate' => 'tax_rate']]])],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0.001', 'default_value' => '1']],
            ['unit_price', 'Price', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['discount_pct', 'Disc %', 'decimal', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['tax_rate', 'Tax %', 'decimal', ['default_value' => '0']],
            ['line_total', 'Total', 'decimal', ['render_type' => 'money']],
        ]);

        $payment = $this->meta('pos_sale_payment_form', 'Payment', 'form', 'pos_sale_payments', 'Money taken for a sale. A sale can be paid several ways; cash may be more than is due, and the rest is change.');
        $this->params($payment, [
            ['id', 'ID', 'integer', $this->key()],
            ['sale_id', 'Sale', 'integer', ['is_show' => false]],
            ['method_id', 'Paid by', 'integer', ['is_required' => true, 'default_value' => (string) $this->cashId] + $this->lookup('pos_payment_methods_lookup')],
            ['amount', 'Amount', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['reference', 'Reference', 'string'],
        ]);

        // The receipt: what a customer takes away. The narrowest page the designer has is A5, so it prints down the left of one.
        $printLine = $this->meta('pos_sale_line_print', 'Sale line (print)', 'form', 'pos_sale_lines', 'The columns a receipt shows.');
        $this->params($printLine, [
            ['id', 'ID', 'integer', $this->key()],
            ['sale_id', 'Sale', 'integer', ['is_show' => false]],
            ['item_name', 'Item', 'string'],
            ['quantity', 'Qty', 'decimal'],
            ['unit_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['discount_pct', 'Disc %', 'decimal'],
            ['line_total', 'Total', 'decimal', ['render_type' => 'money']],
        ]);
        $printPayment = $this->meta('pos_sale_payment_print', 'Payment (print)', 'form', 'pos_sale_payments', 'The payments a receipt shows.');
        $this->params($printPayment, [
            ['id', 'ID', 'integer', $this->key()],
            ['sale_id', 'Sale', 'integer', ['is_show' => false]],
            ['method_id', 'Paid by', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_payment_methods_grid']->id, 'display_field' => 'name']],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money']],
            ['reference', 'Reference', 'string'],
        ]);
        $report = $this->meta('pos_receipt_report', 'Receipt', 'report', 'pos_sales', 'The receipt of a sale.', null, [
            'title' => 'Receipt', 'number_field' => 'receipt_no', 'layout' => $this->receiptLayout(),
        ]);
        $money = ['render_type' => 'money'];
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['receipt_no', 'Receipt', 'string'],
            ['status', 'Status', 'string'],
            ['sold_at', 'Date', 'datetime'],
            ['register_id', 'Register', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_registers_grid']->id, 'display_field' => 'name']],
            ['cashier_name', 'Cashier', 'string'],
            ['customer_id', 'Customer', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_customers_grid']->id, 'display_field' => 'name']],
            ['receipt_header', 'Header', 'string'],
            ['receipt_footer', 'Footer', 'string'],
            ['lines', 'Items', 'detail', ['ref_meta_id' => $printLine->id, 'json_config' => ['foreign_key' => 'sale_id']]],
            ['payments', 'Payments', 'detail', ['ref_meta_id' => $printPayment->id, 'json_config' => ['foreign_key' => 'sale_id']]],
            ['subtotal', 'Subtotal', 'decimal', $money],
            ['discount_total', 'Discount', 'decimal', $money],
            ['tax_total', 'Of which tax', 'decimal', $money],
            ['total_amount', 'Total', 'decimal', $money],
            ['paid_amount', 'Paid', 'decimal', $money],
            ['change_due', 'Change', 'decimal', $money],
        ]);

        $scan = $this->m['pos_scan_get']->id;
        $shift = $this->m['pos_current_shift_get']->id;
        $form = $this->meta('pos_sale_form', 'New sale', 'form', 'pos_sales', 'Scan or type a barcode and press Enter (3*barcode for three), or pick products below, then enter what was paid.', null, ['print_meta_id' => $report->id], null, [
            'is_log' => true,
            'var_fnc_expression_string' => strtr(<<<'JS'
                const SCAN_GET = {scan};
                const SHIFT_GET = {shift};
                // Amounts as the server will work them out: prices include tax.
                function refresh() {
                  let gross = 0, off = 0, tax = 0, net = 0;
                  lines('lines').forEach((l, i) => {
                    if (!l.item_id) {
                      setLine('lines', i, 'line_total', '');
                      return;
                    }
                    const amount = round(num(l.quantity) * num(l.unit_price), 2);
                    const discount = round(amount * Math.min(Math.max(num(l.discount_pct), 0), 100) / 100, 2);
                    const total = round(amount - discount, 2);
                    const rate = num(l.tax_rate);
                    gross += amount;
                    off += discount;
                    net += total;
                    tax += round(total * rate / (100 + rate), 2);
                    setLine('lines', i, 'line_total', total);
                  });
                  const paid = round(sum('payments', 'amount'), 2);
                  set('subtotal', round(gross, 2));
                  set('discount_total', round(off, 2));
                  set('tax_total', round(tax, 2));
                  set('total_amount', round(net, 2));
                  set('paid_amount', paid);
                  set('change_due', round(Math.max(paid - net, 0), 2));
                }
                // Adds what was scanned: one more of the same line, the first empty line, or a new one.
                function addProduct(p, qty) {
                  const rows = lines('lines');
                  const same = rows.find((l) => String(l.item_id) === String(p.item_id) && !num(l.discount_pct));
                  if (same) {
                    same.quantity = String(num(same.quantity) + qty);
                    return;
                  }
                  const values = { item_id: String(p.item_id), quantity: String(qty), unit_price: String(p.sale_price), discount_pct: '0', tax_rate: String(p.tax_rate), line_total: '' };
                  const blank = rows.find((l) => !l.item_id);
                  if (blank) {
                    Object.assign(blank, values);
                  } else {
                    rows.push({ ...values, _key: 'scan-' + Date.now() + '-' + rows.length });
                  }
                }
                JS, ['{scan}' => (string) $scan, '{shift}' => (string) $shift]),
            'load_expression_string' => <<<'JS'
                ['shift_label', 'status', 'subtotal', 'discount_total', 'tax_total', 'total_amount', 'paid_amount', 'change_due'].forEach((f) => readonly(f));
                hide('status', mode === 'new');
                hide('complete_on_save', mode !== 'new');
                const shift = await fill(SHIFT_GET);
                set('shift_label', shift.label ?? 'No open shift');
                if (mode === 'new' && !shift.shift_id) {
                  message('You have no open shift. Open one under Shifts, or keep this sale for later by turning off Complete now.');
                }
                if (mode === 'edit' && get('status') !== 'held') {
                  hide('scan');
                  readonly('customer_id');
                  message('This sale is ' + get('status') + ' and cannot be changed.');
                } else if (mode === 'edit') {
                  message('This sale is held. Save your changes, then use Complete on its row in Sales.');
                }
                refresh();
                JS,
            'event_expression_string' => "if (detail === 'lines' || detail === 'payments') refresh();",
            'before_save_expression_string' => <<<'JS'
                // Enter in the scan field comes here: it adds the product and goes no further.
                const code = String(get('scan') ?? '').trim();
                if (code) {
                  const times = code.match(/^(\d+(?:\.\d+)?)\*(.+)$/);
                  const qty = times ? num(times[1]) : 1;
                  const barcode = times ? times[2] : code;
                  const product = await fill(SCAN_GET, { code: barcode });
                  set('scan', '');
                  if (!product.item_id) {
                    error('Nothing on sale has the code ' + barcode + '.');
                  }
                  addProduct(product, qty);
                  refresh();
                  error('Added ' + qty + ' x ' + product.name + '. Total ' + get('total_amount') + '.');
                }
                if (mode === 'edit' && get('status') !== 'held') {
                  error('This sale is finished and cannot be changed.');
                }
                const items = lines('lines');
                for (let i = items.length - 1; i >= 0; i--) {
                  if (!items[i].item_id) items.splice(i, 1);
                }
                const paying = lines('payments');
                for (let i = paying.length - 1; i >= 0; i--) {
                  if (!num(paying[i].amount)) paying.splice(i, 1);
                }
                refresh();
                if (!items.length) {
                  error('Scan or pick at least one product.');
                }
                if (items.some((l) => num(l.quantity) <= 0)) {
                  error('Every line needs a quantity above zero.');
                }
                if (mode === 'new' && get('complete_on_save') === true) {
                  const open = await fill(SHIFT_GET);
                  if (!open.shift_id) {
                    error('Open a shift before selling, or turn off Complete now to keep this sale for later.');
                  }
                  const due = round(num(get('total_amount')) - num(get('paid_amount')), 2);
                  if (due > 0) {
                    error('Still to pay: ' + due + '.');
                  }
                }
                JS,
        ]);
        $readOnlyMoney = ['render_type' => 'money', 'default_value' => '0'];
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['scan', 'Scan barcode', 'string'],
            ['customer_id', 'Customer (optional)', 'integer', $this->lookup('pos_customers_grid')],
            ['receipt_no', 'Receipt', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'R-{yy}{MM}{dd}{seq:4}']]]],
            ['shift_label', 'Shift', 'string'],
            ['total_amount', 'Total', 'decimal', $readOnlyMoney],
            ['paid_amount', 'Paid', 'decimal', $readOnlyMoney],
            ['change_due', 'Change', 'decimal', $readOnlyMoney],
            ['subtotal', 'Before discount', 'decimal', $readOnlyMoney],
            ['discount_total', 'Discount', 'decimal', $readOnlyMoney],
            ['tax_total', 'Of which tax', 'decimal', $readOnlyMoney],
            ['status', 'Status', 'string', ['default_value' => 'held', 'json_config' => ['options' => self::SALE_STATUSES]]],
            ['complete_on_save', 'Complete now (turn off to hold the sale)', 'boolean', ['default_value' => '1']],
            ['lines', 'Items', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'sale_id']]],
            ['payments', 'Payments', 'detail', ['ref_meta_id' => $payment->id, 'json_config' => ['foreign_key' => 'sale_id']]],
        ]);
        $this->flow($form, [
            'complete' => ['process' => $this->m['pos_complete_sale'], 'criteria' => [['field' => 'complete_on_save', 'op' => '=', 'value' => '1']], 'map' => ['sale_id' => 'id']],
        ]);

        $grid = $this->meta('pos_sales_grid', 'Sales', 'datagrid', 'pos_sales', 'Every sale. Finish a held one, print a receipt, take a return, or void.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Receipt'],
        ], <<<'SQL'
            SELECT s.id, s.receipt_no, s.status, s.sold_at, DATE(s.sold_at) AS sold_on, sh.shift_no, r.name AS register_name, s.cashier_name,
                   c.name AS customer_name, (SELECT COUNT(*) FROM pos_sale_lines l WHERE l.sale_id = s.id) AS line_count,
                   s.discount_total, s.tax_total, s.total_amount, s.paid_amount, s.change_due, s.void_reason, s.created_at
            FROM pos_sales s
            LEFT JOIN pos_shifts sh ON sh.id = s.shift_id
            LEFT JOIN pos_registers r ON r.id = s.register_id
            LEFT JOIN crm_customers c ON c.id = s.customer_id
            WHERE (:from_date IS NULL OR COALESCE(s.sold_at, s.created_at) >= :from_date)
              AND (:to_date IS NULL OR DATE(COALESCE(s.sold_at, s.created_at)) <= :to_date)
            SQL);
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'add', 'New sale', 'plus', $form, null, 1, null, false, false);
        $this->action($grid, 'edit', 'Open', 'edit', $form, null, 2, ['id' => 'id'], true, true);
        $this->action($grid, 'stage_query', 'Complete', 'check', $this->m['pos_complete_sale'], null, 3, ['sale_id' => 'id'], true, true);
        $this->action($grid, 'custom', 'Return', 'file', $this->form('pos_return_form', 'pos_returns'), $this->m['pos_return_start_get'], 4, ['id' => 'id'], true, false);
        $this->action($grid, 'stage_query', 'Cancel held sale', 'trash', $this->m['pos_cancel_held_sale'], null, 5, ['sale_id' => 'id'], true, false);
        $this->action($grid, 'stage_query', 'Void', 'trash', $this->m['pos_void_sale'], null, 6, ['sale_id' => 'id'], true, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['receipt_no', 'Receipt', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::SALE_STATUSES]]],
            ['sold_at', 'Sold', 'datetime'],
            ['shift_no', 'Shift', 'string', ['is_criteria' => true]],
            ['register_name', 'Register', 'string', ['is_criteria' => true]],
            ['cashier_name', 'Cashier', 'string', ['is_criteria' => true]],
            ['customer_name', 'Customer', 'string', ['is_criteria' => true]],
            ['line_count', 'Lines', 'integer'],
            ['discount_total', 'Discount', 'decimal', ['render_type' => 'money']],
            ['tax_total', 'Tax', 'decimal', ['render_type' => 'money']],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['paid_amount', 'Paid', 'decimal', ['render_type' => 'money']],
            ['change_due', 'Change', 'decimal', ['render_type' => 'money']],
            ['void_reason', 'Void reason', 'string'],
            ['from_date', 'From', 'date', ['is_criteria' => true, 'is_show' => false]],
            ['to_date', 'To', 'date', ['is_criteria' => true, 'is_show' => false]],
        ]);
        $this->params($this->meta('pos_sales_tabs', 'Sales', 'tabs', null, 'Sales waiting to be finished, today, and the rest.'), [
            ['today', 'Today', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['from_date' => '{today}', 'to_date' => '{today}']]]],
            ['held', 'Held', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['status' => 'held']]]],
            ['completed', 'Completed', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['status' => 'completed']]]],
            ['void', 'Void', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['status' => 'void']]]],
            ['all', 'All', 'string', ['ref_meta_id' => $grid->id]],
        ]);
    }

    /** @return array<string, mixed> a receipt on the left 82 mm of an A5 page */
    private function receiptLayout(): array
    {
        $text = fn (string $id, float $x, float $y, float $w, float $h, string $text, array $style = []) => ['id' => $id, 'type' => 'text', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'text' => $text, 'style' => $style];
        $row = function (string $id, float $y, string $label, string $field, bool $bold = false) use ($text) {
            return [
                $text("{$id}l", 0, $y, 42, 5, $label, ['bold' => $bold]),
                $text("{$id}v", 42, $y, 40, 5, "{{$field}|number}", ['bold' => $bold, 'align' => 'right']),
            ];
        };

        return [
            'page' => ['size' => 'A5', 'margin' => ['top' => 6, 'right' => 60, 'bottom' => 6, 'left' => 6], 'font' => 'sans', 'font_size' => 8, 'decimals' => 0, 'currency' => '₮'],
            'header' => ['height' => 0, 'elements' => []],
            'footer' => ['height' => 0, 'elements' => []],
            'sections' => [
                ['id' => 'head', 'kind' => 'free', 'height' => 31, 'elements' => [
                    $text('h1', 0, 0, 82, 6, '{receipt_header}', ['font_size' => 10, 'bold' => true, 'align' => 'center']),
                    $text('h2', 0, 7, 82, 5, 'Receipt {receipt_no}', ['bold' => true, 'align' => 'center']),
                    $text('h3', 0, 12, 82, 5, '{sold_at|datetime}', ['align' => 'center']),
                    $text('h4', 0, 18, 82, 5, 'Register: {register_id}'),
                    $text('h5', 0, 22, 82, 5, 'Cashier: {cashier_name}'),
                    $text('h6', 0, 26, 82, 5, 'Customer: {customer_id}'),
                ]],
                ['id' => 'items', 'kind' => 'table', 'detail' => 'lines', 'numbered' => false, 'border' => false, 'font_size' => 7.5, 'columns' => [
                    ['id' => 'c1', 'field' => 'item_name', 'label' => 'Item', 'width' => 3.4, 'align' => 'left', 'format' => 'text'],
                    ['id' => 'c2', 'field' => 'quantity', 'label' => 'Qty', 'width' => 0.9, 'align' => 'right', 'format' => 'number'],
                    ['id' => 'c3', 'field' => 'unit_price', 'label' => 'Price', 'width' => 1.4, 'align' => 'right', 'format' => 'number'],
                    ['id' => 'c4', 'field' => 'discount_pct', 'label' => '%', 'width' => 0.7, 'align' => 'right', 'format' => 'number'],
                    ['id' => 'c5', 'field' => 'line_total', 'label' => 'Total', 'width' => 1.6, 'align' => 'right', 'format' => 'number'],
                ]],
                ['id' => 'totals', 'kind' => 'free', 'height' => 28, 'elements' => array_merge(
                    [['id' => 't0', 'type' => 'line', 'x' => 0, 'y' => 1, 'w' => 82, 'h' => 0]],
                    $row('t1', 2, 'Before discount', 'subtotal'),
                    $row('t2', 7, 'Discount', 'discount_total'),
                    $row('t3', 12, 'Total', 'total_amount', true),
                    $row('t4', 17, 'Of which tax', 'tax_total'),
                    $row('t5', 22, 'Change', 'change_due'),
                )],
                ['id' => 'paid', 'kind' => 'table', 'detail' => 'payments', 'numbered' => false, 'border' => false, 'font_size' => 7.5, 'columns' => [
                    ['id' => 'p1', 'field' => 'method_id', 'label' => 'Paid by', 'width' => 3, 'align' => 'left', 'format' => 'text'],
                    ['id' => 'p2', 'field' => 'reference', 'label' => 'Reference', 'width' => 2.5, 'align' => 'left', 'format' => 'text'],
                    ['id' => 'p3', 'field' => 'amount', 'label' => 'Amount', 'width' => 2, 'align' => 'right', 'format' => 'number'],
                ]],
                ['id' => 'foot', 'kind' => 'free', 'height' => 12, 'elements' => [
                    $text('f1', 0, 4, 82, 6, '{receipt_footer}', ['align' => 'center']),
                ]],
            ],
        ];
    }

    // ---------------------------------------------------------------- returns

    private function returns(): void
    {
        $line = $this->meta('pos_return_line_form', 'Return line', 'form', 'pos_return_lines', 'What comes back. The refund is what the sale charged for it; turn off Back in stock for damaged goods.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['return_id', 'Return', 'integer', ['is_show' => false]],
            ['item_id', 'Product', 'integer', ['is_required' => true] + $this->lookup('pos_products_lookup', 'label')],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0.001', 'default_value' => '1']],
            ['unit_price', 'Refund each', 'decimal', ['render_type' => 'money']],
            ['line_total', 'Refund', 'decimal', ['render_type' => 'money']],
            ['restock', 'Back in stock', 'boolean', ['default_value' => '1']],
        ]);

        $printLine = $this->meta('pos_return_line_print', 'Return line (print)', 'form', 'pos_return_lines', 'The columns a return slip shows.');
        $this->params($printLine, [
            ['id', 'ID', 'integer', $this->key()],
            ['return_id', 'Return', 'integer', ['is_show' => false]],
            ['item_name', 'Item', 'string'],
            ['quantity', 'Qty', 'decimal'],
            ['unit_price', 'Refund each', 'decimal', ['render_type' => 'money']],
            ['line_total', 'Refund', 'decimal', ['render_type' => 'money']],
        ]);
        $report = $this->meta('pos_return_report', 'Return slip', 'report', 'pos_returns', 'The slip of a return, for the customer to sign.', null, [
            'title' => 'Return slip', 'number_field' => 'return_no', 'note' => 'Refund received.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['return_no', 'Return', 'string'],
            ['sale_id', 'Receipt', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_sales_lookup_stub']->id, 'display_field' => 'receipt_no']],
            ['status', 'Status', 'string'],
            ['returned_at', 'Date', 'datetime'],
            ['cashier_name', 'Cashier', 'string'],
            ['refund_method_id', 'Refunded by', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pos_payment_methods_grid']->id, 'display_field' => 'name']],
            ['reason', 'Reason', 'string'],
            ['lines', 'Items', 'detail', ['ref_meta_id' => $printLine->id, 'json_config' => ['foreign_key' => 'return_id']]],
            ['total_amount', 'Refund', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['customer_signature', 'Customer', 'signature'],
        ]);

        $shift = $this->m['pos_current_shift_get']->id;
        $next = $this->m['pos_return_line_get']->id;
        $form = $this->m['pos_return_form'];
        $form->update([
            'description' => 'Pick the receipt: what is left of it fills in. Change the quantities, remove what stays with the customer.',
            'json_config' => ['print_meta_id' => $report->id],
            'is_log' => true,
            'var_fnc_expression_string' => strtr(<<<'JS'
                const SHIFT_GET = {shift};
                const LEFT_GET = {next};
                function refresh() {
                  let total = 0;
                  lines('lines').forEach((l, i) => {
                    const amount = round(num(l.quantity) * num(l.unit_price), 2);
                    total += amount;
                    setLine('lines', i, 'line_total', amount);
                  });
                  set('total_amount', round(total, 2));
                }
                // One read per item of the sale: a get gives one row.
                async function takeLines() {
                  const rows = lines('lines');
                  rows.length = 0;
                  const sale = get('sale_id');
                  if (!sale) return;
                  for (let n = 1; n <= 200; n++) {
                    const left = await fill(LEFT_GET, { sale_id: String(sale), n: String(n) });
                    if (!left.item_id) break;
                    rows.push({ _key: 'return-' + sale + '-' + n, item_id: String(left.item_id), quantity: String(num(left.left_qty)), unit_price: String(left.unit_price), line_total: '', restock: true });
                  }
                  if (!rows.length) message('Everything on this receipt has already come back.');
                  refresh();
                }
                JS, ['{shift}' => (string) $shift, '{next}' => (string) $next]),
            'load_expression_string' => <<<'JS'
                readonly('status');
                readonly('total_amount');
                hide('status', mode === 'new');
                hide('complete_on_save', mode !== 'new');
                if (mode === 'new' && get('sale_id')) {
                  await takeLines();
                } else {
                  refresh();
                }
                if (mode === 'edit' && get('status') !== 'draft') {
                  readonly('sale_id');
                  readonly('refund_method_id');
                  readonly('reason');
                  message('This return is completed and cannot be changed.');
                } else if (mode === 'edit') {
                  message('This return is a draft. Save your changes, then use Complete on its row in Returns.');
                }
                JS,
            'event_expression_string' => <<<'JS'
                if (field === 'sale_id' && !detail) await takeLines();
                if (detail === 'lines') refresh();
                JS,
            'before_save_expression_string' => <<<'JS'
                if (mode === 'edit' && get('status') !== 'draft') {
                  error('This return is completed and cannot be changed.');
                }
                const rows = lines('lines');
                for (let i = rows.length - 1; i >= 0; i--) {
                  if (!rows[i].item_id) rows.splice(i, 1);
                }
                refresh();
                if (!rows.length) {
                  error('Nothing to return: pick a receipt with items left on it.');
                }
                if (rows.some((l) => num(l.quantity) <= 0)) {
                  error('Every line needs a quantity above zero.');
                }
                if (mode === 'new' && get('complete_on_save') === true) {
                  const open = await fill(SHIFT_GET);
                  if (!open.shift_id) {
                    error('Open a shift before taking a return, or turn off Complete now to keep it as a draft.');
                  }
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['return_no', 'Return', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'RT-{yy}{MM}{dd}{seq:3}']]]],
            ['sale_id', 'Receipt', 'integer', ['is_required' => true] + $this->lookup('pos_sales_lookup_stub', 'label')],
            ['refund_method_id', 'Refund by', 'integer', ['is_required' => true, 'default_value' => (string) $this->cashId] + $this->lookup('pos_payment_methods_lookup')],
            ['reason', 'Reason', 'string', $this->options(['changed mind', 'wrong item', 'faulty', 'damaged', 'expired', 'other'], 'changed mind')],
            ['total_amount', 'Refund', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['status', 'Status', 'string', ['default_value' => 'draft', 'json_config' => ['options' => self::RETURN_STATUSES]]],
            ['complete_on_save', 'Complete now (turn off to keep a draft)', 'boolean', ['default_value' => '1']],
            ['lines', 'Items coming back', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'return_id']]],
        ]);
        $this->flow($form, [
            'complete' => ['process' => $this->m['pos_complete_return'], 'criteria' => [['field' => 'complete_on_save', 'op' => '=', 'value' => '1']], 'map' => ['return_id' => 'id']],
        ]);

        $grid = $this->meta('pos_returns_grid', 'Returns', 'datagrid', 'pos_returns', 'Goods that came back, and what was refunded.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Return slip'],
        ], <<<'SQL'
            SELECT r.id, r.return_no, r.status, r.returned_at, s.receipt_no, sh.shift_no, r.cashier_name, m.name AS refund_method, r.reason, r.total_amount
            FROM pos_returns r
            JOIN pos_sales s ON s.id = r.sale_id
            LEFT JOIN pos_shifts sh ON sh.id = r.shift_id
            LEFT JOIN pos_payment_methods m ON m.id = r.refund_method_id
            SQL);
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'add', 'New return', 'plus', $form, null, 1, null, false, false);
        $this->action($grid, 'edit', 'Open', 'edit', $form, null, 2, ['id' => 'id'], true, true);
        $this->action($grid, 'stage_query', 'Complete', 'check', $this->m['pos_complete_return'], null, 3, ['return_id' => 'id'], true, true);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['return_no', 'Return', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::RETURN_STATUSES]]],
            ['returned_at', 'Returned', 'datetime'],
            ['receipt_no', 'Receipt', 'string', ['is_criteria' => true]],
            ['shift_no', 'Shift', 'string', ['is_criteria' => true]],
            ['cashier_name', 'Cashier', 'string', ['is_criteria' => true]],
            ['refund_method', 'Refund by', 'string'],
            ['reason', 'Reason', 'string', ['is_criteria' => true]],
            ['total_amount', 'Refund', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);
    }

    // ---------------------------------------------------------------- stock on hand and what sold

    private function lists(): void
    {
        $stock = $this->meta('pos_stock_grid', 'Stock on hand', 'datagrid', 'proc_items', 'What the warehouses of the registers hold of each product. Stock arrives through the Warehouse app.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT i.id, i.code, i.name, c.name AS category, i.unit, p.barcode, p.sale_price, COALESCE(s.on_hand, 0) AS on_hand, COALESCE(s.stock_value, 0) AS stock_value,
                   v.min_qty, CASE WHEN COALESCE(s.on_hand, 0) < v.min_qty THEN 1 ELSE 0 END AS below_min
            FROM pos_products p
            JOIN proc_items i ON i.id = p.item_id
            LEFT JOIN proc_item_categories c ON c.id = i.category_id
            LEFT JOIN (SELECT w.item_id, SUM(w.quantity) AS on_hand, SUM(w.amount) AS stock_value FROM wh_stocks w
                       WHERE w.warehouse_id IN (SELECT r.warehouse_id FROM pos_registers r) GROUP BY w.item_id) s ON s.item_id = i.id
            LEFT JOIN (SELECT l.item_id, SUM(l.min_qty) AS min_qty FROM wh_stock_levels l
                       WHERE l.warehouse_id IN (SELECT r.warehouse_id FROM pos_registers r) GROUP BY l.item_id) v ON v.item_id = i.id
            WHERE COALESCE(c.is_service, 0) = 0
            SQL);
        $this->params($stock, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['barcode', 'Barcode', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['on_hand', 'On hand', 'decimal'],
            ['min_qty', 'Minimum', 'decimal'],
            ['below_min', 'Low', 'boolean', ['is_criteria' => true]],
            ['sale_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['stock_value', 'Value at cost', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);

        $sold = $this->meta('pos_sale_lines_grid', 'Sold items', 'datagrid', 'pos_sale_lines', 'Every line of every completed sale.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT l.id, s.receipt_no, s.sold_at, l.item_name, s.cashier_name, l.quantity, l.unit_price, l.discount_pct, l.discount_amount, l.tax_amount, l.line_total
            FROM pos_sale_lines l
            JOIN pos_sales s ON s.id = l.sale_id
            WHERE s.status = 'completed'
              AND (:from_date IS NULL OR s.sold_at >= :from_date)
              AND (:to_date IS NULL OR DATE(s.sold_at) <= :to_date)
            SQL);
        $this->params($sold, [
            ['id', 'ID', 'integer', $this->key()],
            ['receipt_no', 'Receipt', 'string', ['is_criteria' => true]],
            ['sold_at', 'Sold', 'datetime'],
            ['item_name', 'Item', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['cashier_name', 'Cashier', 'string', ['is_criteria' => true]],
            ['quantity', 'Qty', 'decimal', ['json_config' => ['aggregate' => 'sum']]],
            ['unit_price', 'Price', 'decimal', ['render_type' => 'money']],
            ['discount_pct', 'Disc %', 'decimal'],
            ['discount_amount', 'Discount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['tax_amount', 'Tax', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['line_total', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['from_date', 'From', 'date', ['is_criteria' => true, 'is_show' => false]],
            ['to_date', 'To', 'date', ['is_criteria' => true, 'is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): void
    {
        $today = "SELECT s.total_amount FROM pos_sales s WHERE s.status = 'completed' AND DATE(s.sold_at) = CURDATE()";
        $completed = "s.status = 'completed'";
        $kpi = fn (string $agg, ?string $column, string $label, ?string $format = null) => array_filter([
            'type' => 'kpi', 'measures' => [array_filter(['agg' => $agg, 'column' => $column, 'label' => $label])], 'format' => $format,
        ]);
        $toGrid = fn (string $grid) => ['drilldown' => ['meta_id' => $this->m[$grid]->id, 'params' => []]];

        $charts = [
            [$this->chart('pos_chart_today_sales', "Today's sales", null, $today, 'Everything sold today, tax included.', $kpi('sum', 'total_amount', 'Sales', 'money') + $toGrid('pos_sales_tabs')), 3, 1],
            [$this->chart('pos_chart_today_receipts', 'Receipts today', null, $today, 'Completed sales today.', $kpi('count', null, 'Receipts')), 3, 1],
            [$this->chart('pos_chart_today_basket', 'Average receipt today', null, $today, 'What a customer spends, on average.', $kpi('avg', 'total_amount', 'Average', 'money')), 3, 1],
            [$this->chart('pos_chart_today_returns', 'Returns today', null, "SELECT r.total_amount FROM pos_returns r WHERE r.status = 'completed' AND DATE(r.returned_at) = CURDATE()", 'Refunded today.', $kpi('sum', 'total_amount', 'Refunds', 'money') + $toGrid('pos_returns_grid')), 3, 1],
            [$this->chart('pos_chart_held', 'Held sales', null, "SELECT s.id FROM pos_sales s WHERE s.status = 'held'", 'Sales waiting to be finished.', $kpi('count', null, 'Sales')), 3, 1],
            [$this->chart('pos_chart_open_shifts', 'Open shifts', null, "SELECT s.id FROM pos_shifts s WHERE s.status = 'open'", 'Registers in use now.', $kpi('count', null, 'Shifts') + $toGrid('pos_shifts_grid')), 3, 1],
            [$this->chart('pos_chart_by_day', 'Sales by day', null, "SELECT DATE(s.sold_at) AS sold_on, s.total_amount FROM pos_sales s WHERE {$completed}", 'Sales day by day, in the period picked above.',
                ['type' => 'column', 'x' => 'sold_on', 'bucket' => 'day', 'date_field' => 'sold_on', 'format' => 'money', 'measures' => [['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Sales']]]), 6, 2],
            [$this->chart('pos_chart_by_method', 'Sales by payment method', null, <<<SQL
                SELECT m.name AS method, DATE(s.sold_at) AS sold_on, p.amount
                FROM pos_sale_payments p
                JOIN pos_sales s ON s.id = p.sale_id
                JOIN pos_payment_methods m ON m.id = p.method_id
                WHERE {$completed}
                UNION ALL
                SELECT (SELECT c.name FROM pos_payment_methods c WHERE c.is_cash = 1 ORDER BY c.id LIMIT 1), DATE(s.sold_at), -s.change_due
                FROM pos_sales s
                WHERE {$completed} AND s.change_due != 0
                SQL, 'What came in each way, with change taken off the cash.',
                ['type' => 'donut', 'x' => 'method', 'date_field' => 'sold_on', 'format' => 'money', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Paid']]]), 6, 2],
            [$this->chart('pos_chart_top_products', 'Top products', null, <<<SQL
                SELECT l.item_name AS product, DATE(s.sold_at) AS sold_on, l.quantity, l.line_total
                FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id
                WHERE {$completed}
                SQL, 'What sold best, by value.',
                ['type' => 'table', 'x' => 'product', 'limit' => 10, 'date_field' => 'sold_on', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'line_total', 'label' => 'Sales'],
                    ['agg' => 'sum', 'column' => 'quantity', 'label' => 'Qty'],
                ]]), 4, 2],
            [$this->chart('pos_chart_by_cashier', 'Sales by cashier', null, <<<SQL
                SELECT COALESCE(s.cashier_name, 'Unknown') AS cashier, DATE(s.sold_at) AS sold_on, s.total_amount
                FROM pos_sales s WHERE {$completed}
                SQL, 'Who sold how much.',
                ['type' => 'bar', 'x' => 'cashier', 'sort' => 'value', 'date_field' => 'sold_on', 'format' => 'money', 'measures' => [['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Sales']]]), 4, 2],
            [$this->chart('pos_chart_by_shift', 'Sales by shift', null, <<<SQL
                SELECT CONCAT(sh.shift_no, ' · ', COALESCE(s.cashier_name, '')) AS shift_name, DATE(s.sold_at) AS sold_on, s.total_amount
                FROM pos_sales s JOIN pos_shifts sh ON sh.id = s.shift_id
                WHERE {$completed}
                SQL, 'The latest shifts, by what they sold.',
                ['type' => 'table', 'x' => 'shift_name', 'limit' => 10, 'date_field' => 'sold_on', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Sales'],
                    ['agg' => 'count', 'label' => 'Receipts'],
                ]]), 4, 2],
        ];

        $dashboard = $this->meta('pos_dashboard', 'Sales dashboard', 'dashboard', null, 'Today at the top; below, the period picked above.');
        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'pos_chart_');
            MetaParam::create([
                'main_meta_id' => $dashboard->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field,
                'data_type' => 'chart', 'ref_meta_id' => $chart->id, 'json_config' => ['w' => $w, 'h' => $h], 'is_show' => true,
            ]);
        }
    }

    // ---------------------------------------------------------------- the app and who may use it

    private function app(): void
    {
        $menu = fn (string $field, string $label, string $code, ?string $icon = null) => [$field, $label, 'string', array_filter([
            'ref_meta_id' => $this->m[$code]->id, 'icon_cls' => $icon,
        ], fn ($v) => $v !== null)];
        $group = fn (string $field, string $label, string $icon, array $children) => [$field, $label, 'string', ['icon_cls' => $icon, 'children' => $children]];

        $app = $this->meta('pos_app', 'Point of sale', 'app', null, 'Sell at the till: sales with split payments and receipts, returns, shifts and cash counts, products, and how the day is going.', null, ['menu_style' => 'floating']);
        $this->params($app, [
            $menu('sell', 'New sale', 'pos_sale_form', 'cart'),
            $menu('sales', 'Sales', 'pos_sales_tabs', 'receipt'),
            $menu('returns', 'Returns', 'pos_returns_grid', 'file'),
            $menu('shifts', 'Shifts', 'pos_shifts_grid', 'calendar'),
            $menu('dashboard', 'Dashboard', 'pos_dashboard', 'chart'),
            $menu('customers', 'Customers', 'pos_customers_grid', 'users'),
            $group('products', 'Products', 'package', [
                $menu('products', 'Products', 'pos_products_tabs'),
                $menu('categories', 'Categories', 'pos_categories_grid'),
                $menu('taxes', 'Tax rates', 'pos_tax_rates_grid'),
                $menu('stock', 'Stock on hand', 'pos_stock_grid'),
                $menu('sold', 'Sold items', 'pos_sale_lines_grid'),
            ]),
            $group('setup', 'Setup', 'settings', [
                $menu('registers', 'Registers', 'pos_registers_grid'),
                $menu('methods', 'Payment methods', 'pos_payment_methods_grid'),
            ]),
        ]);
    }

    /**
     * pos_manager: everything here, plus reading the warehouses and locations a register points at.
     * pos_cashier: what running a till takes. Metas it gets no row on stay closed to it: void,
     * the dashboard, products, setup. The stock grid and the products pick list are read-only.
     */
    private function access(): void
    {
        $manager = Role::updateOrCreate(['code' => 'pos_manager'], ['name' => 'Store manager', 'description' => 'Runs the shop: voids sales, closes any shift, keeps products, prices and registers, sees the sales dashboard.']);
        $cashier = Role::updateOrCreate(['code' => 'pos_cashier'], ['name' => 'Cashier', 'description' => 'Works a till: opens and closes their own shift, sells, holds sales and takes returns.']);
        $tag = Tag::firstOrCreate(['name' => 'pos'], ['color' => 'purple']);
        $types = array_flip($this->types);
        $ids = [];

        foreach ($this->m as $meta) {
            $type = $types[$meta->type_id] ?? null;
            $full = $type === 'form';
            MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $manager->id], [
                'can_view' => true, 'can_create' => $full, 'can_edit' => $full || $type === 'stage_query', 'can_delete' => $full,
            ]);
            $ids[] = $meta->id;
        }
        foreach ($this->ext as $meta) {
            MetaPermission::firstOrCreate(['meta_id' => $meta->id, 'role_id' => $manager->id], ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]);
        }

        $view = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
        $sell = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false];
        $run = ['can_view' => true, 'can_create' => false, 'can_edit' => true, 'can_delete' => false];
        $add = ['can_view' => true, 'can_create' => true, 'can_edit' => false, 'can_delete' => false];
        $rights = [
            'pos_app' => $view, 'pos_sales_tabs' => $view, 'pos_sales_grid' => $view, 'pos_returns_grid' => $view, 'pos_shifts_grid' => $view,
            'pos_customers_grid' => $view, 'pos_stock_grid' => $view, 'pos_sale_lines_grid' => $view,
            'pos_products_lookup' => $view, 'pos_payment_methods_lookup' => $view, 'pos_payment_methods_grid' => $view, 'pos_registers_lookup' => $view, 'pos_registers_grid' => $view, 'pos_sales_lookup_stub' => $view,
            'pos_scan_get' => $view, 'pos_current_shift_get' => $view, 'pos_shift_close_get' => $view, 'pos_return_start_get' => $view, 'pos_return_line_get' => $view,
            'pos_sale_line_form' => $view, 'pos_sale_payment_form' => $view, 'pos_return_line_form' => $view,
            'pos_sale_line_print' => $view, 'pos_sale_payment_print' => $view, 'pos_return_line_print' => $view,
            'pos_receipt_report' => $view, 'pos_return_report' => $view, 'pos_shift_report' => $view,
            'pos_sale_form' => $sell, 'pos_return_form' => $sell, 'pos_shift_form' => $add, 'pos_customer_form' => $add, 'pos_shift_close_form' => $run,
            'pos_claim_shift' => $run, 'pos_complete_sale' => $run, 'pos_cancel_held_sale' => $run, 'pos_complete_return' => $run,
        ];
        foreach ($rights as $code => $can) {
            MetaPermission::updateOrCreate(['meta_id' => $this->m[$code]->id, 'role_id' => $cashier->id], $can);
        }

        DB::table('meta_tag_map')->where('tag_id', $tag->id)->whereNotIn('meta_id', $ids)->delete();
        $tagged = DB::table('meta_tag_map')->where('tag_id', $tag->id)->pluck('meta_id')->all();
        $now = now();
        foreach (array_diff($ids, $tagged) as $id) {
            DB::table('meta_tag_map')->insert(['meta_id' => $id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000013)

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null, array $extra = []): Meta
    {
        return $this->m[$code] = Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type] ?? null,
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'query_string' => $query,
            'is_active' => true,
        ] + $extra);
    }

    /**
     * A form or pick list that an earlier meta needs before its own turn comes: the return form
     * on the sales grid, and the receipts a return picks from. Filled in by returns().
     */
    private function form(string $code, string $table): Meta
    {
        if (! isset($this->m['pos_sales_lookup_stub'])) {
            $lookup = $this->meta('pos_sales_lookup', 'Receipts (pick list)', 'datagrid', 'pos_sales', 'Completed sales, to take a return against.', null, null, <<<'SQL'
                SELECT s.id, s.receipt_no, s.sold_at, s.total_amount, c.name AS customer_name,
                       CONCAT(s.receipt_no, ' · ', DATE(s.sold_at), COALESCE(CONCAT(' · ', c.name), '')) AS label
                FROM pos_sales s
                LEFT JOIN crm_customers c ON c.id = s.customer_id
                WHERE s.status = 'completed'
                SQL);
            $this->params($lookup, [
                ['id', 'ID', 'integer', $this->key()],
                ['label', 'Receipt', 'string', ['is_label' => true, 'is_show' => false]],
                ['receipt_no', 'Receipt', 'string', ['is_criteria' => true]],
                ['sold_at', 'Sold', 'datetime'],
                ['customer_name', 'Customer', 'string', ['is_criteria' => true]],
                ['total_amount', 'Total', 'decimal', ['render_type' => 'money']],
            ]);
            $this->m['pos_sales_lookup_stub'] = $lookup;
        }

        return $this->meta($code, 'New return', 'form', $table, 'A return against a receipt.');
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

    /** Add and Edit, and Delete unless the records belong to more than this app. */
    private function actions(Meta $grid, Meta $form, bool $delete = true): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'add', 'Add', 'plus', $form, null, 1, null, false, false);
        $this->action($grid, 'edit', 'Edit', 'edit', $form, null, 2, ['id' => 'id'], true, true);
        if ($delete) {
            $this->action($grid, 'delete', 'Delete', 'trash', $form, null, 3, ['id' => 'id'], true, true);
        }
    }

    private function action(Meta $grid, string $action, string $label, string $icon, Meta $target, ?Meta $get, int $order, ?array $params, bool $needsRow, bool $inRow): void
    {
        MetaAction::create([
            'grid_meta_id' => $grid->id, 'action' => $action, 'label' => $label, 'icon_cls' => $icon, 'form_meta_id' => $target->id,
            'get_meta_id' => $get?->id, 'needs_row' => $needsRow, 'show_in_row' => $inRow, 'order_number' => $order,
            'json_config' => $params ? ['params' => $params] : null,
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
};
