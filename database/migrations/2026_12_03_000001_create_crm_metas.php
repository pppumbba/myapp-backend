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
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The CRM app, built only from metas (tables: 2026_12_03_000000_create_crm_tables):
 *
 *   CRM (app crm_app)
 *   ├─ Dashboard            pipeline by stage, sales by month, AR ageing, open complaints, leads by source
 *   ├─ Customers            registry (sidebar filters, opens the customer workspace), workspace,
 *   │                       contacts, activities, follow-ups (grid + calendar)
 *   ├─ Sales pipeline       leads (workflow, Convert = process flow), opportunities (tabs per stage),
 *   │                       proposals and quotations (one table, menu presets, workflow, PDF)
 *   ├─ Catalogue & prices   items (proc_items, shared with Procurement), services, price lists, price finder
 *   ├─ Sales & purchases    sales orders, invoices, purchase orders (the Procurement ones), suppliers
 *   ├─ Finance (AR)         receivables, customer balances, ageing statement, payments register
 *   ├─ Service              complaints (workflow, notifies the assignee), feedback
 *   └─ Setup                customer categories, sales reps
 *
 * Process flows: lead conversion form -> customer form -> opportunity form -> finish (stage
 * query); a sales order made from an accepted quotation copies its lines; an invoice made
 * from an order copies its lines; a customer saved as a supplier gets a proc_suppliers row.
 *
 * Idempotent: metas are matched by code and their params, actions and process flows are
 * rebuilt; workflow statuses keep their ids. The role "crm" gets the permissions. MySQL
 * only (the queries use MySQL date functions), so the SQLite test database skips it.
 */
return new class extends Migration
{
    private const SOURCES = ['website', 'referral', 'cold call', 'event', 'social media', 'advertising', 'other'];

    private const STAGES = ['prospecting', 'qualification', 'proposal', 'negotiation', 'won', 'lost'];

    private const UNITS = ['pcs', 'box', 'kg', 'l', 'm', 'pack', 'set'];

    /** @var array<string, int> meta type code => id */
    private array $types = [];

    /** @var array<string, Meta> every CRM meta made here, by code */
    private array $m = [];

    /** @var array<string, array<string, int>> workflow code => status code => id */
    private array $status = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        if (! isset($this->types['workspace'], $this->types['stage_query'], $this->types['report'])) {
            (new MetaTypeSeeder)->run();
            $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        }

        DB::transaction(function () {
            $this->build();
        });

        // DDL commits on MySQL, so after the transaction: new records start in the first status.
        foreach ([['crm_leads', 'crm_lead_workflow', 'new'], ['crm_quotations', 'crm_quotation_workflow', 'draft'], ['crm_complaints', 'crm_complaint_workflow', 'open']] as [$table, $workflow, $code]) {
            DB::statement(sprintf('ALTER TABLE `%s` ALTER `status_id` SET DEFAULT %d', $table, $this->status[$workflow][$code]));
            DB::table($table)->whereNull('status_id')->update(['status_id' => $this->status[$workflow][$code]]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['crm_leads', 'crm_quotations', 'crm_complaints'] as $table) {
            if (Schema::hasTable($table)) {
                DB::statement(sprintf('ALTER TABLE `%s` ALTER `status_id` DROP DEFAULT', $table));
            }
        }

        DB::transaction(function () {
            $ids = DB::table('metas')->where('code', 'like', 'crm\_%')->pluck('id');
            DB::table('wfm_status_log')->whereIn('workflow_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('ref_meta_id', $ids)->whereNotIn('id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('roles')->where('code', 'crm')->delete();
            DB::table('tags')->where('name', 'crm')->delete();
            DB::table('autonumbers')->where('table_name', 'like', 'crm\_%')->delete();
        });
    }

    private function build(): void
    {
        $this->setup();
        $this->catalogue();
        $this->workflows();
        $this->customers();
        $this->leads();
        $this->opportunities();
        $this->activities();
        $this->quotations();
        $this->salesAndInvoices();
        $this->receivables();
        $this->service();
        $this->workspace();
        $this->dashboard();
        $this->app();
        $this->access();
    }

    // ---------------------------------------------------------------- setup and catalogue

    private function setup(): void
    {
        $form = $this->meta('crm_customer_category_form', 'Customer category form', 'form', 'crm_customer_categories', 'A group of customers, e.g. retail or wholesale; price lists can be for one.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['discount_pct', 'Usual discount %', 'decimal', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('crm_customer_categories_grid', 'Customer categories', 'datagrid', 'crm_customer_categories', 'Groups of customers.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['discount_pct', 'Usual discount %', 'decimal'],
            ['notes', 'Notes', 'text'],
        ]);

        // Users can't be a meta's table, but a query may read them: the sales reps to pick as owners.
        $users = $this->meta('crm_users_lookup', 'Sales reps', 'datagrid', null, 'Everyone who can sign in, to pick as an owner or assignee.', null, null, 'SELECT id, name, email FROM users');
        $this->params($users, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
        ]);
    }

    private function catalogue(): void
    {
        // Items are the Procurement catalogue (proc_items): one list for buying and selling.
        $itemForm = $this->meta('crm_item_form', 'Item form', 'form', 'proc_items', 'A product we buy or sell (shared with Procurement).');
        $this->params($itemForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Item code', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true, 'is_label' => true]],
            ['category', 'Category', 'string'],
            ['unit', 'Unit', 'string', $this->options(self::UNITS, 'pcs')],
            ['unit_price', 'Base price', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
        ]);
        $itemGrid = $this->meta('crm_items_grid', 'Items', 'datagrid', 'proc_items', 'Products, shared with Procurement; selling prices come from price lists.');
        $this->actions($itemGrid, $itemForm);
        $this->params($itemGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['unit_price', 'Base price', 'decimal', ['render_type' => 'money']],
        ]);

        $serviceForm = $this->meta('crm_service_form', 'Service form', 'form', 'crm_services', 'A service we sell: consulting, installation, support...');
        $this->params($serviceForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['category', 'Category', 'string', $this->options(['consulting', 'installation', 'support', 'training', 'maintenance', 'subscription'], 'support')],
            ['unit', 'Unit', 'string', $this->options(['hour', 'day', 'month', 'visit', 'job'], 'hour')],
            ['rate', 'Rate', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['description', 'Description', 'text', ['render_type' => 'textarea']],
        ]);
        $serviceGrid = $this->meta('crm_services_grid', 'Services', 'datagrid', 'crm_services', 'Services we sell and their standard rates.');
        $this->actions($serviceGrid, $serviceForm);
        $this->params($serviceGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['rate', 'Rate', 'decimal', ['render_type' => 'money']],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        $lineForm = $this->meta('crm_price_list_line_form', 'Price list line', 'form', 'crm_price_list_lines', 'The price of one item or service on a price list.');
        $this->params($lineForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['price_list_id', 'Price list', 'integer', ['is_show' => false]],
            ['item_id', 'Item', 'integer', $this->lookup('crm_items_grid', 'name', ['json_config' => ['fill' => ['price' => 'unit_price']]])],
            ['service_id', 'Service', 'integer', $this->lookup('crm_services_grid', 'name', ['json_config' => ['fill' => ['price' => 'rate']]])],
            ['price', 'Price', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['min_qty', 'From qty', 'decimal', ['min_value' => '0', 'default_value' => '1']],
            ['notes', 'Note', 'string'],
        ]);
        $listForm = $this->meta('crm_price_list_form', 'Price list form', 'form', 'crm_price_lists', 'A price list: prices of items and services, for a period and a customer category.', null, null, null, [
            'before_save_expression_string' => <<<'JS'
                if (lines('lines').some((l) => !l.item_id && !l.service_id)) {
                  error('Each line needs an item or a service.');
                }
                if (get('valid_from') && get('valid_to') && get('valid_to') < get('valid_from')) {
                  error('"Valid to" is before "Valid from".');
                }
                JS,
        ]);
        $this->params($listForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['customer_category_id', 'For customer category', 'integer', $this->lookup('crm_customer_categories_grid')],
            ['currency', 'Currency', 'string', ['is_required' => true] + $this->options(['MNT', 'USD', 'CNY', 'EUR'], 'MNT')],
            ['valid_from', 'Valid from', 'date', ['default_value' => '{today}']],
            ['valid_to', 'Valid to', 'date'],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['lines', 'Prices', 'detail', ['ref_meta_id' => $lineForm->id, 'json_config' => ['foreign_key' => 'price_list_id']]],
        ]);
        $listGrid = $this->meta('crm_price_lists_grid', 'Price lists', 'datagrid', 'crm_price_lists', 'Price lists by period and customer category.');
        $this->actions($listGrid, $listForm);
        $this->params($listGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_category_id', 'Customer category', 'integer', ['is_criteria' => true] + $this->lookup('crm_customer_categories_grid')],
            ['currency', 'Currency', 'string'],
            ['valid_from', 'Valid from', 'date'],
            ['valid_to', 'Valid to', 'date'],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        // Every price valid today, one row per price list line: what sales lines pick from.
        $finder = $this->meta('crm_price_lookup', 'Price finder', 'datagrid', 'crm_price_list_lines', 'Every price valid today on an active price list; quotation, order and invoice lines pick from it.', null, null, <<<'SQL'
            SELECT l.id, l.price_list_id, l.item_id, l.service_id,
                   COALESCE(i.name, s.name) AS item_name,
                   COALESCE(i.unit, s.unit) AS unit,
                   l.price, l.min_qty, p.name AS price_list, p.currency, p.customer_category_id,
                   p.valid_from, p.valid_to,
                   CONCAT(COALESCE(i.name, s.name), ' · ', p.name, ' · ', FORMAT(l.price, 0), ' ', p.currency) AS label
            FROM crm_price_list_lines l
            JOIN crm_price_lists p ON p.id = l.price_list_id
            LEFT JOIN proc_items i ON i.id = l.item_id
            LEFT JOIN crm_services s ON s.id = l.service_id
            WHERE p.is_active = 1
              AND (p.valid_from IS NULL OR p.valid_from <= :today)
              AND (p.valid_to IS NULL OR p.valid_to >= :today)
            SQL);
        $this->params($finder, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Price', 'string', ['is_show' => false, 'is_label' => true]],
            ['item_name', 'Item / service', 'string', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['price', 'Price', 'decimal', ['render_type' => 'money']],
            ['min_qty', 'From qty', 'decimal'],
            ['price_list', 'Price list', 'string', ['is_criteria' => true]],
            ['customer_category_id', 'Customer category', 'integer', ['is_criteria' => true] + $this->lookup('crm_customer_categories_grid')],
            ['currency', 'Currency', 'string'],
            ['valid_from', 'Valid from', 'date'],
            ['valid_to', 'Valid to', 'date'],
            ['item_id', 'Item', 'integer', ['is_show' => false]],
            ['service_id', 'Service', 'integer', ['is_show' => false]],
            ['price_list_id', 'Price list id', 'integer', ['is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- workflows

    private function workflows(): void
    {
        $resolve = $this->meta('crm_complaint_resolve_form', 'Complaint resolution', 'form', 'crm_complaints', 'How a complaint was resolved; asked for when it moves to Resolved.');
        $this->params($resolve, [
            ['id', 'ID', 'integer', $this->key()],
            ['resolution', 'How was it resolved?', 'text', ['is_required' => true, 'render_type' => 'textarea']],
            ['resolved_on', 'Resolved on', 'date', ['is_required' => true, 'default_value' => '{today}']],
        ]);

        $this->workflow('crm_lead_workflow', 'Lead workflow', 'crm_leads', 'New → contacted → qualified → converted, or lost. Converting is done by the Convert button (a process flow).', [
            ['new', 'New', 'blue', null, [130, 130]],
            ['contacted', 'Contacted', 'amber', null, [330, 130]],
            ['qualified', 'Qualified', 'purple', null, [530, 130]],
            ['converted', 'Converted', 'green', null, [730, 130]],
            ['lost', 'Lost', 'red', null, [430, 320]],
        ], [
            [null, 'new', null, true], ['new', 'contacted', null, true], ['contacted', 'qualified', null, true],
            // Drawn only: the Convert button's process flow moves a lead here.
            ['qualified', 'converted', null, false],
            ['new', 'lost', null, true], ['contacted', 'lost', null, true], ['qualified', 'lost', null, true], ['lost', 'new', null, true],
        ]);

        $this->workflow('crm_quotation_workflow', 'Quotation workflow', 'crm_quotations', 'Draft → sent → accepted or rejected; a rejected one can be reworked.', [
            ['draft', 'Draft', 'gray', null, [130, 130]],
            ['sent', 'Sent', 'blue', null, [330, 130]],
            ['accepted', 'Accepted', 'green', null, [530, 60]],
            ['rejected', 'Rejected', 'red', null, [530, 260]],
        ], [
            [null, 'draft', null, true], ['draft', 'sent', 'totalAmount > 0', true], ['sent', 'accepted', null, true],
            ['sent', 'rejected', null, true], ['rejected', 'draft', null, true],
        ]);

        $this->workflow('crm_complaint_workflow', 'Complaint workflow', 'crm_complaints', 'Open → in progress → resolved → closed; a resolved complaint can be reopened.', [
            ['open', 'Open', 'red', null, [130, 130]],
            ['in_progress', 'In progress', 'amber', null, [330, 130]],
            ['resolved', 'Resolved', 'green', $resolve->id, [530, 130]],
            ['closed', 'Closed', 'gray', null, [730, 130]],
        ], [
            [null, 'open', null, true], ['open', 'in_progress', null, true], ['in_progress', 'resolved', null, true],
            ['resolved', 'closed', null, true], ['resolved', 'in_progress', null, true], ['open', 'closed', null, true],
        ]);
    }

    /**
     * A workflow meta, its statuses (kept by code, so their ids stay) and its transitions (rebuilt).
     * Statuses: [code, name, color, form meta id, [x, y]]; transitions: [from, to, criteria, is_transition].
     */
    private function workflow(string $code, string $name, string $table, string $description, array $statuses, array $transitions): void
    {
        $workflow = $this->meta($code, $name, 'workflow', $table, $description);
        $ids = [];
        $layout = ['start' => ['x' => 30, 'y' => 150], 'statuses' => []];
        foreach ($statuses as [$statusCode, $statusName, $color, $form, [$x, $y]]) {
            $ids[$statusCode] = WfmStatus::updateOrCreate(
                ['workflow_meta_id' => $workflow->id, 'code' => $statusCode],
                ['name' => $statusName, 'color' => $color, 'meta_id' => $form],
            )->id;
            $layout['statuses'][$ids[$statusCode]] = ['x' => $x, 'y' => $y];
        }
        WfmStatus::where('workflow_meta_id', $workflow->id)->whereNotIn('code', array_keys($ids))->delete();
        WfmTransition::where('workflow_meta_id', $workflow->id)->delete();
        $path = [];
        foreach ($transitions as [$from, $to, $criteria, $live]) {
            $t = WfmTransition::create([
                'workflow_meta_id' => $workflow->id,
                'prev_status_id' => $from ? $ids[$from] : null,
                'next_status_id' => $ids[$to],
                'criteria' => $criteria,
                'is_transition' => $live,
            ]);
            if (count($path) === 0 || $path[count($path) - 1]['to'] === $from) {
                $path[] = ['t' => $t, 'to' => $to];
            }
        }
        foreach ($path as $i => $step) {
            $step['t']->update(['prev_id' => $path[$i - 1]['t']->id ?? null, 'next_id' => $path[$i + 1]['t']->id ?? null]);
        }
        $workflow->update(['json_config' => ['layout' => $layout]]);
        $this->status[$code] = $ids;
    }

    // ---------------------------------------------------------------- customers

    private function customers(): void
    {
        // The registry grid first, so lookups can point at it; its columns come in workspace().
        $this->meta('crm_customers_grid', 'Customer registry', 'datagrid', 'crm_customers', 'Every customer: companies and people, with category, owner and status.');

        $contactForm = $this->meta('crm_contact_form', 'Contact form', 'form', 'crm_contacts', 'A person at a customer.');
        $this->params($contactForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['position', 'Position', 'string'],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['is_primary', 'Main contact', 'boolean', ['default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $addressForm = $this->meta('crm_address_form', 'Address form', 'form', 'crm_addresses', 'An address of a customer: office, billing, shipping or home.');
        $this->params($addressForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(['office', 'billing', 'shipping', 'home'], 'office')],
            ['line1', 'Address', 'string', ['is_required' => true, 'is_label' => true]],
            ['district', 'District', 'string'],
            ['city', 'City', 'string', ['default_value' => 'Ulaanbaatar']],
            ['country', 'Country', 'string', ['default_value' => 'Mongolia']],
            ['postal_code', 'Postal code', 'string'],
        ]);

        $makeSupplier = $this->stageQuery('crm_make_supplier', 'Make supplier', 'crm_customers', <<<'SQL'
            INSERT INTO proc_suppliers (name, supplier_type_id, contact_name, email, phone, country, is_active, created_at, updated_at)
            SELECT c.name,
                   (SELECT t.id FROM proc_supplier_types t WHERE t.code = 'local'),
                   (SELECT p.name FROM crm_contacts p WHERE p.customer_id = c.id ORDER BY p.is_primary DESC, p.id LIMIT 1),
                   c.email, c.phone,
                   COALESCE((SELECT a.country FROM crm_addresses a WHERE a.customer_id = c.id ORDER BY a.id LIMIT 1), 'Mongolia'),
                   1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_customers c
            WHERE c.id = :customer_id AND c.supplier_id IS NULL;
            UPDATE crm_customers
            SET is_supplier = 1, supplier_id = LAST_INSERT_ID(), updated_at = CURRENT_TIMESTAMP
            WHERE id = :customer_id AND supplier_id IS NULL
            SQL, 'Adds the customer to Procurement’s suppliers (once) and links them, so purchase orders can be placed with it.', [
            ['customer_id', 'Customer', 'integer', ['is_required' => true]],
        ]);

        $form = $this->meta('crm_customer_form', 'Customer form', 'form', 'crm_customers', 'A customer: company or person, with contacts, addresses and files. Every change is logged.', null, null, null, ['is_log' => true]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Customer code', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true, 'json_config' => ['autonumber' => ['format' => 'CUS-{seq:4}']]]],
            ['kind', 'Company or person', 'string', ['is_required' => true] + $this->options(['company', 'person'], 'company')],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['register_no', 'Register no', 'string', ['is_unique' => true]],
            ['category_id', 'Category', 'integer', $this->lookup('crm_customer_categories_grid')],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['prospect', 'active', 'inactive', 'blocked'], 'active')],
            ['owner_id', 'Owner (sales rep)', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['website', 'Website', 'string'],
            ['industry', 'Industry', 'string'],
            ['is_supplier', 'Also a supplier', 'boolean', ['default_value' => '0']],
            ['credit_limit', 'Credit limit', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['payment_terms_days', 'Payment terms (days)', 'integer', ['min_value' => '0', 'default_value' => '30']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['contacts', 'Contacts', 'detail', ['ref_meta_id' => $contactForm->id, 'json_config' => ['foreign_key' => 'customer_id']]],
            ['addresses', 'Addresses', 'detail', ['ref_meta_id' => $addressForm->id, 'json_config' => ['foreign_key' => 'customer_id']]],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);
        // A new customer marked as a supplier is added to Procurement's suppliers too.
        $this->flow($form, [
            'supplier' => ['process' => $makeSupplier, 'criteria' => [['field' => 'is_supplier', 'op' => '=', 'value' => '1']], 'map' => ['customer_id' => 'id']],
        ]);

        $contacts = $this->meta('crm_contacts_grid', 'Contacts', 'datagrid', 'crm_contacts', 'People at our customers.');
        $this->actions($contacts, $contactForm);
        $this->params($contacts, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['position', 'Position', 'string'],
            ['email', 'Email', 'string', ['is_criteria' => true]],
            ['phone', 'Phone', 'string'],
            ['is_primary', 'Main', 'boolean'],
        ]);
        $addresses = $this->meta('crm_addresses_grid', 'Addresses', 'datagrid', 'crm_addresses', 'Customer addresses.');
        $this->actions($addresses, $addressForm);
        $this->params($addresses, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['line1', 'Address', 'string', ['is_label' => true]],
            ['district', 'District', 'string'],
            ['city', 'City', 'string', ['is_criteria' => true]],
            ['country', 'Country', 'string'],
        ]);
    }

    // ---------------------------------------------------------------- leads

    private function leads(): void
    {
        $form = $this->meta('crm_lead_form', 'Lead form', 'form', 'crm_leads', 'Someone who may buy: where they came from and who follows up.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['lead_no', 'Lead no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'LD-{yy}{MM}{seq:3}']]]],
            ['contact_name', 'Contact person', 'string', ['is_required' => true, 'is_label' => true]],
            ['company_name', 'Company', 'string'],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['source', 'Source', 'string', ['is_required' => true] + $this->options(self::SOURCES, 'website')],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['estimated_value', 'Estimated value', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $grid = $this->meta('crm_leads_grid', 'Leads', 'datagrid', 'crm_leads', 'Leads from new to converted or lost.', $this->m['crm_lead_workflow'], ['filter_layout' => 'sidebar']);

        // Convert: a conversion record whose process flow makes the customer and the opportunity.
        $get = $this->meta('crm_lead_conversion_get', 'Lead to convert', 'get', null, 'Starts a conversion from the picked lead.', null, null, <<<'SQL'
            SELECT l.id AS lead_id,
                   CASE WHEN COALESCE(l.company_name, '') = '' THEN 'person' ELSE 'company' END AS kind,
                   COALESCE(NULLIF(l.company_name, ''), l.contact_name) AS company_name,
                   l.contact_name, l.email, l.phone, l.owner_id,
                   CONCAT(COALESCE(NULLIF(l.company_name, ''), l.contact_name), ' – first deal') AS opportunity_name,
                   l.estimated_value AS amount,
                   DATE_ADD(:today, INTERVAL 30 DAY) AS expected_close,
                   :today AS converted_on
            FROM crm_leads l
            WHERE l.id = :id
            SQL);
        $this->params($get, [['id', 'Lead', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $conversion = $this->meta('crm_lead_conversion_form', 'Convert lead', 'form', 'crm_lead_conversions', 'Turns a lead into a customer and an opportunity (process flow), and marks it converted.', null, ['save_and_add' => false], null, [
            'load_expression_string' => "readonly('lead_id');",
        ]);
        $this->params($conversion, [
            ['id', 'ID', 'integer', $this->key()],
            ['lead_id', 'Lead', 'integer', ['is_required' => true, 'is_unique' => true] + $this->lookup('crm_leads_grid', 'contact_name')],
            ['kind', 'Company or person', 'string', ['is_required' => true] + $this->options(['company', 'person'], 'company')],
            ['company_name', 'Customer name', 'string', ['is_required' => true, 'is_label' => true]],
            ['contact_name', 'Contact person', 'string'],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['category_id', 'Customer category', 'integer', $this->lookup('crm_customer_categories_grid')],
            ['owner_id', 'Owner', 'integer', $this->lookup('crm_users_lookup')],
            ['opportunity_name', 'Opportunity', 'string', ['is_required' => true]],
            ['amount', 'Expected amount', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['expected_close', 'Expected close', 'date'],
            ['converted_on', 'Converted on', 'date', ['is_required' => true, 'default_value' => '{today}']],
        ]);

        $this->actions($grid, $form);
        $this->button($grid, 'custom', 'Convert', 'check', $conversion, $get, 4, ['id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['lead_no', 'Lead', 'string', ['is_criteria' => true]],
            ['contact_name', 'Contact', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['company_name', 'Company', 'string', ['is_criteria' => true]],
            ['phone', 'Phone', 'string'],
            ['source', 'Source', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::SOURCES]]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['estimated_value', 'Estimated value', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['customer_id', 'Customer', 'integer', $this->lookup('crm_customers_grid')],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);

        $s = $this->status['crm_lead_workflow'];
        $tabs = $this->meta('crm_leads_tabs', 'Leads', 'tabs', null, 'Leads by where they stand, and your own.');
        $this->params($tabs, [
            ['all', 'All', 'string', ['ref_meta_id' => $grid->id]],
            ['new', 'New', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['new']]]]],
            ['contacted', 'Contacted', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['contacted']]]]],
            ['qualified', 'Qualified', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['qualified']]]]],
            ['converted', 'Converted', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['converted']]]]],
            ['lost', 'Lost', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['lost']]]]],
            ['mine', 'Mine', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['owner_id' => '{user_id}']]]],
        ]);
    }

    // ---------------------------------------------------------------- opportunities

    private function opportunities(): void
    {
        $form = $this->meta('crm_opportunity_form', 'Opportunity form', 'form', 'crm_opportunities', 'A deal in the pipeline: stage, amount, probability and close date.', null, null, null, [
            'event_expression_string' => <<<'JS'
                // Moving to a stage suggests its usual probability.
                if (field === 'stage') {
                  const usual = { prospecting: 10, qualification: 25, proposal: 50, negotiation: 75, won: 100, lost: 0 }[get('stage')];
                  if (usual !== undefined) set('probability', usual);
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['opp_no', 'Opportunity no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'OPP-{yy}{MM}{seq:3}']]]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['lead_id', 'Lead', 'integer', ['is_show' => false]],
            ['stage', 'Stage', 'string', ['is_required' => true] + $this->options(self::STAGES, 'prospecting')],
            ['amount', 'Expected amount', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['probability', 'Probability %', 'integer', ['min_value' => '0', 'max_value' => '100', 'default_value' => '10']],
            ['expected_close', 'Expected close', 'date'],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['next_step', 'Next step', 'string'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $grid = $this->meta('crm_opportunities_grid', 'Opportunities', 'datagrid', 'crm_opportunities', 'The sales pipeline.', null, ['filter_layout' => 'dropdown']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['opp_no', 'No', 'string', ['is_criteria' => true]],
            ['name', 'Opportunity', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['stage', 'Stage', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::STAGES]]],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['probability', 'Probability %', 'integer'],
            ['expected_close', 'Expected close', 'date'],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['next_step', 'Next step', 'string'],
        ]);

        $rows = [['all', 'All', 'string', ['ref_meta_id' => $grid->id]]];
        foreach (self::STAGES as $stage) {
            $rows[] = [$stage, ucfirst($stage), 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['stage' => $stage], 'defaults' => ['stage' => $stage]]]];
        }
        $rows[] = ['mine', 'Mine', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['owner_id' => '{user_id}']]]];
        $tabs = $this->meta('crm_opportunity_tabs', 'Opportunities', 'tabs', null, 'The pipeline, one tab per stage.');
        $this->params($tabs, $rows);
    }

    // ---------------------------------------------------------------- activities and follow-ups

    private function activities(): void
    {
        $form = $this->meta('crm_activity_form', 'Activity form', 'form', 'crm_activities', 'A call, meeting, email or note with a customer or a lead.', null, null, null, [
            'before_save_expression_string' => <<<'JS'
                if (!get('customer_id') && !get('lead_id')) {
                  error('Pick a customer or a lead.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(['call', 'meeting', 'email', 'note'], 'call')],
            ['subject', 'Subject', 'string', ['is_required' => true, 'is_label' => true]],
            ['activity_at', 'When', 'datetime', ['is_required' => true]],
            ['customer_id', 'Customer', 'integer', $this->lookup('crm_customers_grid')],
            ['lead_id', 'Lead', 'integer', $this->lookup('crm_leads_grid', 'contact_name')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['duration_min', 'Minutes', 'integer', ['min_value' => '0']],
            ['outcome', 'Outcome', 'string'],
            ['owner_id', 'By', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['details', 'Details', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('crm_activities_grid', 'Activities', 'datagrid', 'crm_activities', 'Customer history: calls, meetings, emails and notes.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['activity_at', 'When', 'datetime'],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['call', 'meeting', 'email', 'note']]]],
            ['subject', 'Subject', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['lead_id', 'Lead', 'integer', $this->lookup('crm_leads_grid', 'contact_name')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['owner_id', 'By', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['duration_min', 'Minutes', 'integer'],
            ['outcome', 'Outcome', 'string'],
        ]);

        $taskForm = $this->meta('crm_task_form', 'Follow-up form', 'form', 'crm_tasks', 'Something to do for a customer by a date.');
        $this->params($taskForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['title', 'What to do', 'string', ['is_required' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', $this->lookup('crm_customers_grid')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['due_on', 'Due', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['owner_id', 'Who', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['priority', 'Priority', 'string', ['is_required' => true] + $this->options(['low', 'normal', 'high'], 'normal')],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['open', 'done', 'cancelled'], 'open')],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $done = $this->stageQuery('crm_task_done', 'Mark follow-up done', 'crm_tasks', <<<'SQL'
            UPDATE crm_tasks SET status = 'done', updated_at = CURRENT_TIMESTAMP WHERE id = :task_id AND status = 'open'
            SQL, 'Marks an open follow-up done.', [
            ['task_id', 'Follow-up', 'integer', ['is_required' => true]],
        ]);
        $taskColumns = [
            ['id', 'ID', 'integer', $this->key()],
            ['due_on', 'Due', 'date'],
            ['title', 'What to do', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['owner_id', 'Who', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['priority', 'Priority', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['low', 'normal', 'high']]]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['open', 'done', 'cancelled']]]],
        ];
        $tasks = $this->meta('crm_tasks_grid', 'Follow-ups', 'datagrid', 'crm_tasks', 'Tasks and follow-ups with due dates.');
        $this->actions($tasks, $taskForm);
        $this->button($tasks, 'stage_query', 'Done', 'check', $done, null, 4, ['task_id' => 'id']);
        $this->params($tasks, $taskColumns);

        $calendar = $this->meta('crm_tasks_calendar', 'Follow-up calendar', 'calendar', 'crm_tasks', 'Follow-ups by due date, coloured by priority.', $taskForm, [
            'start_field' => 'due_on',
            'title_field' => 'title',
            'item_label' => 'follow-up',
            'color_field' => 'priority',
            'colors' => ['high' => 'red', 'normal' => 'blue', 'low' => 'gray'],
        ]);
        $this->params($calendar, [
            ['id', 'ID', 'integer', $this->key()],
            ['title', 'What to do', 'string', ['is_criteria' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['owner_id', 'Who', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['priority', 'Priority', 'string'],
            ['status', 'Status', 'string', ['is_criteria' => true]],
            ['due_on', 'Due', 'date'],
        ]);
    }

    // ---------------------------------------------------------------- proposals and quotations

    /** The scripts of a document with sales lines: line totals after discount and the document total. */
    private function lineScripts(string $source, string $emptyMessage): array
    {
        $check = $source === ''
            ? "if (docTotal() <= 0) {\n  error('{$emptyMessage}');\n}"
            : "if (docTotal() <= 0 && !get('{$source}')) {\n  error('{$emptyMessage}');\n}";

        return [
            'var_fnc_expression_string' => <<<'JS'
                // Shared by the scripts below: a line's total after its discount, and the document's total.
                function lineTotal(l) {
                  return round(num(l.quantity) * num(l.unit_price) * (1 - num(l.discount_pct) / 100), 2);
                }
                function docTotal() {
                  return round(sum('lines', (l) => lineTotal(l)), 2);
                }
                function refreshLines() {
                  lines('lines').forEach((l, i) => setLine('lines', i, 'line_total', lineTotal(l)));
                  set('total_amount', docTotal());
                }
                JS,
            'load_expression_string' => "readonly('total_amount');\nrefreshLines();",
            'event_expression_string' => <<<'JS'
                // A line's item, quantity, price or discount changed, or a line was added or removed.
                if (detail === 'lines') {
                  refreshLines();
                }
                JS,
            'before_save_expression_string' => $check,
            'after_save_expression_string' => null,
        ];
    }

    /** A sales line form: pick a price from a price list, or an item or service; the total is worked out. */
    private function lineForm(string $code, string $name, string $table, string $foreignKey): Meta
    {
        $form = $this->meta($code, $name, 'form', $table, 'One line: an item or a service, quantity, price and discount.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            [$foreignKey, 'Document', 'integer', ['is_show' => false]],
            ['price_line_id', 'From price list', 'integer', $this->lookup('crm_price_lookup', 'label', ['json_config' => ['fill' => [
                'item_id' => 'item_id', 'service_id' => 'service_id', 'description' => 'item_name', 'unit_price' => 'price',
            ]]])],
            ['item_id', 'Item', 'integer', $this->lookup('crm_items_grid', 'name', ['json_config' => ['fill' => ['unit_price' => 'unit_price', 'description' => 'name']]])],
            ['service_id', 'Service', 'integer', $this->lookup('crm_services_grid', 'name', ['json_config' => ['fill' => ['unit_price' => 'rate', 'description' => 'name']]])],
            ['description', 'Description', 'string'],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['discount_pct', 'Discount %', 'decimal', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['line_total', 'Line total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
        ]);

        return $form;
    }

    /** The columns a printed document shows for its lines. */
    private function printLineForm(string $code, string $name, string $table, string $foreignKey): Meta
    {
        $form = $this->meta($code, $name, 'form', $table, 'The columns a printed document shows for its lines.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            [$foreignKey, 'Document', 'integer', ['is_show' => false]],
            ['description', 'Description', 'string'],
            ['quantity', 'Qty', 'decimal'],
            ['unit_price', 'Unit price', 'decimal', ['render_type' => 'money']],
            ['discount_pct', 'Discount %', 'decimal'],
            ['line_total', 'Amount', 'decimal', ['render_type' => 'money']],
        ]);

        return $form;
    }

    private function quotations(): void
    {
        $accepted = $this->status['crm_quotation_workflow']['accepted'];
        $lineForm = $this->lineForm('crm_quotation_line_form', 'Quotation line', 'crm_quotation_lines', 'quotation_id');
        $printLines = $this->printLineForm('crm_quotation_line_print', 'Quotation line (print)', 'crm_quotation_lines', 'quotation_id');

        $report = $this->meta('crm_quotation_report', 'Quotation / proposal', 'report', 'crm_quotations', 'A quotation or proposal to print or send as a PDF.', null, [
            'title' => 'Quotation',
            'number_field' => 'quote_no',
            'issuer' => 'Blue Sky LLC · Sales',
            'note' => 'Prices are in MNT and include VAT unless stated otherwise.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['quote_no', 'Number', 'string'],
            ['doc_type', 'Type', 'string'],
            ['title', 'Subject', 'string'],
            ['customer_id', 'Customer', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['crm_customers_grid']->id, 'display_field' => 'name']],
            ['contact_name', 'Attention', 'string'],
            ['issue_date', 'Date', 'date'],
            ['valid_until', 'Valid until', 'date'],
            ['intro', 'Proposal', 'text'],
            ['lines', 'Items and services', 'detail', ['ref_meta_id' => $printLines->id, 'json_config' => ['foreign_key' => 'quotation_id', 'amount' => ['line_total']]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['terms', 'Terms', 'text'],
            ['prepared_by', 'Prepared by', 'signature'],
            ['accepted_by', 'Accepted by (customer)', 'signature'],
        ]);

        $form = $this->meta('crm_quotation_form', 'Quotation form', 'form', 'crm_quotations', 'A proposal or quotation with lines; prices come from price lists.', null, ['print_meta_id' => $report->id], null,
            $this->lineScripts('', 'Add at least one line with a quantity and a price.'));
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['quote_no', 'Number', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => [
                'format' => 'QT-{yy}{MM}{seq:4}',
                'rules' => [['field' => 'doc_type', 'value' => 'proposal', 'format' => 'PRP-{yy}{MM}{seq:4}']],
            ]]]],
            ['doc_type', 'Type', 'string', ['is_required' => true] + $this->options(['quotation', 'proposal'], 'quotation')],
            ['title', 'Subject', 'string', ['is_required' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['contact_name', 'Attention', 'string'],
            ['issue_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['valid_until', 'Valid until', 'date'],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['intro', 'Proposal text', 'text', ['render_type' => 'textarea']],
            ['lines', 'Lines', 'detail', ['ref_meta_id' => $lineForm->id, 'json_config' => [
                'foreign_key' => 'quotation_id', 'amount' => ['line_total'], 'total_field' => 'total_amount',
            ]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['terms', 'Terms', 'text', ['render_type' => 'textarea', 'default_value' => 'Valid for 30 days. Payment within 30 days of the invoice.']],
            ['notes', 'Internal notes', 'text', ['render_type' => 'textarea']],
        ]);

        $newQuote = $this->meta('crm_quote_new_get', 'New quotation values', 'get', null, 'A new quotation is dated today and valid for 30 days.', null, null,
            'SELECT :today AS issue_date, DATE_ADD(:today, INTERVAL 30 DAY) AS valid_until');
        $this->params($newQuote, []);
        $fromOpportunity = $this->meta('crm_quote_from_opportunity_get', 'Quotation from opportunity', 'get', null, 'A quotation for the picked opportunity.', null, null, <<<'SQL'
            SELECT o.customer_id, o.id AS opportunity_id, o.name AS title, 'quotation' AS doc_type, o.owner_id,
                   :today AS issue_date, DATE_ADD(:today, INTERVAL 30 DAY) AS valid_until
            FROM crm_opportunities o
            WHERE o.id = :id
            SQL);
        $this->params($fromOpportunity, [['id', 'Opportunity', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);
        // The opportunities grid's buttons: its form, and a quotation for the picked one.
        $opportunities = $this->m['crm_opportunities_grid'];
        $this->actions($opportunities, $this->m['crm_opportunity_form']);
        $this->button($opportunities, 'custom', 'New quotation', 'file', $form, $fromOpportunity, 4, ['id' => 'id']);

        // Accepted quotations, for a sales order to come from.
        $acceptedLookup = $this->meta('crm_quotes_accepted_lookup', 'Accepted quotations', 'datagrid', 'crm_quotations', 'Quotations the customer accepted, to make an order from.', null, null, $this->sql(<<<'SQL'
            SELECT q.id, q.quote_no, q.customer_id, q.owner_id, q.total_amount, q.sales_order_id,
                   CONCAT(q.quote_no, ' · ', c.name, ' · ', FORMAT(q.total_amount, 0)) AS label
            FROM crm_quotations q
            JOIN crm_customers c ON c.id = q.customer_id
            WHERE q.status_id = {accepted}
            SQL, ['{accepted}' => $accepted]));
        $this->params($acceptedLookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Quotation', 'string', ['is_label' => true, 'is_show' => false]],
            ['quote_no', 'Number', 'string', ['is_criteria' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['owner_id', 'Owner', 'integer', $this->lookup('crm_users_lookup')],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money']],
            ['sales_order_id', 'Sales order', 'integer'],
        ]);

        // The grid is set up in salesAndInvoices(), once the sales order form exists.
        $this->meta('crm_quotations_grid', 'Proposals & quotations', 'datagrid', 'crm_quotations', 'Proposals and quotations, from draft to accepted or rejected.', $this->m['crm_quotation_workflow'], [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print'],
        ]);
        unset($newQuote);
    }

    // ---------------------------------------------------------------- sales orders and invoices

    private function salesAndInvoices(): void
    {
        $accepted = $this->status['crm_quotation_workflow']['accepted'];

        // Sales orders.
        $orderLines = $this->lineForm('crm_sales_order_line_form', 'Sales order line', 'crm_sales_order_lines', 'sales_order_id');
        $orderForm = $this->meta('crm_sales_order_form', 'Sales order form', 'form', 'crm_sales_orders', 'An order from a customer; made from an accepted quotation, its lines are copied.', null, null, null,
            $this->lineScripts('quotation_id', 'Add at least one line, or pick an accepted quotation to copy.'));
        $this->params($orderForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['so_number', 'Order no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'SO-{yy}{MM}{seq:4}']]]],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['quotation_id', 'From quotation', 'integer', ['is_unique' => true] + $this->lookup('crm_quotes_accepted_lookup', 'label', ['json_config' => ['fill' => ['customer_id' => 'customer_id', 'owner_id' => 'owner_id']]])],
            ['order_date', 'Order date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['delivery_date', 'Delivery date', 'date'],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('crm_users_lookup')],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['open', 'confirmed', 'delivered', 'invoiced', 'cancelled'], 'open')],
            ['lines', 'Lines', 'detail', ['ref_meta_id' => $orderLines->id, 'json_config' => [
                'foreign_key' => 'sales_order_id', 'amount' => ['line_total'], 'total_field' => 'total_amount',
            ]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $copyQuote = $this->stageQuery('crm_copy_quotation_lines', 'Copy quotation to order', 'crm_sales_order_lines', <<<'SQL'
            INSERT INTO crm_sales_order_lines (sales_order_id, price_line_id, item_id, service_id, description, quantity, unit_price, discount_pct, line_total, created_at, updated_at)
            SELECT :sales_order_id, l.price_line_id, l.item_id, l.service_id, l.description, l.quantity, l.unit_price, l.discount_pct, l.line_total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
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
            SQL, 'Copies an accepted quotation’s lines to its new sales order (when the order has none), links them and marks the opportunity won.', [
            ['sales_order_id', 'Sales order', 'integer', ['is_required' => true]],
            ['quotation_id', 'Quotation', 'integer', ['is_required' => true]],
        ]);
        $this->flow($orderForm, [
            'copy' => ['process' => $copyQuote, 'criteria' => [['field' => 'quotation_id', 'op' => 'not_empty']], 'map' => ['sales_order_id' => 'id', 'quotation_id' => 'quotation_id']],
        ]);
        $newOrder = $this->meta('crm_so_new_get', 'New order values', 'get', null, 'A new order is dated today.', null, null, 'SELECT :today AS order_date');
        $this->params($newOrder, []);
        $fromQuote = $this->meta('crm_so_from_quotation_get', 'Order from quotation', 'get', null, 'An order for the picked quotation, when it is accepted.', null, null, $this->sql(<<<'SQL'
            SELECT q.id AS quotation_id, q.customer_id, q.owner_id, :today AS order_date,
                   DATE_ADD(:today, INTERVAL 14 DAY) AS delivery_date, 'open' AS status,
                   CONCAT('From ', q.quote_no, ': ', q.title) AS notes
            FROM crm_quotations q
            WHERE q.id = :id AND q.status_id = {accepted}
            SQL, ['{accepted}' => $accepted]));
        $this->params($fromQuote, [['id', 'Quotation', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $quoteGrid = $this->m['crm_quotations_grid'];
        $this->actions($quoteGrid, $this->m['crm_quotation_form'], null, $this->m['crm_quote_new_get']);
        $this->button($quoteGrid, 'custom', 'Make sales order', 'file', $orderForm, $fromQuote, 4, ['id' => 'id']);

        $orderGrid = $this->meta('crm_sales_orders_grid', 'Sales orders', 'datagrid', 'crm_sales_orders', 'Orders from customers.');

        $this->params($quoteGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['quote_no', 'Number', 'string', ['is_criteria' => true]],
            ['doc_type', 'Type', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['quotation', 'proposal']]]],
            ['title', 'Subject', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['opportunity_id', 'Opportunity', 'integer', $this->lookup('crm_opportunities_grid')],
            ['issue_date', 'Date', 'date'],
            ['valid_until', 'Valid until', 'date'],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['sales_order_id', 'Sales order', 'integer', $this->lookup('crm_sales_orders_grid', 'so_number')],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);

        // Invoices.
        $invoiceLines = $this->lineForm('crm_invoice_line_form', 'Invoice line', 'crm_invoice_lines', 'invoice_id');
        $printLines = $this->printLineForm('crm_invoice_line_print', 'Invoice line (print)', 'crm_invoice_lines', 'invoice_id');
        $report = $this->meta('crm_invoice_report', 'Invoice', 'report', 'crm_invoices', 'A sales invoice to print or send as a PDF.', null, [
            'title' => 'Invoice',
            'number_field' => 'invoice_no',
            'issuer' => 'Blue Sky LLC · Accounts',
            'note' => 'Please pay by the due date to Khan Bank 5012 3456 78, quoting the invoice number.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['invoice_no', 'Invoice no', 'string'],
            ['customer_id', 'Bill to', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['crm_customers_grid']->id, 'display_field' => 'name']],
            ['invoice_date', 'Date', 'date'],
            ['due_date', 'Due date', 'date'],
            ['notes', 'Notes', 'text'],
            ['lines', 'Items and services', 'detail', ['ref_meta_id' => $printLines->id, 'json_config' => ['foreign_key' => 'invoice_id', 'amount' => ['line_total']]]],
            ['total_amount', 'Total due', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['issued_by', 'Issued by', 'signature'],
            ['received_by', 'Received by', 'signature'],
        ]);
        $invoiceForm = $this->meta('crm_invoice_form', 'Invoice form', 'form', 'crm_invoices', 'A sales invoice; made from an order, its lines are copied.', null, ['print_meta_id' => $report->id], null,
            $this->lineScripts('sales_order_id', 'Add at least one line, or pick the order to invoice.'));
        $this->params($invoiceForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['invoice_no', 'Invoice no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'INV-{yy}{MM}{seq:4}']]]],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['sales_order_id', 'For order', 'integer', ['is_unique' => true] + $this->lookup('crm_sales_orders_grid', 'so_number', ['json_config' => ['fill' => ['customer_id' => 'customer_id']]])],
            ['invoice_date', 'Invoice date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['due_date', 'Due date', 'date', ['is_required' => true]],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['draft', 'issued', 'cancelled'], 'issued')],
            ['lines', 'Lines', 'detail', ['ref_meta_id' => $invoiceLines->id, 'json_config' => [
                'foreign_key' => 'invoice_id', 'amount' => ['line_total'], 'total_field' => 'total_amount',
            ]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $copyOrder = $this->stageQuery('crm_copy_order_lines', 'Copy order to invoice', 'crm_invoice_lines', <<<'SQL'
            INSERT INTO crm_invoice_lines (invoice_id, price_line_id, item_id, service_id, description, quantity, unit_price, discount_pct, line_total, created_at, updated_at)
            SELECT :invoice_id, l.price_line_id, l.item_id, l.service_id, l.description, l.quantity, l.unit_price, l.discount_pct, l.line_total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM crm_sales_order_lines l
            WHERE l.sales_order_id = :sales_order_id
              AND NOT EXISTS (SELECT 1 FROM crm_invoice_lines x WHERE x.invoice_id = :invoice_id)
            ORDER BY l.id;
            UPDATE crm_invoices
            SET total_amount = (SELECT COALESCE(SUM(x.line_total), 0) FROM crm_invoice_lines x WHERE x.invoice_id = :invoice_id),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :invoice_id;
            UPDATE crm_sales_orders SET status = 'invoiced', updated_at = CURRENT_TIMESTAMP WHERE id = :sales_order_id AND status <> 'cancelled'
            SQL, 'Copies a sales order’s lines to its new invoice (when the invoice has none) and marks the order invoiced.', [
            ['invoice_id', 'Invoice', 'integer', ['is_required' => true]],
            ['sales_order_id', 'Sales order', 'integer', ['is_required' => true]],
        ]);
        $this->flow($invoiceForm, [
            'copy' => ['process' => $copyOrder, 'criteria' => [['field' => 'sales_order_id', 'op' => 'not_empty']], 'map' => ['invoice_id' => 'id', 'sales_order_id' => 'sales_order_id']],
        ]);
        $newInvoice = $this->meta('crm_invoice_new_get', 'New invoice values', 'get', null, 'A new invoice is dated today and due in 30 days.', null, null,
            "SELECT :today AS invoice_date, DATE_ADD(:today, INTERVAL 30 DAY) AS due_date, 'issued' AS status");
        $this->params($newInvoice, []);
        $fromOrder = $this->meta('crm_invoice_from_order_get', 'Invoice from order', 'get', null, 'An invoice for the picked order, due by the customer’s payment terms.', null, null, <<<'SQL'
            SELECT o.id AS sales_order_id, o.customer_id, :today AS invoice_date,
                   DATE_ADD(:today, INTERVAL COALESCE(c.payment_terms_days, 30) DAY) AS due_date,
                   'issued' AS status, CONCAT('For order ', o.so_number) AS notes
            FROM crm_sales_orders o
            JOIN crm_customers c ON c.id = o.customer_id
            WHERE o.id = :id
            SQL);
        $this->params($fromOrder, [['id', 'Order', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $this->actions($orderGrid, $orderForm, null, $newOrder);
        $this->button($orderGrid, 'custom', 'Invoice it', 'file', $invoiceForm, $fromOrder, 4, ['id' => 'id']);
        $this->params($orderGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['so_number', 'Order no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['quotation_id', 'Quotation', 'integer', $this->lookup('crm_quotes_accepted_lookup', 'quote_no')],
            ['order_date', 'Ordered', 'date'],
            ['delivery_date', 'Delivery', 'date'],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['open', 'confirmed', 'delivered', 'invoiced', 'cancelled']]]],
        ]);

        $invoiceGrid = $this->meta('crm_invoices_grid', 'Invoices', 'datagrid', 'crm_invoices', 'Sales invoices issued to customers.', null, [
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print'],
        ]);
        $this->actions($invoiceGrid, $invoiceForm, null, $newInvoice);
        $this->params($invoiceGrid, [
            ['id', 'ID', 'integer', $this->key()],
            ['invoice_no', 'Invoice no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['sales_order_id', 'Order', 'integer', $this->lookup('crm_sales_orders_grid', 'so_number')],
            ['invoice_date', 'Date', 'date'],
            ['due_date', 'Due', 'date'],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['draft', 'issued', 'cancelled']]]],
        ]);
    }

    // ---------------------------------------------------------------- accounts receivable

    private function receivables(): void
    {
        $invoiceLookup = $this->meta('crm_invoices_lookup', 'Invoices (pick list)', 'datagrid', 'crm_invoices', 'Invoices with what is still due, to pick for a payment.', null, null, <<<'SQL'
            SELECT i.id, i.invoice_no, i.customer_id,
                   i.total_amount - COALESCE(p.paid, 0) AS balance,
                   CONCAT(i.invoice_no, ' · ', c.name, ' · due ', FORMAT(i.total_amount - COALESCE(p.paid, 0), 0)) AS label
            FROM crm_invoices i
            JOIN crm_customers c ON c.id = i.customer_id
            LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM crm_payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id) p ON p.invoice_id = i.id
            WHERE i.status <> 'cancelled'
            SQL);
        $this->params($invoiceLookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Invoice', 'string', ['is_label' => true, 'is_show' => false]],
            ['invoice_no', 'Invoice no', 'string', ['is_criteria' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['balance', 'Due', 'decimal', ['render_type' => 'money']],
        ]);

        $paymentForm = $this->meta('crm_payment_form', 'Payment form', 'form', 'crm_payments', 'Money received from a customer, for an invoice or on account.');
        $this->params($paymentForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['payment_no', 'Receipt no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'RC-{yy}{MM}{seq:4}']]]],
            ['invoice_id', 'For invoice', 'integer', $this->lookup('crm_invoices_lookup', 'label', ['json_config' => ['fill' => ['customer_id' => 'customer_id', 'amount' => 'balance']]])],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['paid_on', 'Paid on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['amount', 'Amount', 'decimal', ['is_required' => true, 'min_value' => '0.01', 'render_type' => 'money']],
            ['method', 'Method', 'string', ['is_required' => true] + $this->options(['bank transfer', 'cash', 'card', 'QPay'], 'bank transfer')],
            ['reference', 'Reference', 'string'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $fromInvoice = $this->meta('crm_payment_from_invoice_get', 'Payment for invoice', 'get', null, 'A payment of what is still due on the picked invoice.', null, null, <<<'SQL'
            SELECT i.id AS invoice_id, i.customer_id, :today AS paid_on, 'bank transfer' AS method,
                   i.total_amount - COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id), 0) AS amount
            FROM crm_invoices i
            WHERE i.id = :id
            SQL);
        $this->params($fromInvoice, [['id', 'Invoice', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);
        $this->button($this->m['crm_invoices_grid'], 'custom', 'Record payment', 'plus', $paymentForm, $fromInvoice, 4, ['id' => 'id']);

        $payments = $this->meta('crm_payments_grid', 'Payments register', 'datagrid', 'crm_payments', 'Every payment received, with the period in the filters.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT p.id, p.payment_no, p.paid_on, p.customer_id, p.invoice_id, p.amount, p.method, p.reference
            FROM crm_payments p
            WHERE (:from_date IS NULL OR p.paid_on >= :from_date)
              AND (:to_date IS NULL OR p.paid_on <= :to_date)
            SQL);
        $this->actions($payments, $paymentForm);
        $this->params($payments, [
            ['id', 'ID', 'integer', $this->key()],
            ['payment_no', 'Receipt no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['paid_on', 'Paid on', 'date'],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['invoice_id', 'Invoice', 'integer', $this->lookup('crm_invoices_lookup', 'invoice_no')],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['method', 'Method', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['bank transfer', 'cash', 'card', 'QPay']]]],
            ['reference', 'Reference', 'string'],
            ['from_date', 'Paid from', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'Paid to', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        // Invoices issued vs payments received, invoice by invoice, with their age.
        $buckets = ['not due', '1-30 days', '31-60 days', '61-90 days', '90+ days', 'paid'];
        $ar = $this->meta('crm_ar_invoices_grid', 'Receivables', 'datagrid', 'crm_invoices', 'Each invoice: issued, paid, still due and how overdue.', null, [
            'filter_layout' => 'sidebar',
            'drilldown' => ['meta_id' => $this->m['crm_invoice_report']->id, 'params' => ['id' => 'id'], 'label' => 'Print invoice'],
        ], <<<'SQL'
            SELECT i.id, i.invoice_no, i.customer_id, i.invoice_date, i.due_date, i.total_amount,
                   COALESCE(p.paid, 0) AS paid_amount,
                   i.total_amount - COALESCE(p.paid, 0) AS balance,
                   GREATEST(DATEDIFF(:today, i.due_date), 0) AS days_overdue,
                   CASE
                     WHEN i.total_amount - COALESCE(p.paid, 0) <= 0 THEN 'paid'
                     WHEN DATEDIFF(:today, i.due_date) <= 0 THEN 'not due'
                     WHEN DATEDIFF(:today, i.due_date) <= 30 THEN '1-30 days'
                     WHEN DATEDIFF(:today, i.due_date) <= 60 THEN '31-60 days'
                     WHEN DATEDIFF(:today, i.due_date) <= 90 THEN '61-90 days'
                     ELSE '90+ days'
                   END AS age_bucket,
                   CASE WHEN i.total_amount - COALESCE(p.paid, 0) > 0 THEN 1 ELSE 0 END AS is_open
            FROM crm_invoices i
            LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM crm_payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id) p ON p.invoice_id = i.id
            WHERE i.status <> 'cancelled'
            SQL);
        $this->button($ar, 'custom', 'Record payment', 'plus', $paymentForm, $fromInvoice, 1, ['id' => 'id']);
        $this->params($ar, [
            ['id', 'ID', 'integer', $this->key()],
            ['invoice_no', 'Invoice no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['invoice_date', 'Date', 'date'],
            ['due_date', 'Due', 'date'],
            ['total_amount', 'Invoiced', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['paid_amount', 'Paid', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['balance', 'Balance', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['days_overdue', 'Days overdue', 'integer'],
            ['age_bucket', 'Age', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $buckets]]],
            ['is_open', 'Still due', 'boolean', ['is_criteria' => true, 'is_show' => false]],
        ]);

        $balances = $this->meta('crm_customer_balances_grid', 'Customer balances', 'datagrid', 'crm_customers', 'What each customer was invoiced, has paid and still owes.', null, [
            'drilldown' => ['meta_id' => null, 'params' => ['id' => 'id'], 'label' => 'Open workspace'],
        ], <<<'SQL'
            SELECT c.id, c.code, c.name, c.owner_id, c.credit_limit,
                   COALESCE(i.invoiced, 0) AS invoiced,
                   COALESCE(p.received, 0) AS received,
                   COALESCE(i.invoiced, 0) - COALESCE(p.received, 0) AS balance,
                   i.last_invoice, p.last_payment
            FROM crm_customers c
            LEFT JOIN (SELECT customer_id, SUM(total_amount) AS invoiced, MAX(invoice_date) AS last_invoice
                       FROM crm_invoices WHERE status <> 'cancelled' GROUP BY customer_id) i ON i.customer_id = c.id
            LEFT JOIN (SELECT customer_id, SUM(amount) AS received, MAX(paid_on) AS last_payment
                       FROM crm_payments GROUP BY customer_id) p ON p.customer_id = c.id
            WHERE i.customer_id IS NOT NULL OR p.customer_id IS NOT NULL
            SQL);
        $this->params($balances, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Customer', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['invoiced', 'Invoiced', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['received', 'Received', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['balance', 'Balance', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['credit_limit', 'Credit limit', 'decimal', ['render_type' => 'money']],
            ['last_invoice', 'Last invoice', 'date'],
            ['last_payment', 'Last payment', 'date'],
        ]);

        $ageing = $this->meta('crm_ar_ageing', 'AR ageing', 'statement', 'crm_invoices', 'What customers still owe, by how long it is overdue: not due, 1-30, 31-60, 61-90 and 90+ days.', null, [
            'group_by' => ['customer_name'],
            'sort' => [['field' => 'due_date', 'dir' => 'asc']],
            'drilldown' => ['meta_id' => $this->m['crm_invoice_report']->id, 'params' => ['id' => 'id'], 'label' => 'Print invoice'],
        ], <<<'SQL'
            SELECT a.id, c.name AS customer_name, a.invoice_no, a.invoice_date, a.due_date,
                   GREATEST(a.days_overdue, 0) AS days_overdue, a.balance,
                   CASE WHEN a.days_overdue <= 0 THEN a.balance ELSE 0 END AS not_due,
                   CASE WHEN a.days_overdue BETWEEN 1 AND 30 THEN a.balance ELSE 0 END AS d1_30,
                   CASE WHEN a.days_overdue BETWEEN 31 AND 60 THEN a.balance ELSE 0 END AS d31_60,
                   CASE WHEN a.days_overdue BETWEEN 61 AND 90 THEN a.balance ELSE 0 END AS d61_90,
                   CASE WHEN a.days_overdue > 90 THEN a.balance ELSE 0 END AS d90_plus
            FROM (
              SELECT i.id, i.customer_id, i.invoice_no, i.invoice_date, i.due_date,
                     DATEDIFF(:today, i.due_date) AS days_overdue,
                     i.total_amount - COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id), 0) AS balance
              FROM crm_invoices i
              WHERE i.status <> 'cancelled'
            ) a
            JOIN crm_customers c ON c.id = a.customer_id
            WHERE a.balance > 0
            SQL);
        $money = fn (string $label) => ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']];
        $this->params($ageing, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_name', 'Customer', 'string', ['is_criteria' => true]],
            ['invoice_no', 'Invoice', 'string', ['json_config' => ['aggregate' => 'count']]],
            ['invoice_date', 'Date', 'date'],
            ['due_date', 'Due', 'date'],
            ['days_overdue', 'Days overdue', 'integer'],
            ['not_due', 'Not due', 'decimal', $money('Not due')],
            ['d1_30', '1-30 days', 'decimal', $money('1-30')],
            ['d31_60', '31-60 days', 'decimal', $money('31-60')],
            ['d61_90', '61-90 days', 'decimal', $money('61-90')],
            ['d90_plus', '90+ days', 'decimal', $money('90+')],
            ['balance', 'Balance', 'decimal', $money('Balance')],
        ]);
    }

    // ---------------------------------------------------------------- feedback and complaints

    private function service(): void
    {
        $s = $this->status['crm_complaint_workflow'];
        $form = $this->meta('crm_complaint_form', 'Complaint form', 'form', 'crm_complaints', 'A customer complaint: what, how bad, who handles it and by when. The assignee is notified.', null, null, null, [
            'var_fnc_expression_string' => <<<'JS'
                // How many days a complaint of each severity may take.
                function dueFor(severity) {
                  const days = { critical: 1, high: 3, medium: 7, low: 14 }[severity];
                  if (days === undefined) return '';
                  const d = new Date();
                  d.setDate(d.getDate() + days);
                  return d.toISOString().slice(0, 10);
                }
                JS,
            'load_expression_string' => <<<'JS'
                if (mode === 'new' && !get('due_on')) set('due_on', dueFor(get('severity')));
                JS,
            'event_expression_string' => <<<'JS'
                if (field === 'severity') set('due_on', dueFor(get('severity')));
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['complaint_no', 'Complaint no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'CMP-{yy}{MM}{seq:3}']]]],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['subject', 'Subject', 'string', ['is_required' => true, 'is_label' => true]],
            ['category', 'Category', 'string', $this->options(['product', 'service', 'delivery', 'billing', 'staff', 'other'], 'product')],
            ['severity', 'Severity', 'string', ['is_required' => true] + $this->options(['low', 'medium', 'high', 'critical'], 'medium')],
            ['assignee_id', 'Assigned to', 'integer', ['is_required' => true] + $this->lookup('crm_users_lookup')],
            ['received_on', 'Received on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['due_on', 'Resolve by', 'date'],
            ['description', 'What happened', 'text', ['render_type' => 'textarea']],
            ['resolution', 'Resolution', 'text', ['render_type' => 'textarea']],
            ['resolved_on', 'Resolved on', 'date'],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);
        $grid = $this->meta('crm_complaints_grid', 'Complaints', 'datagrid', 'crm_complaints', 'Complaints from open to closed.', $this->m['crm_complaint_workflow'], ['filter_layout' => 'sidebar']);
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['complaint_no', 'No', 'string', ['is_criteria' => true]],
            ['received_on', 'Received', 'date'],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['subject', 'Subject', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['product', 'service', 'delivery', 'billing', 'staff', 'other']]]],
            ['severity', 'Severity', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['low', 'medium', 'high', 'critical']]]],
            ['assignee_id', 'Assigned to', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['due_on', 'Resolve by', 'date'],
            ['resolved_on', 'Resolved', 'date'],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);
        $tabs = $this->meta('crm_complaints_tabs', 'Complaints', 'tabs', null, 'Complaints by status, and the ones assigned to you.');
        $this->params($tabs, [
            ['open', 'Open', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['open']]]]],
            ['in_progress', 'In progress', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['in_progress']]]]],
            ['resolved', 'Resolved', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['resolved']]]]],
            ['closed', 'Closed', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['filters' => ['status_id' => (string) $s['closed']]]]],
            ['all', 'All', 'string', ['ref_meta_id' => $grid->id]],
            ['mine', 'Assigned to me', 'string', ['ref_meta_id' => $grid->id, 'json_config' => ['fixed' => ['assignee_id' => '{user_id}']]]],
        ]);

        $body = '{customer_id}: {subject}. Severity {severity}, resolve by {due_on}.';
        $this->notification('crm_notify_complaint_assigned', 'Complaint assigned', 'Tells the assignee when a complaint is logged for them.', [
            'event' => 'created',
            'source_meta_id' => $form->id,
            'kind' => 'alert',
            'title' => 'Complaint {complaint_no} is assigned to you',
            'body' => $body,
            'link_meta_id' => $grid->id,
            'link_record' => true,
        ], [['field', 'assignee_id']]);
        $this->notification('crm_notify_complaint_reassigned', 'Complaint handed over', 'Tells the new assignee when a complaint is handed to them.', [
            'event' => 'updated',
            'source_meta_id' => $form->id,
            'changed_field' => 'assignee_id',
            'kind' => 'alert',
            'title' => 'Complaint {complaint_no} was handed to you',
            'body' => $body,
            'link_meta_id' => $grid->id,
            'link_record' => true,
        ], [['field', 'assignee_id']]);

        $feedbackForm = $this->meta('crm_feedback_form', 'Feedback form', 'form', 'crm_feedback', 'What a customer told us, and how happy they are (1-5).');
        $this->params($feedbackForm, [
            ['id', 'ID', 'integer', $this->key()],
            ['customer_id', 'Customer', 'integer', ['is_required' => true] + $this->lookup('crm_customers_grid')],
            ['received_on', 'Received on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['channel', 'Channel', 'string', ['is_required' => true] + $this->options(['email', 'phone', 'survey', 'visit', 'web'], 'email')],
            ['rating', 'Rating (1-5)', 'integer', ['min_value' => '1', 'max_value' => '5']],
            ['contact_name', 'From', 'string', ['is_label' => true]],
            ['comment', 'Comment', 'text', ['render_type' => 'textarea']],
        ]);
        $feedback = $this->meta('crm_feedback_grid', 'Feedback', 'datagrid', 'crm_feedback', 'Customer feedback and ratings.');
        $this->actions($feedback, $feedbackForm);
        $this->params($feedback, [
            ['id', 'ID', 'integer', $this->key()],
            ['received_on', 'Received', 'date'],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $this->lookup('crm_customers_grid')],
            ['channel', 'Channel', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['email', 'phone', 'survey', 'visit', 'web']]]],
            ['rating', 'Rating', 'integer', ['json_config' => ['aggregate' => 'avg']]],
            ['contact_name', 'From', 'string', ['is_label' => true]],
            ['comment', 'Comment', 'text'],
        ]);
    }

    // ---------------------------------------------------------------- the customer workspace

    private function workspace(): void
    {
        $timeline = $this->meta('crm_customer_timeline', 'Customer timeline', 'datagrid', null, 'Everything that happened with one customer, newest first.', null, null, <<<'SQL'
            SELECT t.* FROM (
              SELECT CONCAT(DATE_FORMAT(a.activity_at, '%Y%m%d%H%i%s'), '-A', LPAD(a.id, 8, '0')) AS uid, a.customer_id,
                     a.activity_at AS happened_at, a.kind AS kind, a.subject AS title, a.details AS details
              FROM crm_activities a
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(o.created_at, '%Y%m%d%H%i%s'), '-O', LPAD(o.id, 8, '0')), o.customer_id,
                     o.created_at, 'opportunity', CONCAT(o.opp_no, ' ', o.name), CONCAT('Stage ', o.stage, ', ', FORMAT(o.amount, 0))
              FROM crm_opportunities o
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(q.issue_date, '%Y%m%d%H%i%s'), '-Q', LPAD(q.id, 8, '0')), q.customer_id,
                     q.issue_date, q.doc_type, CONCAT(q.quote_no, ' ', q.title), CONCAT('Total ', FORMAT(q.total_amount, 0))
              FROM crm_quotations q
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(s.order_date, '%Y%m%d%H%i%s'), '-S', LPAD(s.id, 8, '0')), s.customer_id,
                     s.order_date, 'sales order', s.so_number, CONCAT('Total ', FORMAT(s.total_amount, 0), ', ', s.status)
              FROM crm_sales_orders s
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(i.invoice_date, '%Y%m%d%H%i%s'), '-I', LPAD(i.id, 8, '0')), i.customer_id,
                     i.invoice_date, 'invoice', i.invoice_no, CONCAT('Total ', FORMAT(i.total_amount, 0), ', due ', DATE_FORMAT(i.due_date, '%Y-%m-%d'))
              FROM crm_invoices i
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(p.paid_on, '%Y%m%d%H%i%s'), '-P', LPAD(p.id, 8, '0')), p.customer_id,
                     p.paid_on, 'payment', p.payment_no, CONCAT('Received ', FORMAT(p.amount, 0), ' by ', p.method)
              FROM crm_payments p
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(k.received_on, '%Y%m%d%H%i%s'), '-C', LPAD(k.id, 8, '0')), k.customer_id,
                     k.received_on, 'complaint', CONCAT(k.complaint_no, ' ', k.subject), CONCAT('Severity ', k.severity)
              FROM crm_complaints k
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(f.received_on, '%Y%m%d%H%i%s'), '-F', LPAD(f.id, 8, '0')), f.customer_id,
                     f.received_on, 'feedback', CONCAT('Rating ', COALESCE(f.rating, '-'), '/5 by ', f.channel), f.comment
              FROM crm_feedback f
              UNION ALL
              SELECT CONCAT(DATE_FORMAT(d.due_on, '%Y%m%d%H%i%s'), '-T', LPAD(d.id, 8, '0')), d.customer_id,
                     d.due_on, 'follow-up', d.title, CONCAT('Due ', DATE_FORMAT(d.due_on, '%Y-%m-%d'), ', ', d.status)
              FROM crm_tasks d
            ) t
            WHERE t.customer_id = :customer_id
            SQL);
        $this->params($timeline, [
            ['uid', 'Key', 'string', $this->key()],
            ['happened_at', 'When', 'datetime'],
            ['kind', 'What', 'string', ['render_type' => 'status']],
            ['title', 'Title', 'string'],
            ['details', 'Details', 'text'],
            ['customer_id', 'Customer', 'integer', ['is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
        ]);

        $workspace = $this->meta('crm_customer_workspace', 'Customer workspace', 'workspace', 'crm_customers', 'One customer and everything about them: history, contacts, deals, documents, money and service.', $this->m['crm_customer_form'], [
            'header' => ['code', 'kind', 'category_id', 'owner_id', 'status', 'phone', 'email'],
            'title_field' => 'name',
        ]);
        $scope = ['scope' => ['customer_id' => 'id']];
        $sections = [
            ['timeline', 'Timeline', 'crm_customer_timeline'],
            ['contacts', 'Contacts', 'crm_contacts_grid'],
            ['addresses', 'Addresses', 'crm_addresses_grid'],
            ['activities', 'Activities', 'crm_activities_grid'],
            ['tasks', 'Follow-ups', 'crm_tasks_grid'],
            ['opportunities', 'Opportunities', 'crm_opportunities_grid'],
            ['quotations', 'Proposals & quotations', 'crm_quotations_grid'],
            ['sales_orders', 'Sales orders', 'crm_sales_orders_grid'],
            ['receivables', 'Invoices & balance', 'crm_ar_invoices_grid'],
            ['payments', 'Payments', 'crm_payments_grid'],
            ['complaints', 'Complaints', 'crm_complaints_grid'],
            ['feedback', 'Feedback', 'crm_feedback_grid'],
        ];
        $rows = array_map(fn ($s) => [$s[0], $s[1], 'string', ['ref_meta_id' => $this->m[$s[2]]->id, 'json_config' => $scope]], $sections);
        // A customer that is also a supplier: its purchase orders from Procurement.
        $supplierOrders = Meta::where('code', 'proc_supplier_orders_grid')->first();
        if ($supplierOrders) {
            $rows[] = ['purchases', 'Purchase orders', 'string', ['ref_meta_id' => $supplierOrders->id, 'json_config' => ['scope' => ['supplier_id' => 'supplier_id']]]];
        }
        $this->params($workspace, $rows);

        // The registry: sidebar filters; a row opens the workspace.
        $grid = $this->meta('crm_customers_grid', 'Customer registry', 'datagrid', 'crm_customers', 'Every customer: companies and people, with category, owner and status.', null, [
            'filter_layout' => 'sidebar',
            'drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace'],
        ]);
        $this->actions($grid, $this->m['crm_customer_form']);
        $this->button($grid, 'stage_query', 'Make supplier', 'check', $this->m['crm_make_supplier'], null, 4, ['customer_id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['company', 'person']]]],
            ['register_no', 'Register no', 'string', ['is_criteria' => true]],
            ['category_id', 'Category', 'integer', ['is_criteria' => true] + $this->lookup('crm_customer_categories_grid')],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('crm_users_lookup')],
            ['phone', 'Phone', 'string'],
            ['email', 'Email', 'string'],
            ['is_supplier', 'Supplier', 'boolean', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['prospect', 'active', 'inactive', 'blocked']]]],
        ]);

        $balances = $this->m['crm_customer_balances_grid'];
        $balances->update(['json_config' => ['drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace']]]);
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): void
    {
        $s = $this->status['crm_complaint_workflow'];
        $openComplaints = [['column' => 'status_id', 'op' => '!=', 'value' => (string) $s['resolved']], ['column' => 'status_id', 'op' => '!=', 'value' => (string) $s['closed']]];
        $openDeals = [['column' => 'stage', 'op' => '!=', 'value' => 'won'], ['column' => 'stage', 'op' => '!=', 'value' => 'lost']];
        $notCancelled = [['column' => 'status', 'op' => '!=', 'value' => 'cancelled']];
        $balanceSql = <<<'SQL'
            SELECT i.id, i.customer_id, i.due_date, DATEDIFF(:today, i.due_date) AS days_overdue,
                   i.total_amount - COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id), 0) AS balance
            FROM crm_invoices i
            WHERE i.status <> 'cancelled'
            SQL;
        $toGrid = fn (string $grid, array $params) => ['drilldown' => ['meta_id' => $this->m[$grid]->id, 'params' => $params]];

        $charts = [
            [$this->chart('crm_chart_open_pipeline', 'Open pipeline', 'crm_opportunities', null, 'Deals not yet won or lost.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Pipeline']], 'filters' => $openDeals, 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_weighted_pipeline', 'Weighted pipeline', null, "SELECT amount * probability / 100 AS weighted FROM crm_opportunities WHERE stage NOT IN ('won', 'lost')", 'Open deals times their probability.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'weighted', 'label' => 'Weighted']], 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_invoiced', 'Invoiced', 'crm_invoices', null, 'Invoices issued in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Invoiced']], 'filters' => $notCancelled, 'date_field' => 'invoice_date', 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_received', 'Received', 'crm_payments', null, 'Payments received in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Received']], 'date_field' => 'paid_on', 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_ar_outstanding', 'Receivables', null, $balanceSql, 'What customers still owe.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'balance', 'label' => 'Owed']], 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_ar_overdue', 'Overdue', null, $balanceSql, 'Owed past the due date.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'balance', 'label' => 'Overdue']], 'filters' => [['column' => 'days_overdue', 'op' => '>', 'value' => '0']], 'format' => 'money']), 3, 1],
            [$this->chart('crm_chart_open_complaints', 'Open complaints', 'crm_complaints', null, 'Complaints not yet resolved.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Complaints']], 'filters' => $openComplaints] + $toGrid('crm_complaints_grid', [])), 3, 1],
            [$this->chart('crm_chart_new_leads', 'New leads', 'crm_leads', null, 'Leads that came in during the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Leads']], 'date_field' => 'created_at'] + $toGrid('crm_leads_grid', [])), 3, 1],
            [$this->chart('crm_chart_pipeline_by_stage', 'Pipeline by stage', null, <<<'SQL'
                SELECT CASE stage WHEN 'prospecting' THEN '1 Prospecting' WHEN 'qualification' THEN '2 Qualification'
                                  WHEN 'proposal' THEN '3 Proposal' WHEN 'negotiation' THEN '4 Negotiation'
                                  WHEN 'won' THEN '5 Won' ELSE '6 Lost' END AS stage_name,
                       amount, expected_close
                FROM crm_opportunities
                SQL, 'The amount of the deals at each stage.',
                ['type' => 'column', 'x' => 'stage_name', 'sort' => 'label', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Amount']], 'format' => 'money']), 6, 2],
            [$this->chart('crm_chart_ar_ageing', 'AR ageing', null, <<<'SQL'
                SELECT CASE WHEN a.days_overdue <= 0 THEN '0 Not due' WHEN a.days_overdue <= 30 THEN '1-30 days'
                            WHEN a.days_overdue <= 60 THEN '31-60 days' WHEN a.days_overdue <= 90 THEN '61-90 days'
                            ELSE '90+ days' END AS age, a.balance
                FROM (
                  SELECT DATEDIFF(:today, i.due_date) AS days_overdue,
                         i.total_amount - COALESCE((SELECT SUM(p.amount) FROM crm_payments p WHERE p.invoice_id = i.id), 0) AS balance
                  FROM crm_invoices i
                  WHERE i.status <> 'cancelled'
                ) a
                WHERE a.balance > 0
                SQL, 'What customers owe, by how overdue it is.',
                ['type' => 'column', 'x' => 'age', 'sort' => 'label', 'measures' => [['agg' => 'sum', 'column' => 'balance', 'label' => 'Owed']], 'format' => 'money']), 6, 2],
            [$this->chart('crm_chart_sales_by_month', 'Sales by month', null, <<<'SQL'
                SELECT invoice_date AS day, total_amount AS invoiced, 0 AS received FROM crm_invoices WHERE status <> 'cancelled'
                UNION ALL
                SELECT paid_on, 0, amount FROM crm_payments
                SQL, 'Invoiced and received, month by month.',
                ['type' => 'column', 'x' => 'day', 'bucket' => 'month', 'date_field' => 'day', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'invoiced', 'label' => 'Invoiced'],
                    ['agg' => 'sum', 'column' => 'received', 'label' => 'Received'],
                ]]), 8, 2],
            [$this->chart('crm_chart_leads_by_source', 'Leads by source', 'crm_leads', null, 'Where leads come from.',
                ['type' => 'donut', 'x' => 'source', 'measures' => [['agg' => 'count', 'label' => 'Leads']]] + $toGrid('crm_leads_grid', ['source' => 'x'])), 4, 2],
            [$this->chart('crm_chart_top_customers', 'Top customers', 'crm_invoices', null, 'Who we invoiced most in the period.',
                ['type' => 'table', 'x' => 'customer_id', 'x_lookup' => ['meta_id' => $this->m['crm_customers_grid']->id, 'label_field' => 'name'], 'filters' => $notCancelled,
                    'date_field' => 'invoice_date', 'limit' => 10, 'format' => 'money', 'measures' => [
                        ['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Invoiced'],
                        ['agg' => 'count', 'label' => 'Invoices'],
                    ]]), 8, 2],
            [$this->chart('crm_chart_complaints_by_severity', 'Open complaints by severity', 'crm_complaints', null, 'How bad the open complaints are.',
                ['type' => 'donut', 'x' => 'severity', 'measures' => [['agg' => 'count', 'label' => 'Complaints']], 'filters' => $openComplaints] + $toGrid('crm_complaints_grid', ['severity' => 'x'])), 4, 2],
        ];

        $dashboard = $this->meta('crm_dashboard', 'CRM dashboard', 'dashboard', null, 'Pipeline, sales, receivables, leads and complaints at a glance.');
        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'crm_chart_');
            MetaParam::create([
                'main_meta_id' => $dashboard->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'data_type' => 'chart',
                'ref_meta_id' => $chart->id,
                'json_config' => ['w' => $w, 'h' => $h],
                'is_show' => true,
            ]);
        }
    }

    // ---------------------------------------------------------------- the app and who may use it

    private function app(): void
    {
        $setup = $this->meta('crm_setup', 'CRM setup', 'tabs', null, 'Customer categories and the sales reps you can pick.');
        $this->params($setup, [
            ['categories', 'Customer categories', 'string', ['ref_meta_id' => $this->m['crm_customer_categories_grid']->id]],
            ['sales_reps', 'Sales reps', 'string', ['ref_meta_id' => $this->m['crm_users_lookup']->id]],
        ]);

        $menu = fn (string $field, string $label, string $code, array $config = [], ?string $icon = null) => [$field, $label, 'string', array_filter([
            'ref_meta_id' => $this->m[$code]->id,
            'icon_cls' => $icon,
            'json_config' => $config ?: null,
        ], fn ($v) => $v !== null)];

        $purchases = [];
        $orders = Meta::where('code', 'proc_orders_grid')->first();
        if ($orders) {
            $purchases[] = ['purchase_orders', 'Purchase orders', 'string', ['ref_meta_id' => $orders->id]];
        }

        $app = $this->meta('crm_app', 'CRM', 'app', null, 'Customers, sales pipeline, quotations, sales, receivables and service.');
        $this->params($app, [
            $menu('dashboard', 'Dashboard', 'crm_dashboard', [], 'chart'),
            ['customers', 'Customers', 'string', ['icon_cls' => 'users', 'children' => [
                $menu('registry', 'Customer registry', 'crm_customers_grid'),
                $menu('workspace', 'Customer workspace', 'crm_customer_workspace'),
                $menu('contacts', 'Contacts', 'crm_contacts_grid'),
                $menu('activities', 'Activities', 'crm_activities_grid'),
                $menu('follow_ups', 'Follow-ups', 'crm_tasks_grid', ['filters' => ['status' => 'open']]),
                $menu('follow_up_calendar', 'Follow-up calendar', 'crm_tasks_calendar'),
            ]]],
            ['pipeline', 'Sales pipeline', 'string', ['icon_cls' => 'zap', 'children' => [
                $menu('leads', 'Leads', 'crm_leads_tabs'),
                $menu('opportunities', 'Opportunities', 'crm_opportunity_tabs'),
                $menu('proposals', 'Proposals', 'crm_quotations_grid', ['fixed' => ['doc_type' => 'proposal']]),
                $menu('quotations', 'Quotations', 'crm_quotations_grid', ['fixed' => ['doc_type' => 'quotation']]),
            ]]],
            ['catalogue', 'Catalogue & prices', 'string', ['icon_cls' => 'package', 'children' => [
                $menu('items', 'Items', 'crm_items_grid'),
                $menu('services', 'Services', 'crm_services_grid'),
                $menu('price_lists', 'Price lists', 'crm_price_lists_grid'),
                $menu('price_finder', 'Price finder', 'crm_price_lookup'),
            ]]],
            ['sales', 'Sales & purchases', 'string', ['icon_cls' => 'cart', 'children' => [
                $menu('sales_orders', 'Sales orders', 'crm_sales_orders_grid'),
                $menu('invoices', 'Invoices', 'crm_invoices_grid'),
                ...$purchases,
                $menu('suppliers', 'Suppliers', 'crm_customers_grid', ['fixed' => ['is_supplier' => '1']]),
            ]]],
            ['finance', 'Finance (AR)', 'string', ['icon_cls' => 'receipt', 'children' => [
                $menu('receivables', 'Receivables', 'crm_ar_invoices_grid', ['filters' => ['is_open' => '1']]),
                $menu('balances', 'Customer balances', 'crm_customer_balances_grid'),
                $menu('ageing', 'AR ageing', 'crm_ar_ageing'),
                $menu('payments', 'Payments register', 'crm_payments_grid'),
            ]]],
            ['service', 'Service', 'string', ['icon_cls' => 'tool', 'children' => [
                $menu('complaints', 'Complaints', 'crm_complaints_tabs'),
                $menu('feedback', 'Feedback', 'crm_feedback_grid'),
            ]]],
            $menu('setup', 'Setup', 'crm_setup', [], 'settings'),
        ]);
    }

    /**
     * The "crm" role: view on every CRM meta, full use of its forms, running its stage queries;
     * plus the Procurement purchase order screens the menu reuses. Metas without rows are admin-only.
     */
    private function access(): void
    {
        $role = Role::updateOrCreate(['code' => 'crm'], ['name' => 'CRM user', 'description' => 'Sales and service staff using the CRM app: customers, pipeline, quotations, sales, receivables and complaints.']);
        $tag = Tag::firstOrCreate(['name' => 'crm'], ['color' => 'blue']);

        $types = array_flip($this->types);
        $grant = function (Meta $meta) use ($role, $types) {
            $type = $types[$meta->type_id] ?? null;
            $full = $type === 'form';
            MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], [
                'can_view' => true,
                'can_create' => $full,
                'can_edit' => $full || $type === 'stage_query',
                'can_delete' => $full,
            ]);
        };

        $ids = [];
        foreach ($this->m as $meta) {
            $grant($meta);
            $ids[] = $meta->id;
        }
        DB::table('meta_tag_map')->where('tag_id', $tag->id)->whereNotIn('meta_id', $ids)->delete();
        $tagged = DB::table('meta_tag_map')->where('tag_id', $tag->id)->pluck('meta_id')->all();
        $now = now();
        foreach (array_diff($ids, $tagged) as $id) {
            DB::table('meta_tag_map')->insert(['meta_id' => $id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Reused from Procurement: purchase orders and what their screens need.
        foreach (Meta::whereIn('code', [
            'proc_orders_grid', 'proc_order_form', 'proc_order_line_form', 'proc_order_line_delivery_form', 'proc_order_workflow',
            'proc_order_get', 'proc_order_new_get', 'proc_order_reject_form', 'proc_order_report', 'proc_suppliers_grid',
            'proc_items_grid', 'proc_supplier_orders_grid',
        ])->get() as $meta) {
            $grant($meta);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null, array $extra = []): Meta
    {
        $meta = Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'query_string' => $query,
            'is_active' => true,
        ] + $extra);

        return $this->m[$code] = $meta;
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

            // The row's own attributes win, e.g. is_show false for a hidden column.
            $param = MetaParam::create($extra + [
                'main_meta_id' => $meta->id,
                'parent_id' => $parentId,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
                'is_show' => true,
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

    /** A lookup into one of the CRM grids made so far; $extra wins (e.g. json_config.fill). */
    private function lookup(string $grid, string $display = 'name', array $extra = []): array
    {
        return $extra + ['is_select' => true, 'ref_meta_id' => $this->m[$grid]->id, 'render_type' => 'lookup', 'display_field' => $display];
    }

    private function sql(string $sql, array $values): string
    {
        return strtr($sql, array_map('strval', $values));
    }

    /** Replaces a grid's Add, Edit and Delete buttons (see SeedsActions). */
    private function actions(Meta $grid, Meta $form, ?Meta $edit = null, ?Meta $add = null): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        foreach ([
            ['add', 'Add', 'plus', $add, false, false],
            ['edit', 'Edit', 'edit', $edit, true, true],
            ['delete', 'Delete', 'trash', null, true, true],
        ] as $i => [$action, $label, $icon, $get, $needsRow, $inRow]) {
            MetaAction::create([
                'grid_meta_id' => $grid->id,
                'action' => $action,
                'label' => $label,
                'icon_cls' => $icon,
                'form_meta_id' => $form->id,
                'get_meta_id' => $get?->id,
                'needs_row' => $needsRow,
                'show_in_row' => $inRow,
                'order_number' => $i + 1,
                'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
            ]);
        }
    }

    /** One more button on a picked row: a custom form (filled by a get meta) or a stage query. */
    private function button(Meta $grid, string $action, string $label, string $icon, Meta $target, ?Meta $get, int $order, array $params): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->where('label', $label)->delete();
        MetaAction::create([
            'grid_meta_id' => $grid->id,
            'action' => $action,
            'label' => $label,
            'icon_cls' => $icon,
            'form_meta_id' => $target->id,
            'get_meta_id' => $get?->id,
            'needs_row' => true,
            'show_in_row' => true,
            'order_number' => $order,
            'json_config' => ['params' => $params],
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

    /**
     * Replaces a form's process flow. Steps by key: process (a form or stage query), after (the
     * step it follows; none = right after the form), criteria, map (target field => source path).
     */
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
                    'main_process_id' => $form->id,
                    'source_id' => $after !== null ? $ids[$after] : null,
                    'target_id' => $ids[$key],
                    'criteria' => $step['criteria'] ?? [],
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
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $value,
                'param_name' => $value,
                'data_type' => $kind,
                'is_show' => true,
            ]);
        }
    }
};
