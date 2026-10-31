<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\MetaSchedule;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Tag;
use App\Models\WfmStatus;
use App\Models\WfmTransition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The contract management app, built only from metas (tables: 2026_12_03_000008_create_cm_tables):
 *
 *   Contracts (app cm_app)
 *   ├─ Dashboard           active contracts and value, expiring, collaterals held, acted, paid,
 *   │                      fines, overdue payments; by status, by type, acted vs paid by month,
 *   │                      top contractors, contractor performance, collaterals by kind
 *   ├─ Contracts           drafts, contracts by status (workflow), contract workspace, timeline
 *   │                      (gantt), parties, lines & execution
 *   ├─ Changes             extensions & amendments (applied when approved), terminations
 *   ├─ Execution           completion acts (PDF), progress reports, performance reviews
 *   ├─ Finance             payment schedule, payments, contract balances, fines
 *   ├─ Collaterals         active, expiring, released, called
 *   ├─ Library             contract types, standard terms, penalty types
 *   └─ Alerts sent
 *
 * A contract is drafted with its parties (two or more; customers, suppliers or anyone),
 * lines (items, services and jobs of the shared catalogue), terms (from templates), penalty
 * clauses and payment schedule, then goes draft -> review -> approved -> active (signing asks
 * for the dates) -> completed, or suspended, expired, terminated. Process flows: an approved
 * extension moves the end date and adds to the value; a termination marks the contract
 * terminated; a completion act saved without lines gets everything still to be done.
 * Every morning (schedule 0 7 * * *) owners are alerted about contracts ending, collaterals
 * expiring and payments due (30/14/7/1/0 days, and overdue payments).
 *
 * Idempotent: metas matched by code, their params, actions and flows rebuilt; workflow
 * statuses keep their ids. Role "contracts" gets the permissions. MySQL only.
 */
return new class extends Migration
{
    private const DIRECTIONS = ['purchase', 'sale', 'service', 'lease', 'other'];

    private const ROLES = ['client', 'contractor', 'subcontractor', 'consortium member', 'guarantor', 'other'];

    private const UNITS = ['pcs', 'box', 'kg', 'l', 'm', 'pack', 'set', 'hour', 'day', 'month', 'visit', 'job'];

    private const CURRENCIES = ['MNT', 'USD', 'EUR', 'CNY'];

    private const TERM_CATEGORIES = ['general', 'scope', 'price', 'payment', 'delivery', 'acceptance', 'warranty', 'penalty', 'confidentiality', 'force majeure', 'termination', 'dispute'];

    private const BASES = ['per_day_pct', 'pct', 'fixed'];

    private const COLLATERALS = ['bank_guarantee', 'performance_bond', 'advance_payment_guarantee', 'warranty_bond', 'bid_bond', 'cash_deposit', 'insurance', 'pledge'];

    private const METHODS = ['bank transfer', 'cash', 'card', 'offset'];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> the metas made here, by code */
    private array $m = [];

    /** @var array<string, Meta> metas of other apps reused here */
    private array $ext = [];

    /** @var array<string, int> status code => id */
    private array $status = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('cm_contracts')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        $this->ext = Meta::whereIn('code', ['crm_customers_grid', 'proc_suppliers_grid', 'crm_items_grid', 'proc_items_grid', 'crm_item_categories_grid'])->get()->keyBy('code')->all();
        $this->ext['items'] = $this->ext['crm_items_grid'] ?? $this->ext['proc_items_grid'] ?? null;
        if (! $this->ext['items']) {
            return;
        }

        DB::transaction(function () {
            $this->library();
            $this->workflow();
            $this->contracts();
            $this->changes();
            $this->execution();
            $this->finance();
            $this->collaterals();
            $this->alerts();
            $this->notifications();
            $this->workspace();
            $this->dashboard();
            $this->app();
            $this->access();
        });

        // DDL commits on MySQL, so after the transaction: a new contract starts as a draft.
        DB::statement(sprintf('ALTER TABLE `cm_contracts` ALTER `status_id` SET DEFAULT %d', $this->status['draft']));
        DB::table('cm_contracts')->whereNull('status_id')->update(['status_id' => $this->status['draft']]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        if (Schema::hasTable('cm_contracts')) {
            DB::statement('ALTER TABLE `cm_contracts` ALTER `status_id` DROP DEFAULT');
        }
        DB::transaction(function () {
            $ids = DB::table('metas')->where('code', 'like', 'cm\_%')->pluck('id');
            DB::table('meta_schedules')->whereIn('process_id', $ids)->orWhereIn('view_id', $ids)->delete();
            DB::table('wfm_status_log')->whereIn('workflow_meta_id', $ids)->delete();
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->whereNotIn('main_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('ref_meta_id', $ids)->whereNotIn('id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('roles')->where('code', 'contracts')->delete();
            DB::table('tags')->where('name', 'contracts')->delete();
            DB::table('autonumbers')->where('table_name', 'like', 'cm\_%')->delete();
        });
    }

    // ---------------------------------------------------------------- library

    private function library(): void
    {
        $users = $this->meta('cm_users_lookup', 'People', 'datagrid', null, 'Everyone who can sign in, to pick as an owner or reviewer.', null, null, 'SELECT id, name, email FROM users');
        $this->params($users, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
        ]);

        $form = $this->meta('cm_contract_type_form', 'Contract type form', 'form', 'cm_contract_types', 'A kind of contract: its direction, usual length and whether it needs a collateral.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['direction', 'Direction', 'string', ['is_required' => true] + $this->options(self::DIRECTIONS, 'purchase')],
            ['default_months', 'Usual length (months)', 'integer', ['min_value' => '0']],
            ['requires_collateral', 'Needs a collateral', 'boolean', ['default_value' => '0']],
            ['collateral_pct', 'Collateral % of value', 'decimal', ['min_value' => '0', 'max_value' => '100']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('cm_contract_types_grid', 'Contract types', 'datagrid', 'cm_contract_types', 'Kinds of contracts.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['direction', 'Direction', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DIRECTIONS]]],
            ['default_months', 'Usual months', 'integer'],
            ['requires_collateral', 'Collateral', 'boolean'],
            ['collateral_pct', 'Collateral %', 'decimal'],
        ]);

        $form = $this->meta('cm_term_template_form', 'Standard term form', 'form', 'cm_term_templates', 'A standard clause to put into contracts.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['category', 'Category', 'string', ['is_required' => true] + $this->options(self::TERM_CATEGORIES, 'general')],
            ['title', 'Title', 'string', ['is_required' => true, 'is_label' => true]],
            ['body', 'Text', 'text', ['is_required' => true, 'render_type' => 'textarea']],
            ['is_standard', 'Standard', 'boolean', ['default_value' => '1']],
        ]);
        $grid = $this->meta('cm_term_templates_grid', 'Standard terms', 'datagrid', 'cm_term_templates', 'A library of clauses for contracts.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::TERM_CATEGORIES]]],
            ['title', 'Title', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['body', 'Text', 'text'],
            ['is_standard', 'Standard', 'boolean'],
        ]);

        $form = $this->meta('cm_penalty_type_form', 'Penalty type form', 'form', 'cm_penalty_types', 'A usual penalty: per day late as % of value, a % once, or a fixed sum; capped at a % of value.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(['late_delivery', 'late_payment', 'quality', 'breach', 'other'], 'late_delivery')],
            ['basis', 'Basis', 'string', ['is_required' => true] + $this->options(self::BASES, 'per_day_pct')],
            ['rate', 'Rate (% or sum)', 'decimal', ['is_required' => true, 'min_value' => '0']],
            ['cap_pct', 'Cap % of value', 'decimal', ['min_value' => '0', 'max_value' => '100']],
            ['description', 'Description', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('cm_penalty_types_grid', 'Penalty types', 'datagrid', 'cm_penalty_types', 'Usual penalties to put into contracts.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['basis', 'Basis', 'string'],
            ['rate', 'Rate', 'decimal'],
            ['cap_pct', 'Cap %', 'decimal'],
        ]);
    }

    // ---------------------------------------------------------------- the contract workflow

    private function workflow(): void
    {
        $sign = $this->meta('cm_contract_sign_form', 'Signing', 'form', 'cm_contracts', 'When the contract was signed and the period it runs; asked for when it becomes active.');
        $this->params($sign, [
            ['id', 'ID', 'integer', $this->key()],
            ['signed_on', 'Signed on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['start_date', 'Starts', 'date', ['is_required' => true]],
            ['end_date', 'Ends', 'date', ['is_required' => true]],
        ]);
        $complete = $this->meta('cm_contract_complete_form', 'Completion', 'form', 'cm_contracts', 'When the contract was fully performed; asked for when it is completed.');
        $this->params($complete, [
            ['id', 'ID', 'integer', $this->key()],
            ['completed_on', 'Completed on', 'date', ['is_required' => true, 'default_value' => '{today}']],
        ]);

        $workflow = $this->meta('cm_contract_workflow', 'Contract workflow', 'workflow', 'cm_contracts', 'Draft → review → approved → active → completed; active ones can be suspended or expire; Terminate (a form) ends one early.');
        $statuses = [
            ['draft', 'Draft', 'gray', null, [130, 130]],
            ['review', 'In review', 'amber', null, [310, 130]],
            ['approved', 'Approved', 'purple', null, [490, 130]],
            ['active', 'Active', 'blue', $sign->id, [670, 130]],
            ['completed', 'Completed', 'green', $complete->id, [850, 130]],
            ['suspended', 'Suspended', 'orange', null, [670, 300]],
            ['expired', 'Expired', 'gray', null, [850, 300]],
            ['terminated', 'Terminated', 'red', null, [490, 300]],
        ];
        $transitions = [
            [null, 'draft', null, true], ['draft', 'review', 'totalAmount > 0', true], ['review', 'approved', null, true],
            ['approved', 'active', null, true], ['active', 'completed', null, true],
            ['review', 'draft', null, true], ['approved', 'draft', null, true],
            ['active', 'suspended', null, true], ['suspended', 'active', null, true], ['active', 'expired', null, true],
            // Drawn only: the Terminate button's process flow moves a contract here.
            ['active', 'terminated', null, false], ['suspended', 'terminated', null, false],
        ];
        $ids = [];
        $layout = ['start' => ['x' => 30, 'y' => 150], 'statuses' => []];
        foreach ($statuses as [$code, $name, $color, $form, [$x, $y]]) {
            $ids[$code] = WfmStatus::updateOrCreate(['workflow_meta_id' => $workflow->id, 'code' => $code], ['name' => $name, 'color' => $color, 'meta_id' => $form])->id;
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

    // ---------------------------------------------------------------- contracts

    private function contracts(): void
    {
        $s = $this->status;
        // The contract pick list first, so the forms below can point at it.
        $lookup = $this->meta('cm_contracts_lookup', 'Contracts (pick list)', 'datagrid', 'cm_contracts', 'Contracts with their current value and end date, to pick on acts, payments, collaterals, fines...', null, null, <<<'SQL'
            SELECT c.id, c.contract_no, c.title, c.owner_id, c.currency, c.end_date, c.status_id,
                   c.total_amount + c.amended_amount AS total_value,
                   CONCAT(c.contract_no, ' · ', c.title) AS label
            FROM cm_contracts c
            SQL);
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Contract', 'string', ['is_label' => true, 'is_show' => false]],
            ['contract_no', 'Number', 'string', ['is_criteria' => true]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['owner_id', 'Owner', 'integer', $this->lookup('cm_users_lookup')],
            ['end_date', 'Ends', 'date'],
            ['total_value', 'Value', 'decimal', ['render_type' => 'money']],
            ['currency', 'Currency', 'string'],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);

        // The parts of a contract, edited as its lines.
        $party = $this->meta('cm_party_form', 'Contract party', 'form', 'cm_contract_parties', 'A party to a contract: who, in what role, and who signs.');
        $partyRows = [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_show' => false]],
            ['role', 'Role', 'string', ['is_required' => true] + $this->options(self::ROLES, 'contractor')],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
        ];
        if (isset($this->ext['crm_customers_grid'])) {
            $partyRows[] = ['customer_id', 'Customer (CRM)', 'integer', $this->lookup($this->ext['crm_customers_grid'], 'name', ['json_config' => ['fill' => ['name' => 'name', 'register_no' => 'register_no', 'email' => 'email', 'phone' => 'phone']]])];
        }
        if (isset($this->ext['proc_suppliers_grid'])) {
            $partyRows[] = ['supplier_id', 'Supplier (Procurement)', 'integer', $this->lookup($this->ext['proc_suppliers_grid'], 'name', ['json_config' => ['fill' => ['name' => 'name', 'email' => 'email']]])];
        }
        $this->params($party, array_merge($partyRows, [
            ['register_no', 'Register no', 'string'],
            ['address', 'Address', 'string'],
            ['signatory', 'Signatory', 'string'],
            ['signatory_position', 'Position', 'string'],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['phone', 'Phone', 'string'],
            ['share_pct', 'Share %', 'decimal', ['min_value' => '0', 'max_value' => '100']],
            ['is_lead', 'Lead', 'boolean', ['default_value' => '0']],
        ]));

        $line = $this->meta('cm_contract_line_form', 'Contract line', 'form', 'cm_contract_lines', 'An item, service or job of a contract: quantity, unit price, when.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_show' => false]],
            ['item_id', 'Item / service / job', 'integer', $this->lookup($this->ext['items'], 'name', ['json_config' => ['fill' => ['description' => 'name', 'unit' => 'unit', 'unit_price' => 'unit_price']]])],
            ['description', 'Description', 'string'],
            ['unit', 'Unit', 'string', $this->options(self::UNITS, 'pcs')],
            ['quantity', 'Qty', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['line_total', 'Amount', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['delivery_date', 'By', 'date'],
        ]);

        $term = $this->meta('cm_contract_term_form', 'Contract term', 'form', 'cm_contract_terms', 'A clause of a contract; pick a standard one to start from.');
        $this->params($term, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_show' => false]],
            ['template_id', 'From standard term', 'integer', $this->lookup('cm_term_templates_grid', 'title', ['json_config' => ['fill' => ['title' => 'title', 'body' => 'body', 'category' => 'category']]])],
            ['clause_no', 'Clause', 'string'],
            ['category', 'Category', 'string', $this->options(self::TERM_CATEGORIES, 'general')],
            ['title', 'Title', 'string', ['is_required' => true, 'is_label' => true]],
            ['body', 'Text', 'text', ['render_type' => 'textarea']],
        ]);

        $penalty = $this->meta('cm_contract_penalty_form', 'Penalty clause', 'form', 'cm_contract_penalties', 'A penalty a contract allows: basis, rate and cap.');
        $this->params($penalty, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_show' => false]],
            ['penalty_type_id', 'Penalty type', 'integer', $this->lookup('cm_penalty_types_grid', 'name', ['json_config' => ['fill' => ['basis' => 'basis', 'rate' => 'rate', 'cap_pct' => 'cap_pct', 'description' => 'name']]])],
            ['description', 'Description', 'string', ['is_label' => true]],
            ['basis', 'Basis', 'string', ['is_required' => true] + $this->options(self::BASES, 'per_day_pct')],
            ['rate', 'Rate (% or sum)', 'decimal', ['is_required' => true, 'min_value' => '0']],
            ['cap_pct', 'Cap % of value', 'decimal', ['min_value' => '0', 'max_value' => '100']],
        ]);

        $schedule = $this->meta('cm_payment_schedule_form', 'Payment milestone', 'form', 'cm_payment_schedules', 'A payment the contract plans: advance, progress, final or retention.');
        $this->params($schedule, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_show' => false]],
            ['milestone', 'Milestone', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', $this->options(['advance', 'progress', 'final', 'retention'], 'progress')],
            ['due_date', 'Due', 'date'],
            ['pct', '% of value', 'decimal', ['min_value' => '0', 'max_value' => '100']],
            ['amount', 'Amount', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['notes', 'Note', 'string'],
        ]);

        // The printed contract.
        $print = fn (string $code, string $table, array $rows) => $this->params($this->meta($code, Str::headline(Str::after($code, 'cm_')), 'form', $table, 'The columns the printed contract shows.'), array_merge([
            ['id', 'ID', 'integer', $this->key()], ['contract_id', 'Contract', 'integer', ['is_show' => false]],
        ], $rows));
        $print('cm_party_print', 'cm_contract_parties', [['role', 'Role', 'string'], ['name', 'Name', 'string'], ['register_no', 'Register no', 'string'], ['signatory', 'Signatory', 'string'], ['signatory_position', 'Position', 'string']]);
        $print('cm_line_print', 'cm_contract_lines', [['description', 'Description', 'string'], ['unit', 'Unit', 'string'], ['quantity', 'Qty', 'decimal'], ['unit_price', 'Unit price', 'decimal', ['render_type' => 'money']], ['line_total', 'Amount', 'decimal', ['render_type' => 'money']]]);
        $print('cm_schedule_print', 'cm_payment_schedules', [['milestone', 'Milestone', 'string'], ['due_date', 'Due', 'date'], ['pct', '%', 'decimal'], ['amount', 'Amount', 'decimal', ['render_type' => 'money']]]);
        $print('cm_term_print', 'cm_contract_terms', [['clause_no', 'Clause', 'string'], ['title', 'Title', 'string'], ['body', 'Text', 'text']]);
        $report = $this->meta('cm_contract_report', 'Contract', 'report', 'cm_contracts', 'The contract to print or send as a PDF: parties, scope, price, payments and terms.', null, [
            'title' => 'Contract',
            'number_field' => 'contract_no',
            'issuer' => 'Blue Sky LLC · Legal',
            'note' => 'Signed in two copies of equal force, one for each party.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_no', 'Number', 'string'],
            ['title', 'Subject', 'string'],
            ['contract_type_id', 'Type', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['cm_contract_types_grid']->id, 'display_field' => 'name']],
            ['signed_on', 'Signed on', 'date'],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['description', 'Subject of the contract', 'text'],
            ['parties', 'Parties', 'detail', ['ref_meta_id' => $this->m['cm_party_print']->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['lines', 'Items, services and jobs', 'detail', ['ref_meta_id' => $this->m['cm_line_print']->id, 'json_config' => ['foreign_key' => 'contract_id', 'amount' => ['line_total']]]],
            ['total_amount', 'Contract price', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['currency', 'Currency', 'string'],
            ['schedule', 'Payments', 'detail', ['ref_meta_id' => $this->m['cm_schedule_print']->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['terms', 'Terms and conditions', 'detail', ['ref_meta_id' => $this->m['cm_term_print']->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['signed_client', 'For the client', 'signature'],
            ['signed_contractor', 'For the contractor', 'signature'],
        ]);

        $form = $this->meta('cm_contract_form', 'Contract form', 'form', 'cm_contracts', 'A contract with its parties (two or more), lines, terms, penalties and payment schedule. Every change is logged.', null, ['print_meta_id' => $report->id], null, [
            'is_log' => true,
            'var_fnc_expression_string' => <<<'JS'
                // A line's amount, the contract's value, and milestones given as % of it.
                function lineTotal(l) {
                  return round(num(l.quantity) * num(l.unit_price), 2);
                }
                function docTotal() {
                  return round(sum('lines', (l) => lineTotal(l)), 2);
                }
                function refresh() {
                  lines('lines').forEach((l, i) => setLine('lines', i, 'line_total', lineTotal(l)));
                  set('total_amount', docTotal());
                  lines('schedule').forEach((s, i) => {
                    if (num(s.pct) > 0) setLine('schedule', i, 'amount', round(docTotal() * num(s.pct) / 100, 2));
                  });
                }
                JS,
            'load_expression_string' => "readonly('total_amount');\nreadonly('amended_amount');\nrefresh();",
            'event_expression_string' => <<<'JS'
                if (detail === 'lines' || detail === 'schedule') {
                  refresh();
                }
                JS,
            'before_save_expression_string' => <<<'JS'
                const parties = lines('parties');
                if (parties.length < 2) {
                  error('A contract needs at least two parties.');
                }
                if (round(sum('parties', (p) => num(p.share_pct)), 2) > 100) {
                  error('The parties\' shares add up to more than 100%.');
                }
                if (get('start_date') && get('end_date') && get('end_date') < get('start_date')) {
                  error('The end date is before the start date.');
                }
                if (docTotal() > 0 && round(sum('schedule', (s) => num(s.amount)), 2) > docTotal() + 0.01) {
                  error('The payment schedule adds up to more than the contract value.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_no', 'Contract no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => [
                'format' => 'CT-{yy}{MM}{seq:4}',
                'rules' => [['field' => 'direction', 'value' => 'sale', 'format' => 'SC-{yy}{MM}{seq:4}']],
            ]]]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_label' => true]],
            ['contract_type_id', 'Type', 'integer', $this->lookup('cm_contract_types_grid', 'name', ['json_config' => ['fill' => ['direction' => 'direction']]])],
            ['direction', 'Direction', 'string', ['is_required' => true] + $this->options(self::DIRECTIONS, 'purchase')],
            ['owner_id', 'Owner', 'integer', ['default_value' => '{user_id}'] + $this->lookup('cm_users_lookup')],
            ['department', 'Department', 'string'],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['currency', 'Currency', 'string', ['is_required' => true] + $this->options(self::CURRENCIES, 'MNT')],
            ['payment_terms_days', 'Payment terms (days)', 'integer', ['min_value' => '0', 'default_value' => '30']],
            ['advance_pct', 'Advance %', 'decimal', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['retention_pct', 'Retention %', 'decimal', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['total_amount', 'Contract value', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['amended_amount', 'Amendments', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['description', 'Subject', 'text', ['render_type' => 'textarea']],
            ['parties', 'Parties', 'detail', ['ref_meta_id' => $party->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['lines', 'Items, services, jobs', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'contract_id', 'amount' => ['quantity', 'unit_price'], 'total_field' => 'total_amount']]],
            ['terms', 'Terms', 'detail', ['ref_meta_id' => $term->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['penalties', 'Penalties', 'detail', ['ref_meta_id' => $penalty->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['schedule', 'Payment schedule', 'detail', ['ref_meta_id' => $schedule->id, 'json_config' => ['foreign_key' => 'contract_id']]],
            ['notes', 'Internal notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'], 'max_size' => 20480, 'max_files' => 20]]],
        ]);

        $new = $this->meta('cm_contract_new_get', 'New contract values', 'get', null, 'A new contract starts today and runs a year.', null, null,
            'SELECT :today AS start_date, DATE_SUB(DATE_ADD(:today, INTERVAL 12 MONTH), INTERVAL 1 DAY) AS end_date');
        $this->params($new, []);

        $grid = $this->meta('cm_contracts_grid', 'Contracts', 'datagrid', 'cm_contracts', 'Every contract, from draft to completed or terminated.', $this->m['cm_contract_workflow'], [
            'filter_layout' => 'sidebar',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print'],
        ]);
        $this->actions($grid, $form, null, $new);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_no', 'No', 'string', ['is_criteria' => true]],
            ['title', 'Title', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['contract_type_id', 'Type', 'integer', ['is_criteria' => true] + $this->lookup('cm_contract_types_grid')],
            ['direction', 'Direction', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DIRECTIONS]]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['total_amount', 'Value', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['amended_amount', 'Amendments', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['currency', 'Currency', 'string'],
            ['status_id', 'Status', 'integer', ['is_criteria' => true]],
        ]);

        $tab = fn (string $field, string $label, array $config) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $config ?: null]];
        $rows = [];
        foreach (['draft' => 'Drafts', 'review' => 'In review', 'approved' => 'Approved', 'active' => 'Active', 'suspended' => 'Suspended', 'completed' => 'Completed', 'expired' => 'Expired', 'terminated' => 'Terminated'] as $code => $label) {
            $rows[] = $tab($code, $label, ['filters' => ['status_id' => (string) $s[$code]]]);
        }
        $rows[] = $tab('all', 'All', []);
        $rows[] = $tab('mine', 'Mine', ['fixed' => ['owner_id' => '{user_id}']]);
        $this->params($this->meta('cm_contracts_tabs', 'Contracts', 'tabs', null, 'Contracts by where they stand, and your own.'), $rows);

        // Registers of the parts (read-only; a contract's parts are edited in its form).
        $parties = $this->meta('cm_parties_grid', 'Contract parties', 'datagrid', 'cm_contract_parties', 'Who is party to which contract, in what role.', null, [
            'filter_layout' => 'sidebar',
        ], <<<'SQL'
            SELECT p.id, p.contract_id, c.contract_no, p.role, p.name, p.register_no, p.signatory, p.email, p.phone,
                   p.share_pct, p.is_lead, CONCAT(p.name, ' (', p.role, ', ', c.contract_no, ')') AS label
            FROM cm_contract_parties p
            JOIN cm_contracts c ON c.id = p.contract_id
            SQL);
        $this->params($parties, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Party', 'string', ['is_label' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['role', 'Role', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::ROLES]]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['register_no', 'Register no', 'string', ['is_criteria' => true]],
            ['signatory', 'Signatory', 'string'],
            ['email', 'Email', 'string'],
            ['phone', 'Phone', 'string'],
            ['share_pct', 'Share %', 'decimal'],
            ['is_lead', 'Lead', 'boolean'],
        ]);

        $terms = $this->meta('cm_terms_grid', 'Contract terms', 'datagrid', 'cm_contract_terms', 'The clauses of contracts.');
        $this->params($terms, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['clause_no', 'Clause', 'string'],
            ['category', 'Category', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::TERM_CATEGORIES]]],
            ['title', 'Title', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['body', 'Text', 'text'],
        ]);

        $penalties = $this->meta('cm_penalties_lookup', 'Penalty clauses', 'datagrid', 'cm_contract_penalties', 'The penalties contracts allow, to impose a fine under.', null, null, <<<'SQL'
            SELECT p.id, p.contract_id, p.basis, p.rate, p.cap_pct, p.description,
                   CONCAT(c.contract_no, ' · ', COALESCE(NULLIF(p.description, ''), p.basis), ' · ', p.rate, CASE WHEN p.basis = 'fixed' THEN '' ELSE '%' END) AS label
            FROM cm_contract_penalties p
            JOIN cm_contracts c ON c.id = p.contract_id
            SQL);
        $this->params($penalties, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Penalty', 'string', ['is_label' => true, 'is_show' => false]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['description', 'Description', 'string', ['is_criteria' => true]],
            ['basis', 'Basis', 'string'],
            ['rate', 'Rate', 'decimal'],
            ['cap_pct', 'Cap %', 'decimal'],
        ]);

        // Lines with how much is done (on completion acts not rejected): execution by line.
        $execution = $this->meta('cm_contract_lines_grid', 'Lines & execution', 'datagrid', 'cm_contract_lines', 'Each contract line: ordered, done on completion acts, still to do.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT l.id, l.contract_id, c.contract_no, l.item_id,
                   COALESCE(NULLIF(l.description, ''), i.name) AS description,
                   COALESCE(k.is_service, 0) AS is_service, COALESCE(k.is_job, 0) AS is_job,
                   l.unit, l.quantity, l.unit_price, l.line_total, l.delivery_date,
                   COALESCE(a.acted, 0) AS acted_qty,
                   l.quantity - COALESCE(a.acted, 0) AS remaining_qty,
                   COALESCE(a.acted_amount, 0) AS acted_amount,
                   CASE WHEN l.quantity > 0 THEN ROUND(100 * COALESCE(a.acted, 0) / l.quantity, 1) ELSE 0 END AS done_pct,
                   CASE WHEN l.quantity - COALESCE(a.acted, 0) > 0 AND l.delivery_date < :today THEN 1 ELSE 0 END AS is_late,
                   CONCAT(c.contract_no, ' · ', COALESCE(NULLIF(l.description, ''), i.name), ' · left ', FORMAT(l.quantity - COALESCE(a.acted, 0), 2)) AS label
            FROM cm_contract_lines l
            JOIN cm_contracts c ON c.id = l.contract_id
            LEFT JOIN proc_items i ON i.id = l.item_id
            LEFT JOIN proc_item_categories k ON k.id = i.category_id
            LEFT JOIN (SELECT al.contract_line_id, SUM(al.quantity) AS acted, SUM(al.line_total) AS acted_amount
                       FROM cm_completion_act_lines al
                       JOIN cm_completion_acts a ON a.id = al.act_id
                       WHERE a.status <> 'rejected'
                       GROUP BY al.contract_line_id) a ON a.contract_line_id = l.id
            SQL);
        $this->params($execution, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Line', 'string', ['is_label' => true, 'is_show' => false]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['description', 'Item / service / job', 'string', ['is_criteria' => true]],
            ['is_service', 'Service', 'boolean', ['is_criteria' => true]],
            ['is_job', 'Job', 'boolean', ['is_criteria' => true]],
            ['unit', 'Unit', 'string'],
            ['quantity', 'Ordered', 'decimal'],
            ['acted_qty', 'Done', 'decimal'],
            ['remaining_qty', 'To do', 'decimal'],
            ['done_pct', 'Done %', 'decimal'],
            ['unit_price', 'Unit price', 'decimal', ['render_type' => 'money']],
            ['line_total', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['acted_amount', 'Done amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['delivery_date', 'By', 'date'],
            ['is_late', 'Late', 'boolean', ['is_criteria' => true]],
            ['item_id', 'Item', 'integer', ['is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- extensions and terminations

    private function changes(): void
    {
        $contract = fn (array $fill = []) => ['is_required' => true] + $this->lookup('cm_contracts_lookup', 'label', $fill ? ['json_config' => ['fill' => $fill]] : []);

        $apply = $this->stageQuery('cm_apply_extension', 'Apply extension', 'cm_contracts', <<<'SQL'
            UPDATE cm_contracts c
            JOIN cm_extensions e ON e.contract_id = c.id
            SET c.original_end_date = COALESCE(c.original_end_date, c.end_date),
                c.end_date = COALESCE(e.new_end_date, c.end_date),
                c.amended_amount = c.amended_amount + COALESCE(e.amount_change, 0),
                c.updated_at = CURRENT_TIMESTAMP
            WHERE e.id = :extension_id AND e.status = 'approved' AND e.applied_on IS NULL;
            UPDATE cm_extensions SET applied_on = :today, updated_at = CURRENT_TIMESTAMP
            WHERE id = :extension_id AND status = 'approved' AND applied_on IS NULL
            SQL, 'Applies an approved extension or amendment to its contract (once): the new end date, and the value change added to the amendments.', [
            ['extension_id', 'Extension', 'integer', ['is_required' => true]],
        ]);

        $form = $this->meta('cm_extension_form', 'Extension / amendment', 'form', 'cm_extensions', 'A change to a contract: more time, a different value or scope. Saved as approved, it is applied at once; else Apply it later.', null, null, null, [
            'var_fnc_expression_string' => <<<'JS'
                // Only the fields the kind of change needs.
                function fit() {
                  const kind = get('kind');
                  hide('new_end_date', kind === 'value' || kind === 'scope');
                  hide('amount_change', kind === 'time');
                }
                JS,
            'load_expression_string' => "readonly('old_end_date');\nreadonly('applied_on');\nfit();",
            'event_expression_string' => "if (field === 'kind') fit();",
            'before_save_expression_string' => <<<'JS'
                const kind = get('kind');
                if ((kind === 'time' || kind === 'time_value') && !get('new_end_date')) {
                  error('Give the new end date.');
                }
                if (get('new_end_date') && get('old_end_date') && get('new_end_date') <= get('old_end_date')) {
                  error('The new end date must be after the current one.');
                }
                if ((kind === 'value' || kind === 'time_value') && !num(get('amount_change'))) {
                  error('Give the change in value (negative for less).');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['ext_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'EXT-{yy}{MM}{seq:3}']]]],
            ['contract_id', 'Contract', 'integer', $contract(['old_end_date' => 'end_date'])],
            ['kind', 'Change', 'string', ['is_required' => true] + $this->options(['time', 'value', 'scope', 'time_value'], 'time')],
            ['signed_on', 'Signed on', 'date', ['default_value' => '{today}']],
            ['old_end_date', 'Ends now', 'date'],
            ['new_end_date', 'New end date', 'date'],
            ['amount_change', 'Value change', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['reason', 'Why', 'text', ['is_required' => true, 'is_label' => true, 'render_type' => 'textarea']],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['draft', 'approved', 'rejected'], 'draft')],
            ['applied_on', 'Applied on', 'date'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'png', 'docx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);
        $this->flow($form, ['apply' => ['process' => $apply, 'criteria' => [['field' => 'status', 'op' => '=', 'value' => 'approved']], 'map' => ['extension_id' => 'id']]]);

        $fromContract = $this->meta('cm_extension_from_contract_get', 'Extension of contract', 'get', null, 'An extension of the picked contract, from its current end date.', null, null, <<<'SQL'
            SELECT c.id AS contract_id, c.end_date AS old_end_date, :today AS signed_on, 'time' AS kind, 'draft' AS status
            FROM cm_contracts c WHERE c.id = :id
            SQL);
        $this->params($fromContract, [['id', 'Contract', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $grid = $this->meta('cm_extensions_grid', 'Extensions & amendments', 'datagrid', 'cm_extensions', 'Changes to contracts: time, value and scope.', null, ['filter_layout' => 'dropdown']);
        $this->actions($grid, $form);
        $this->button($grid, 'stage_query', 'Apply', 'check', $apply, null, 4, ['extension_id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['ext_no', 'No', 'string', ['is_criteria' => true]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['kind', 'Change', 'string', ['is_criteria' => true, 'json_config' => ['options' => ['time', 'value', 'scope', 'time_value']]]],
            ['signed_on', 'Signed', 'date'],
            ['old_end_date', 'Ended', 'date'],
            ['new_end_date', 'Ends', 'date'],
            ['amount_change', 'Value change', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['reason', 'Why', 'string', ['is_label' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['draft', 'approved', 'rejected']]]],
            ['applied_on', 'Applied', 'date'],
        ]);

        $finish = $this->stageQuery('cm_finish_termination', 'Finish termination', 'cm_contracts', strtr(<<<'SQL'
            UPDATE cm_contracts SET status_id = {terminated}, terminated_on = :terminated_on, updated_at = CURRENT_TIMESTAMP
            WHERE id = :contract_id
            SQL, ['{terminated}' => (string) $this->status['terminated']]), 'Marks the contract terminated on the termination date.', [
            ['contract_id', 'Contract', 'integer', ['is_required' => true]],
            ['terminated_on', 'Terminated on', 'date', ['is_required' => true]],
        ]);
        $form = $this->meta('cm_termination_form', 'Termination', 'form', 'cm_terminations', 'Ends a contract early: who, why, when, and any settlement. Saving it marks the contract terminated.', null, ['save_and_add' => false]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['termination_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'TRM-{yy}{seq:3}']]]],
            ['contract_id', 'Contract', 'integer', ['is_unique' => true] + $contract()],
            ['notice_date', 'Notice given', 'date'],
            ['terminated_on', 'Terminated on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['initiated_by', 'By', 'string', ['is_required' => true] + $this->options(['us', 'counterparty', 'mutual'], 'mutual')],
            ['reason_kind', 'Reason', 'string', ['is_required' => true] + $this->options(['breach', 'convenience', 'force_majeure', 'mutual', 'insolvency', 'other'], 'mutual')],
            ['reason', 'Details', 'text', ['is_required' => true, 'is_label' => true, 'render_type' => 'textarea']],
            ['settlement_amount', 'Settlement', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'png', 'docx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);
        $this->flow($form, ['finish' => ['process' => $finish, 'map' => ['contract_id' => 'contract_id', 'terminated_on' => 'terminated_on']]]);
        $terminate = $this->meta('cm_termination_from_contract_get', 'Termination of contract', 'get', null, 'A termination of the picked contract, today.', null, null,
            'SELECT c.id AS contract_id, :today AS terminated_on FROM cm_contracts c WHERE c.id = :id');
        $this->params($terminate, [['id', 'Contract', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);
        $grid = $this->meta('cm_terminations_grid', 'Terminations', 'datagrid', 'cm_terminations', 'Contracts ended early.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['termination_no', 'No', 'string', ['is_criteria' => true]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['terminated_on', 'Terminated', 'date'],
            ['initiated_by', 'By', 'string', ['is_criteria' => true]],
            ['reason_kind', 'Reason', 'string', ['is_criteria' => true]],
            ['reason', 'Details', 'string', ['is_label' => true]],
            ['settlement_amount', 'Settlement', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);
    }

    // ---------------------------------------------------------------- execution

    private function execution(): void
    {
        $contract = ['is_required' => true] + $this->lookup('cm_contracts_lookup', 'label');

        $line = $this->meta('cm_act_line_form', 'Completion act line', 'form', 'cm_completion_act_lines', 'What of a contract line was done and accepted.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['act_id', 'Act', 'integer', ['is_show' => false]],
            ['contract_line_id', 'Contract line', 'integer', $this->lookup('cm_contract_lines_grid', 'label', ['json_config' => ['fill' => [
                'item_id' => 'item_id', 'description' => 'description', 'unit_price' => 'unit_price', 'quantity' => 'remaining_qty',
            ]]])],
            ['item_id', 'Item / service / job', 'integer', $this->lookup($this->ext['items'], 'name', ['json_config' => ['fill' => ['description' => 'name', 'unit_price' => 'unit_price']]])],
            ['description', 'Description', 'string'],
            ['quantity', 'Qty done', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0']],
            ['unit_price', 'Unit price', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['line_total', 'Amount', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
        ]);
        $printLine = $this->meta('cm_act_line_print', 'Act line (print)', 'form', 'cm_completion_act_lines', 'The columns a printed completion act shows.');
        $this->params($printLine, [
            ['id', 'ID', 'integer', $this->key()],
            ['act_id', 'Act', 'integer', ['is_show' => false]],
            ['description', 'Work / supply', 'string'],
            ['quantity', 'Qty', 'decimal'],
            ['unit_price', 'Unit price', 'decimal', ['render_type' => 'money']],
            ['line_total', 'Amount', 'decimal', ['render_type' => 'money']],
        ]);
        $report = $this->meta('cm_act_report', 'Completion act', 'report', 'cm_completion_acts', 'A completion (acceptance) act to print and sign.', null, [
            'title' => 'Completion act',
            'number_field' => 'act_no',
            'issuer' => 'Blue Sky LLC',
            'note' => 'The parties confirm the work and supplies above were done as the contract requires.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['act_no', 'Act no', 'string'],
            ['contract_id', 'Contract', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['cm_contracts_lookup']->id, 'display_field' => 'label']],
            ['act_date', 'Date', 'date'],
            ['period_from', 'Period from', 'date'],
            ['period_to', 'Period to', 'date'],
            ['lines', 'Done and accepted', 'detail', ['ref_meta_id' => $printLine->id, 'json_config' => ['foreign_key' => 'act_id', 'amount' => ['line_total']]]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['total' => true]]],
            ['handed_over', 'Handed over by', 'signature'],
            ['accepted', 'Accepted by', 'signature'],
        ]);

        $copy = $this->stageQuery('cm_copy_act_lines', 'Fill act with what is left', 'cm_completion_act_lines', <<<'SQL'
            INSERT INTO cm_completion_act_lines (act_id, contract_line_id, item_id, description, quantity, unit_price, line_total, created_at, updated_at)
            SELECT :act_id, l.id, l.item_id, l.description, l.quantity - COALESCE(x.acted, 0), l.unit_price, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM cm_contract_lines l
            LEFT JOIN (SELECT al.contract_line_id, SUM(al.quantity) AS acted
                       FROM cm_completion_act_lines al
                       JOIN cm_completion_acts a ON a.id = al.act_id
                       WHERE a.status <> 'rejected' AND a.id <> :act_id
                       GROUP BY al.contract_line_id) x ON x.contract_line_id = l.id
            WHERE l.contract_id = :contract_id
              AND l.quantity - COALESCE(x.acted, 0) > 0
              AND NOT EXISTS (SELECT 1 FROM cm_completion_act_lines y WHERE y.act_id = :act_id)
            ORDER BY l.id;
            UPDATE cm_completion_acts
            SET total_amount = (SELECT COALESCE(SUM(y.line_total), 0) FROM cm_completion_act_lines y WHERE y.act_id = :act_id),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :act_id
            SQL, 'An act saved without lines gets every contract line still to do, with what is left of it.', [
            ['act_id', 'Act', 'integer', ['is_required' => true]],
            ['contract_id', 'Contract', 'integer', ['is_required' => true]],
        ]);

        $form = $this->meta('cm_act_form', 'Completion act', 'form', 'cm_completion_acts', 'What was done and accepted under a contract. Leave the lines empty to put everything still to do on it.', null, ['print_meta_id' => $report->id], null, [
            'var_fnc_expression_string' => <<<'JS'
                function lineTotal(l) {
                  return round(num(l.quantity) * num(l.unit_price), 2);
                }
                function refresh() {
                  lines('lines').forEach((l, i) => setLine('lines', i, 'line_total', lineTotal(l)));
                  set('total_amount', round(sum('lines', (l) => lineTotal(l)), 2));
                }
                JS,
            'load_expression_string' => "readonly('total_amount');\nrefresh();",
            'event_expression_string' => "if (detail === 'lines') refresh();",
            'before_save_expression_string' => <<<'JS'
                if (get('period_from') && get('period_to') && get('period_to') < get('period_from')) {
                  error('The period ends before it starts.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['act_no', 'Act no', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'ACT-{yy}{MM}{seq:4}']]]],
            ['contract_id', 'Contract', 'integer', $contract],
            ['act_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['period_from', 'Period from', 'date'],
            ['period_to', 'Period to', 'date'],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options(['draft', 'signed', 'rejected'], 'draft')],
            ['accepted_by', 'Accepted by', 'string'],
            ['lines', 'Done and accepted', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'act_id', 'amount' => ['quantity', 'unit_price'], 'total_field' => 'total_amount']]],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'png', 'docx', 'xlsx'], 'max_size' => 10240, 'max_files' => 10]]],
        ]);
        $this->flow($form, ['fill' => ['process' => $copy, 'map' => ['act_id' => 'id', 'contract_id' => 'contract_id']]]);
        $fromContract = $this->meta('cm_act_from_contract_get', 'Act of contract', 'get', null, 'A completion act of the picked contract, for the month so far.', null, null, <<<'SQL'
            SELECT c.id AS contract_id, :today AS act_date, DATE_FORMAT(:today, '%Y-%m-01') AS period_from, :today AS period_to, 'draft' AS status
            FROM cm_contracts c WHERE c.id = :id
            SQL);
        $this->params($fromContract, [['id', 'Contract', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);

        $grid = $this->meta('cm_acts_grid', 'Completion acts', 'datagrid', 'cm_completion_acts', 'Work and supplies done and accepted, act by act.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Print'],
        ]);
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['act_no', 'Act no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['act_date', 'Date', 'date'],
            ['period_from', 'From', 'date'],
            ['period_to', 'To', 'date'],
            ['total_amount', 'Total', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['draft', 'signed', 'rejected']]]],
            ['accepted_by', 'Accepted by', 'string'],
        ]);

        $form = $this->meta('cm_progress_report_form', 'Progress report', 'form', 'cm_progress_reports', 'How a contract is going: % done, health, work done, issues, next steps.');
        $health = ['on_track', 'at_risk', 'delayed'];
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', $contract],
            ['report_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['progress_pct', 'Done %', 'integer', ['min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['health', 'Health', 'string', ['is_required' => true] + $this->options($health, 'on_track')],
            ['work_done', 'Work done', 'text', ['is_label' => true, 'render_type' => 'textarea']],
            ['issues', 'Issues', 'text', ['render_type' => 'textarea']],
            ['next_steps', 'Next steps', 'text', ['render_type' => 'textarea']],
            ['owner_id', 'By', 'integer', ['default_value' => '{user_id}'] + $this->lookup('cm_users_lookup')],
            ['attachments', 'Photos & files', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'], 'max_size' => 10240, 'max_files' => 20]]],
        ]);
        $grid = $this->meta('cm_progress_reports_grid', 'Progress reports', 'datagrid', 'cm_progress_reports', 'How contracts are going, report by report.', null, ['filter_layout' => 'dropdown']);
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['report_date', 'Date', 'date'],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['progress_pct', 'Done %', 'integer'],
            ['health', 'Health', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $health]]],
            ['work_done', 'Work done', 'text', ['is_label' => true]],
            ['issues', 'Issues', 'text'],
            ['owner_id', 'By', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
        ]);

        $score = fn (string $label) => [$label, 'integer', ['min_value' => '1', 'max_value' => '5']];
        $form = $this->meta('cm_review_form', 'Performance review', 'form', 'cm_performance_reviews', 'How well a party performed: quality, timeliness, cost and communication (1-5); overall is their average.', null, null, null, [
            'load_expression_string' => "readonly('overall');",
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['contract_id', 'Contract', 'integer', $contract],
            ['party_id', 'Party', 'integer', ['is_required' => true] + $this->lookup('cm_parties_grid', 'label', ['json_config' => ['fill' => ['contract_id' => 'contract_id']]])],
            ['review_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['quality', ...$score('Quality')],
            ['timeliness', ...$score('Timeliness')],
            ['cost', ...$score('Cost')],
            ['communication', ...$score('Communication')],
            ['overall', 'Overall', 'decimal'],
            ['comments', 'Comments', 'text', ['is_label' => true, 'render_type' => 'textarea']],
            ['reviewer_id', 'Reviewer', 'integer', ['default_value' => '{user_id}'] + $this->lookup('cm_users_lookup')],
        ]);
        $grid = $this->meta('cm_reviews_grid', 'Performance reviews', 'datagrid', 'cm_performance_reviews', 'How parties performed.');
        $this->actions($grid, $form);
        $avg = ['json_config' => ['aggregate' => 'avg']];
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['review_date', 'Date', 'date'],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['party_id', 'Party', 'integer', ['is_criteria' => true] + $this->lookup('cm_parties_grid', 'label')],
            ['quality', 'Quality', 'integer', $avg],
            ['timeliness', 'Timeliness', 'integer', $avg],
            ['cost', 'Cost', 'integer', $avg],
            ['communication', 'Communication', 'integer', $avg],
            ['overall', 'Overall', 'decimal', $avg],
            ['comments', 'Comments', 'text', ['is_label' => true]],
            ['reviewer_id', 'Reviewer', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
        ]);
    }

    // ---------------------------------------------------------------- payments, balances, fines

    private function finance(): void
    {
        $contract = fn (array $fill = []) => ['is_required' => true] + $this->lookup('cm_contracts_lookup', 'label', $fill ? ['json_config' => ['fill' => $fill]] : []);
        $states = ['planned', 'due soon', 'overdue', 'paid'];

        $schedule = $this->meta('cm_payment_schedule_grid', 'Payment schedule', 'datagrid', 'cm_payment_schedules', 'Every planned payment: paid so far, still due, and whether it is overdue.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT s.id, s.contract_id, c.owner_id, s.milestone, s.kind, s.due_date, s.pct, s.amount,
                   COALESCE(p.paid, 0) AS paid_amount,
                   s.amount - COALESCE(p.paid, 0) AS balance,
                   CASE WHEN s.amount - COALESCE(p.paid, 0) <= 0 THEN 'paid'
                        WHEN s.due_date IS NULL THEN 'planned'
                        WHEN s.due_date < :today THEN 'overdue'
                        WHEN DATEDIFF(s.due_date, :today) <= 7 THEN 'due soon'
                        ELSE 'planned' END AS state,
                   CONCAT(c.contract_no, ' · ', s.milestone, ' · due ', FORMAT(s.amount - COALESCE(p.paid, 0), 0)) AS label
            FROM cm_payment_schedules s
            JOIN cm_contracts c ON c.id = s.contract_id
            LEFT JOIN (SELECT schedule_id, SUM(amount) AS paid FROM cm_payments WHERE schedule_id IS NOT NULL GROUP BY schedule_id) p ON p.schedule_id = s.id
            SQL);
        $this->params($schedule, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Milestone', 'string', ['is_label' => true, 'is_show' => false]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['milestone', 'Milestone', 'string', ['is_criteria' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['due_date', 'Due', 'date'],
            ['pct', '%', 'decimal'],
            ['amount', 'Planned', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['paid_amount', 'Paid', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['balance', 'Still due', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['state', 'State', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $states]]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
        ]);

        $form = $this->meta('cm_payment_form', 'Contract payment', 'form', 'cm_payments', 'A payment under a contract, against a milestone or a completion act.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['payment_no', 'Payment no', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'PAY-{yy}{MM}{seq:4}']]]],
            ['contract_id', 'Contract', 'integer', $contract()],
            ['schedule_id', 'Milestone', 'integer', $this->lookup($schedule, 'label', ['json_config' => ['fill' => ['contract_id' => 'contract_id', 'amount' => 'balance']]])],
            ['act_id', 'Completion act', 'integer', $this->lookup('cm_acts_grid', 'act_no', ['json_config' => ['fill' => ['contract_id' => 'contract_id']]])],
            ['paid_on', 'Paid on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['amount', 'Amount', 'decimal', ['is_required' => true, 'min_value' => '0.01', 'render_type' => 'money']],
            ['method', 'Method', 'string', ['is_required' => true] + $this->options(self::METHODS, 'bank transfer')],
            ['reference', 'Reference', 'string'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $fromContract = $this->meta('cm_payment_from_contract_get', 'Payment of contract', 'get', null, 'A payment of the picked contract, of its next milestone still due.', null, null, <<<'SQL'
            SELECT c.id AS contract_id, :today AS paid_on, 'bank transfer' AS method,
                   (SELECT s.id FROM cm_payment_schedules s
                    WHERE s.contract_id = c.id
                      AND s.amount > COALESCE((SELECT SUM(p.amount) FROM cm_payments p WHERE p.schedule_id = s.id), 0)
                    ORDER BY s.due_date IS NULL, s.due_date, s.id LIMIT 1) AS schedule_id
            FROM cm_contracts c WHERE c.id = :id
            SQL);
        $this->params($fromContract, [['id', 'Contract', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);
        $fromSchedule = $this->meta('cm_payment_from_schedule_get', 'Payment of milestone', 'get', null, 'A payment of what is still due on the picked milestone.', null, null, <<<'SQL'
            SELECT s.contract_id, s.id AS schedule_id, :today AS paid_on, 'bank transfer' AS method,
                   s.amount - COALESCE((SELECT SUM(p.amount) FROM cm_payments p WHERE p.schedule_id = s.id), 0) AS amount
            FROM cm_payment_schedules s WHERE s.id = :id
            SQL);
        $this->params($fromSchedule, [['id', 'Milestone', 'integer', ['is_criteria' => true, 'is_mandatory_criteria' => true]]]);
        $this->button($schedule, 'custom', 'Record payment', 'plus', $form, $fromSchedule, 1, ['id' => 'id']);

        $payments = $this->meta('cm_payments_grid', 'Payments', 'datagrid', 'cm_payments', 'Payments under contracts, with the period in the filters.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT p.id, p.payment_no, p.contract_id, p.schedule_id, p.act_id, p.paid_on, p.amount, p.method, p.reference
            FROM cm_payments p
            WHERE (:from_date IS NULL OR p.paid_on >= :from_date) AND (:to_date IS NULL OR p.paid_on <= :to_date)
            SQL);
        $this->actions($payments, $form);
        $this->params($payments, [
            ['id', 'ID', 'integer', $this->key()],
            ['payment_no', 'Payment no', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['paid_on', 'Paid on', 'date'],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['schedule_id', 'Milestone', 'integer', $this->lookup($schedule, 'label')],
            ['act_id', 'Act', 'integer', $this->lookup('cm_acts_grid', 'act_no')],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['method', 'Method', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::METHODS]]],
            ['reference', 'Reference', 'string'],
            ['from_date', 'Paid from', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'Paid to', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        // Fines under the penalty clauses; the amount is worked out as the fields change.
        $fine = $this->meta('cm_fine_form', 'Fine', 'form', 'cm_fines', 'A fine under a contract\'s penalty clause: per day late, a % once or a fixed sum, capped.', null, null, null, [
            'var_fnc_expression_string' => <<<'JS'
                // The fine by its basis: % per day late, % once, or a fixed sum; never above the cap.
                function fineAmount() {
                  const base = num(get('base_amount'));
                  const rate = num(get('rate'));
                  const basis = get('basis');
                  let amount = basis === 'per_day_pct' ? base * rate / 100 * num(get('days_late'))
                    : basis === 'pct' ? base * rate / 100 : rate;
                  const cap = num(get('cap_pct'));
                  if (cap > 0) amount = Math.min(amount, base * cap / 100);
                  return round(amount, 2);
                }
                function fit() {
                  hide('days_late', get('basis') !== 'per_day_pct');
                  hide('base_amount', get('basis') === 'fixed');
                }
                JS,
            'load_expression_string' => 'fit();',
            'event_expression_string' => <<<'JS'
                if (['basis', 'base_amount', 'rate', 'days_late', 'cap_pct', 'penalty_id', 'contract_id'].includes(field)) {
                  fit();
                  set('amount', fineAmount());
                }
                JS,
        ]);
        $fineStates = ['imposed', 'disputed', 'paid', 'waived'];
        $this->params($fine, [
            ['id', 'ID', 'integer', $this->key()],
            ['fine_no', 'Fine no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'FN-{yy}{MM}{seq:3}']]]],
            ['contract_id', 'Contract', 'integer', $contract(['base_amount' => 'total_value'])],
            ['penalty_id', 'Under clause', 'integer', $this->lookup('cm_penalties_lookup', 'label', ['json_config' => ['fill' => ['contract_id' => 'contract_id', 'basis' => 'basis', 'rate' => 'rate', 'cap_pct' => 'cap_pct']]])],
            ['party_id', 'On party', 'integer', $this->lookup('cm_parties_grid', 'label')],
            ['imposed_on', 'Imposed on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['reason', 'Reason', 'string', ['is_required' => true, 'is_label' => true]],
            ['basis', 'Basis', 'string', ['is_required' => true] + $this->options(self::BASES, 'per_day_pct')],
            ['base_amount', 'Of value', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
            ['days_late', 'Days late', 'integer', ['min_value' => '0', 'default_value' => '0']],
            ['rate', 'Rate (% or sum)', 'decimal', ['min_value' => '0', 'default_value' => '0']],
            ['cap_pct', 'Cap %', 'decimal', ['min_value' => '0', 'max_value' => '100']],
            ['amount', 'Fine', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money', 'default_value' => '0']],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options($fineStates, 'imposed')],
            ['paid_on', 'Paid on', 'date'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $grid = $this->meta('cm_fines_grid', 'Fines', 'datagrid', 'cm_fines', 'Fines imposed under contracts\' penalty clauses.', null, ['filter_layout' => 'dropdown']);
        $this->actions($grid, $fine);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['fine_no', 'No', 'string', ['is_criteria' => true]],
            ['imposed_on', 'Imposed', 'date'],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['party_id', 'On', 'integer', ['is_criteria' => true] + $this->lookup('cm_parties_grid', 'label')],
            ['reason', 'Reason', 'string', ['is_label' => true]],
            ['days_late', 'Days late', 'integer'],
            ['amount', 'Fine', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $fineStates]]],
            ['paid_on', 'Paid', 'date'],
        ]);

        // Contract by contract: value, done, paid, owed, fines and collateral held.
        $balances = $this->meta('cm_contract_balances', 'Contract balances', 'statement', 'cm_contracts', 'Each contract: value with amendments, done on signed acts, paid, done but unpaid, not yet done, fines and collateral held.', null, [
            'group_by' => ['direction'],
            'sort' => [['field' => 'contract_no', 'dir' => 'asc']],
        ], <<<'SQL'
            SELECT c.id, c.contract_no, c.title, c.direction, c.owner_id, c.status_id, c.end_date, c.currency,
                   c.total_amount + c.amended_amount AS total_value,
                   COALESCE(a.acted, 0) AS acted,
                   COALESCE(p.paid, 0) AS paid,
                   COALESCE(a.acted, 0) - COALESCE(p.paid, 0) AS acted_unpaid,
                   c.total_amount + c.amended_amount - COALESCE(a.acted, 0) AS not_done,
                   COALESCE(f.fines, 0) AS fines,
                   COALESCE(k.held, 0) AS collateral_held
            FROM cm_contracts c
            LEFT JOIN (SELECT contract_id, SUM(total_amount) AS acted FROM cm_completion_acts WHERE status = 'signed' GROUP BY contract_id) a ON a.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS paid FROM cm_payments GROUP BY contract_id) p ON p.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS fines FROM cm_fines WHERE status IN ('imposed', 'disputed', 'paid') GROUP BY contract_id) f ON f.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS held FROM cm_collaterals WHERE status = 'active' GROUP BY contract_id) k ON k.contract_id = c.id
            SQL);
        $money = fn () => ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']];
        $this->params($balances, [
            ['id', 'ID', 'integer', $this->key()],
            ['direction', 'Direction', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::DIRECTIONS]]],
            ['contract_no', 'Contract', 'string', ['is_criteria' => true]],
            ['title', 'Title', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
            ['end_date', 'Ends', 'date'],
            ['total_value', 'Value', 'decimal', $money()],
            ['acted', 'Done (acts)', 'decimal', $money()],
            ['paid', 'Paid', 'decimal', $money()],
            ['acted_unpaid', 'Done, unpaid', 'decimal', $money()],
            ['not_done', 'Not done yet', 'decimal', $money()],
            ['fines', 'Fines', 'decimal', $money()],
            ['collateral_held', 'Collateral held', 'decimal', $money()],
            ['currency', 'Currency', 'string'],
            ['status_id', 'Status', 'integer', ['is_criteria' => true, 'is_show' => false]],
        ]);
    }

    // ---------------------------------------------------------------- collaterals

    private function collaterals(): void
    {
        $states = ['active', 'released', 'called', 'expired'];
        $form = $this->meta('cm_collateral_form', 'Collateral', 'form', 'cm_collaterals', 'A guarantee, bond, deposit or pledge given under a contract, until it expires or is released.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['collateral_no', 'No', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'COL-{yy}{seq:4}']]]],
            ['contract_id', 'Contract', 'integer', ['is_required' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['party_id', 'Given by', 'integer', $this->lookup('cm_parties_grid', 'label', ['json_config' => ['fill' => ['contract_id' => 'contract_id']]])],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options(self::COLLATERALS, 'performance_bond')],
            ['issuer', 'Issuer (bank, insurer)', 'string', ['is_label' => true]],
            ['reference_no', 'Reference no', 'string'],
            ['amount', 'Amount', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
            ['currency', 'Currency', 'string', $this->options(self::CURRENCIES, 'MNT')],
            ['issued_on', 'Issued on', 'date'],
            ['expires_on', 'Expires on', 'date'],
            ['status', 'Status', 'string', ['is_required' => true] + $this->options($states, 'active')],
            ['released_on', 'Released on', 'date'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['attachments', 'Scans', 'multifile', ['json_config' => ['accept' => ['pdf', 'jpg', 'jpeg', 'png'], 'max_size' => 10240, 'max_files' => 5]]],
        ]);
        $release = $this->stageQuery('cm_release_collateral', 'Release collateral', 'cm_collaterals', <<<'SQL'
            UPDATE cm_collaterals SET status = 'released', released_on = :today, updated_at = CURRENT_TIMESTAMP
            WHERE id = :collateral_id AND status = 'active'
            SQL, 'Gives an active collateral back: released today.', [['collateral_id', 'Collateral', 'integer', ['is_required' => true]]]);

        $grid = $this->meta('cm_collaterals_grid', 'Collaterals', 'datagrid', 'cm_collaterals', 'Guarantees, bonds and deposits held, with how long until they expire.', null, ['filter_layout' => 'sidebar'], <<<'SQL'
            SELECT k.id, k.collateral_no, k.contract_id, k.party_id, k.kind, k.issuer, k.reference_no, k.amount, k.currency,
                   k.issued_on, k.expires_on, k.status, k.released_on, c.owner_id,
                   CASE WHEN k.status = 'active' AND k.expires_on IS NOT NULL THEN DATEDIFF(k.expires_on, :today) END AS days_left,
                   CASE WHEN k.status = 'active' AND k.expires_on IS NOT NULL AND DATEDIFF(k.expires_on, :today) <= 30 THEN 1 ELSE 0 END AS is_expiring
            FROM cm_collaterals k
            JOIN cm_contracts c ON c.id = k.contract_id
            SQL);
        $this->actions($grid, $form);
        $this->button($grid, 'stage_query', 'Release', 'check', $release, null, 4, ['collateral_id' => 'id']);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['collateral_no', 'No', 'string', ['is_criteria' => true]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['party_id', 'Given by', 'integer', $this->lookup('cm_parties_grid', 'label')],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::COLLATERALS]]],
            ['issuer', 'Issuer', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['currency', 'Currency', 'string'],
            ['expires_on', 'Expires', 'date'],
            ['days_left', 'Days left', 'integer'],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $states]]],
            ['is_expiring', 'Expiring (30 days)', 'boolean', ['is_criteria' => true, 'is_show' => false]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
        ]);
        $tab = fn (string $field, string $label, array $config) => [$field, $label, 'string', ['ref_meta_id' => $grid->id, 'json_config' => $config ?: null]];
        $this->params($this->meta('cm_collaterals_tabs', 'Collaterals', 'tabs', null, 'Collaterals held, expiring soon, released and called.'), [
            $tab('active', 'Held', ['filters' => ['status' => 'active']]),
            $tab('expiring', 'Expiring in 30 days', ['fixed' => ['is_expiring' => '1']]),
            $tab('released', 'Released', ['filters' => ['status' => 'released']]),
            $tab('called', 'Called', ['filters' => ['status' => 'called']]),
            $tab('all', 'All', []),
        ]);
    }

    // ---------------------------------------------------------------- daily alerts

    private function alerts(): void
    {
        $form = $this->meta('cm_alert_form', 'Contract alert', 'form', 'cm_alerts', 'A reminder sent to a contract\'s owner; saved each morning by the schedule.');
        // contract_id is a plain number: the alert's link needs the id, and a lookup would show its name.
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['kind', 'Kind', 'string', ['is_required' => true]],
            ['ref_key', 'Subject', 'string', ['is_required' => true, 'is_show' => false]],
            ['contract_id', 'Contract', 'integer', ['is_required' => true, 'is_show' => false]],
            ['owner_id', 'To', 'integer', $this->lookup('cm_users_lookup')],
            ['title', 'Alert', 'string', ['is_required' => true, 'is_label' => true]],
            ['due_on', 'Due', 'date'],
            ['alerted_on', 'Sent on', 'date', ['is_required' => true, 'default_value' => '{today}']],
        ]);
        $active = (string) $this->status['active'];
        $views = [
            'cm_alerts_contracts_due' => ['Contracts ending soon', 'Active contracts ending in 30, 14, 7 or 1 days, or today.', <<<SQL
                SELECT c.id, 'contract_ending' AS kind, CONCAT('contract-', c.id) AS ref_key, c.id AS contract_id, c.owner_id,
                       CASE WHEN c.end_date = :today THEN CONCAT('Contract ', c.contract_no, ' ends today')
                            ELSE CONCAT('Contract ', c.contract_no, ' ends in ', DATEDIFF(c.end_date, :today), ' days') END AS title,
                       c.end_date AS due_on, :today AS alerted_on
                FROM cm_contracts c
                WHERE c.status_id = {$active} AND c.owner_id IS NOT NULL AND c.end_date IS NOT NULL
                  AND DATEDIFF(c.end_date, :today) IN (30, 14, 7, 1, 0)
                  AND NOT EXISTS (SELECT 1 FROM cm_alerts x WHERE x.ref_key = CONCAT('contract-', c.id) AND x.alerted_on = :today)
                SQL],
            'cm_alerts_collaterals_due' => ['Collaterals expiring soon', 'Active collaterals expiring in 30, 14, 7 or 1 days, or today.', <<<'SQL'
                SELECT k.id, 'collateral_expiring' AS kind, CONCAT('collateral-', k.id) AS ref_key, k.contract_id, c.owner_id,
                       CONCAT('Collateral ', k.collateral_no, ' of ', c.contract_no,
                              CASE WHEN k.expires_on = :today THEN ' expires today' ELSE CONCAT(' expires in ', DATEDIFF(k.expires_on, :today), ' days') END) AS title,
                       k.expires_on AS due_on, :today AS alerted_on
                FROM cm_collaterals k
                JOIN cm_contracts c ON c.id = k.contract_id
                WHERE k.status = 'active' AND c.owner_id IS NOT NULL AND k.expires_on IS NOT NULL
                  AND DATEDIFF(k.expires_on, :today) IN (30, 14, 7, 1, 0)
                  AND NOT EXISTS (SELECT 1 FROM cm_alerts x WHERE x.ref_key = CONCAT('collateral-', k.id) AND x.alerted_on = :today)
                SQL],
            'cm_alerts_payments_due' => ['Payments due', 'Milestones still unpaid that are due in 7 or 1 days or today, or 1, 7 or 30 days overdue.', <<<'SQL'
                SELECT s.id, 'payment_due' AS kind, CONCAT('payment-', s.id) AS ref_key, s.contract_id, c.owner_id,
                       CONCAT(c.contract_no, ': ', s.milestone,
                              CASE WHEN s.due_date >= :today THEN CONCAT(' due ', DATE_FORMAT(s.due_date, '%Y-%m-%d'))
                                   ELSE CONCAT(' overdue by ', DATEDIFF(:today, s.due_date), ' days') END,
                              ', ', FORMAT(s.amount - COALESCE((SELECT SUM(p.amount) FROM cm_payments p WHERE p.schedule_id = s.id), 0), 0), ' ', c.currency) AS title,
                       s.due_date AS due_on, :today AS alerted_on
                FROM cm_payment_schedules s
                JOIN cm_contracts c ON c.id = s.contract_id
                WHERE c.owner_id IS NOT NULL AND s.due_date IS NOT NULL
                  AND s.amount > COALESCE((SELECT SUM(p.amount) FROM cm_payments p WHERE p.schedule_id = s.id), 0)
                  AND DATEDIFF(s.due_date, :today) IN (7, 1, 0, -1, -7, -30)
                  AND NOT EXISTS (SELECT 1 FROM cm_alerts x WHERE x.ref_key = CONCAT('payment-', s.id) AND x.alerted_on = :today)
                SQL],
        ];
        $schedule = Schedule::firstOrCreate(['code' => '0 7 * * *'], ['name' => 'Every day at 07:00']);
        foreach ($views as $code => [$name, $description, $sql]) {
            $view = $this->meta($code, $name, 'datagrid', null, $description.' The daily schedule sends an alert for each.', null, null, $sql);
            $this->params($view, [
                ['id', 'ID', 'integer', $this->key()],
                ['title', 'Alert', 'string', ['is_label' => true]],
                ['contract_id', 'Contract', 'integer', $this->lookup('cm_contracts_lookup', 'label')],
                ['owner_id', 'To', 'integer', $this->lookup('cm_users_lookup')],
                ['due_on', 'Due', 'date'],
                ['kind', 'Kind', 'string', ['is_show' => false]],
                ['ref_key', 'Subject', 'string', ['is_show' => false]],
                ['alerted_on', 'Send on', 'date', ['is_show' => false]],
            ]);
            MetaSchedule::firstOrCreate(['schedule_id' => $schedule->id, 'process_id' => $form->id, 'view_id' => $view->id]);
        }

        $log = $this->meta('cm_alerts_grid', 'Alerts sent', 'datagrid', 'cm_alerts', 'The reminders sent about contracts, collaterals and payments.', null, ['filter_layout' => 'dropdown']);
        $kinds = ['contract_ending', 'collateral_expiring', 'payment_due'];
        $this->params($log, [
            ['id', 'ID', 'integer', $this->key()],
            ['alerted_on', 'Sent on', 'date', ['is_criteria' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => $kinds]]],
            ['title', 'Alert', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['contract_id', 'Contract', 'integer', ['is_criteria' => true] + $this->lookup('cm_contracts_lookup', 'label')],
            ['owner_id', 'To', 'integer', ['is_criteria' => true] + $this->lookup('cm_users_lookup')],
            ['due_on', 'Due', 'date'],
        ]);
    }

    // ---------------------------------------------------------------- notifications (links to the workspace come in workspace())

    private function notifications(): void
    {
        $workflow = $this->m['cm_contract_workflow'];
        $grid = $this->m['cm_contracts_grid'];
        $this->notification('cm_notify_contract_review', 'Contract to review', 'Tells the contract team when a contract is sent for review.', [
            'event' => 'status_changed', 'workflow_meta_id' => $workflow->id, 'status_id' => $this->status['review'],
            'kind' => 'info', 'title' => 'Contract {contract_no} is waiting for review',
            'body' => '{title}: {total_amount} {currency}. Sent by {actor}.',
            'link_meta_id' => $grid->id, 'link_record' => true,
        ], [['role', 'contracts']]);
        $this->notification('cm_notify_contract_active', 'Contract signed', 'Tells the owner when their contract is signed and active.', [
            'event' => 'status_changed', 'workflow_meta_id' => $workflow->id, 'status_id' => $this->status['active'],
            'kind' => 'success', 'title' => 'Contract {contract_no} is signed and active',
            'body' => '{title}, {start_date} to {end_date}.',
            'link_meta_id' => $grid->id, 'link_record' => true,
        ], [['field', 'owner_id']]);
        $this->notification('cm_notify_contract_terminated', 'Contract terminated', 'Tells the owner when their contract is terminated.', [
            'event' => 'created', 'source_meta_id' => $this->m['cm_termination_form']->id,
            'kind' => 'danger', 'title' => 'Contract terminated: {contract_id}',
            'body' => 'Terminated on {terminated_on} ({reason_kind}): {reason}',
            'link_meta_id' => $this->m['cm_terminations_grid']->id, 'link_record' => true,
        ], [['role', 'contracts']]);
    }

    // ---------------------------------------------------------------- the contract workspace

    private function workspace(): void
    {
        $s = $this->status;
        $summary = $this->meta('cm_contract_summary', 'Contract summary', 'datagrid', null, 'One contract at a glance: value, done, paid, owed, fines, collateral and time left.', null, null, <<<'SQL'
            SELECT c.id AS contract_id,
                   c.total_amount AS signed_value, c.amended_amount, c.total_amount + c.amended_amount AS total_value,
                   COALESCE(a.acted, 0) AS acted,
                   CASE WHEN c.total_amount + c.amended_amount > 0 THEN ROUND(100 * COALESCE(a.acted, 0) / (c.total_amount + c.amended_amount), 1) END AS done_pct,
                   COALESCE(p.paid, 0) AS paid,
                   COALESCE(a.acted, 0) - COALESCE(p.paid, 0) AS acted_unpaid,
                   COALESCE(od.overdue, 0) AS overdue_payments,
                   COALESCE(f.fines, 0) AS fines,
                   COALESCE(k.held, 0) AS collateral_held,
                   (SELECT COUNT(*) FROM cm_contract_parties x WHERE x.contract_id = c.id) AS parties,
                   (SELECT COUNT(*) FROM cm_extensions x WHERE x.contract_id = c.id AND x.status = 'approved') AS amendments,
                   CASE WHEN c.end_date IS NOT NULL THEN DATEDIFF(c.end_date, :today) END AS days_left,
                   (SELECT r.progress_pct FROM cm_progress_reports r WHERE r.contract_id = c.id ORDER BY r.report_date DESC, r.id DESC LIMIT 1) AS reported_pct,
                   (SELECT r.health FROM cm_progress_reports r WHERE r.contract_id = c.id ORDER BY r.report_date DESC, r.id DESC LIMIT 1) AS health,
                   (SELECT ROUND(AVG(v.overall), 1) FROM cm_performance_reviews v WHERE v.contract_id = c.id) AS rating
            FROM cm_contracts c
            LEFT JOIN (SELECT contract_id, SUM(total_amount) AS acted FROM cm_completion_acts WHERE status = 'signed' AND contract_id = :contract_id GROUP BY contract_id) a ON a.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS paid FROM cm_payments WHERE contract_id = :contract_id GROUP BY contract_id) p ON p.contract_id = c.id
            LEFT JOIN (SELECT s.contract_id, SUM(s.amount - COALESCE((SELECT SUM(q.amount) FROM cm_payments q WHERE q.schedule_id = s.id), 0)) AS overdue
                       FROM cm_payment_schedules s
                       WHERE s.contract_id = :contract_id AND s.due_date < :today
                         AND s.amount > COALESCE((SELECT SUM(q.amount) FROM cm_payments q WHERE q.schedule_id = s.id), 0)
                       GROUP BY s.contract_id) od ON od.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS fines FROM cm_fines WHERE contract_id = :contract_id AND status IN ('imposed', 'disputed', 'paid') GROUP BY contract_id) f ON f.contract_id = c.id
            LEFT JOIN (SELECT contract_id, SUM(amount) AS held FROM cm_collaterals WHERE contract_id = :contract_id AND status = 'active' GROUP BY contract_id) k ON k.contract_id = c.id
            WHERE c.id = :contract_id
            SQL);
        $money = ['render_type' => 'money'];
        $this->params($summary, [
            ['contract_id', 'Contract', 'integer', ['is_primary' => true, 'is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
            ['signed_value', 'Signed value', 'decimal', $money],
            ['amended_amount', 'Amendments', 'decimal', $money],
            ['total_value', 'Value now', 'decimal', $money],
            ['acted', 'Done (signed acts)', 'decimal', $money],
            ['done_pct', 'Done %', 'decimal'],
            ['paid', 'Paid', 'decimal', $money],
            ['acted_unpaid', 'Done, unpaid', 'decimal', $money],
            ['overdue_payments', 'Overdue payments', 'decimal', $money],
            ['fines', 'Fines', 'decimal', $money],
            ['collateral_held', 'Collateral held', 'decimal', $money],
            ['parties', 'Parties', 'integer'],
            ['amendments', 'Amendments made', 'integer'],
            ['days_left', 'Days left', 'integer'],
            ['reported_pct', 'Reported %', 'integer'],
            ['health', 'Health', 'string', ['render_type' => 'status']],
            ['rating', 'Rating (1-5)', 'decimal'],
        ]);

        $workspace = $this->meta('cm_contract_workspace', 'Contract workspace', 'workspace', 'cm_contracts', 'One contract and everything about it: parties, lines and execution, terms, payments, acts, collaterals, changes and fines.', $this->m['cm_contract_form'], [
            'header' => ['contract_no', 'contract_type_id', 'direction', 'owner_id', 'start_date', 'end_date', 'total_amount', 'currency'],
            'title_field' => 'title',
        ]);
        $scope = ['scope' => ['contract_id' => 'id']];
        $sections = [
            ['summary', 'Summary', 'cm_contract_summary'],
            ['parties', 'Parties', 'cm_parties_grid'],
            ['lines', 'Lines & execution', 'cm_contract_lines_grid'],
            ['terms', 'Terms', 'cm_terms_grid'],
            ['schedule', 'Payment schedule', 'cm_payment_schedule_grid'],
            ['payments', 'Payments', 'cm_payments_grid'],
            ['acts', 'Completion acts', 'cm_acts_grid'],
            ['progress', 'Progress reports', 'cm_progress_reports_grid'],
            ['reviews', 'Performance', 'cm_reviews_grid'],
            ['collaterals', 'Collaterals', 'cm_collaterals_grid'],
            ['extensions', 'Extensions & amendments', 'cm_extensions_grid'],
            ['fines', 'Fines', 'cm_fines_grid'],
            ['termination', 'Termination', 'cm_terminations_grid'],
        ];
        $rows = array_map(fn ($x) => [$x[0], $x[1], 'string', ['ref_meta_id' => $this->m[$x[2]]->id, 'json_config' => $scope]], $sections);
        // The printed contract, of this record.
        $rows[] = ['document', 'Contract document', 'string', ['ref_meta_id' => $this->m['cm_contract_report']->id]];
        $this->params($workspace, $rows);

        // Rows of the registers open the workspace; the contracts grid gets its buttons.
        $grid = $this->m['cm_contracts_grid'];
        $grid->update(['json_config' => ['filter_layout' => 'sidebar', 'drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace']]]);
        $this->m['cm_contract_balances']->update(['json_config' => [
            'group_by' => ['direction'], 'sort' => [['field' => 'contract_no', 'dir' => 'asc']],
            'drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace'],
        ]]);
        $this->button($grid, 'custom', 'Extend / amend', 'plus', $this->m['cm_extension_form'], $this->m['cm_extension_from_contract_get'], 4, ['id' => 'id']);
        $this->button($grid, 'custom', 'Completion act', 'check', $this->m['cm_act_form'], $this->m['cm_act_from_contract_get'], 5, ['id' => 'id']);
        $this->button($grid, 'custom', 'Record payment', 'plus', $this->m['cm_payment_form'], $this->m['cm_payment_from_contract_get'], 6, ['id' => 'id']);
        $this->button($grid, 'custom', 'Terminate', 'file', $this->m['cm_termination_form'], $this->m['cm_termination_from_contract_get'], 7, ['id' => 'id']);

        // Alerts open the contract's workspace.
        $this->notification('cm_notify_alert', 'Contract alert', 'Tells the owner each morning about contracts ending, collaterals expiring and payments due.', [
            'event' => 'created', 'source_meta_id' => $this->m['cm_alert_form']->id,
            'kind' => 'alert', 'title' => '{title}', 'body' => 'Due {due_on}.',
            'link_url' => "/run/{$workspace->id}?record={contract_id}",
            'exclude_actor' => false,
        ], [['field', 'owner_id']]);

        // The timeline: each contract's period, coloured by status, its bar filled by what is done.
        $timeline = $this->meta('cm_contracts_timeline', 'Contract timeline', 'gantt', 'cm_contracts', 'Contracts on a timeline, coloured by status; the bar fills with what is done on signed acts.', $this->m['cm_contract_form'], [
            'start_field' => 'start_date', 'end_field' => 'end_date', 'title_field' => 'name', 'progress_field' => 'progress',
            'group_field' => 'type_name', 'detail_field' => 'owner_name', 'color_field' => 'status_code', 'item_label' => 'contract',
            'colors' => ['draft' => 'gray', 'review' => 'amber', 'approved' => 'purple', 'active' => 'blue', 'suspended' => 'orange', 'completed' => 'green', 'expired' => 'gray', 'terminated' => 'red'],
        ], <<<'SQL'
            SELECT c.id, CONCAT(c.contract_no, ' ', c.title) AS name, c.start_date, c.end_date,
                   COALESCE(t.name, 'No type') AS type_name, u.name AS owner_name, st.code AS status_code, c.owner_id,
                   CASE WHEN c.total_amount + c.amended_amount > 0
                        THEN LEAST(100, ROUND(100 * COALESCE((SELECT SUM(a.total_amount) FROM cm_completion_acts a WHERE a.contract_id = c.id AND a.status = 'signed'), 0) / (c.total_amount + c.amended_amount)))
                        ELSE 0 END AS progress
            FROM cm_contracts c
            LEFT JOIN cm_contract_types t ON t.id = c.contract_type_id
            LEFT JOIN users u ON u.id = c.owner_id
            LEFT JOIN wfm_status st ON st.id = c.status_id
            WHERE c.start_date IS NOT NULL AND c.end_date IS NOT NULL
            SQL);
        $this->params($timeline, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Contract', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['progress', 'Done %', 'integer'],
            ['type_name', 'Type', 'string', ['is_criteria' => true]],
            ['owner_name', 'Owner', 'string'],
            ['status_code', 'Status', 'string', ['is_criteria' => true, 'json_config' => ['options' => array_keys($s)]]],
            ['owner_id', 'Owner', 'integer', ['is_criteria' => true, 'is_show' => false] + $this->lookup('cm_users_lookup')],
        ]);
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): void
    {
        $active = (string) $this->status['active'];
        $toGrid = fn (string $grid, array $params = []) => ['drilldown' => ['meta_id' => $this->m[$grid]->id, 'params' => $params]];
        $scheduleSql = <<<'SQL'
            SELECT s.id, s.due_date, s.amount - COALESCE((SELECT SUM(p.amount) FROM cm_payments p WHERE p.schedule_id = s.id), 0) AS balance
            FROM cm_payment_schedules s
            WHERE s.due_date < :today
            SQL;
        $charts = [
            [$this->chart('cm_chart_active_contracts', 'Active contracts', null, "SELECT id FROM cm_contracts WHERE status_id = {$active}", 'Contracts being performed now.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Contracts']]] + $toGrid('cm_contracts_grid')), 3, 1],
            [$this->chart('cm_chart_active_value', 'Active value', null, "SELECT total_amount + amended_amount AS value FROM cm_contracts WHERE status_id = {$active}", 'What the active contracts are worth, with amendments.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'value', 'label' => 'Value']], 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_expiring', 'Ending in 30 days', null, "SELECT id FROM cm_contracts WHERE status_id = {$active} AND end_date BETWEEN :today AND DATE_ADD(:today, INTERVAL 30 DAY)", 'Active contracts ending within a month.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Contracts']]]), 3, 1],
            [$this->chart('cm_chart_collateral_held', 'Collateral held', 'cm_collaterals', null, 'Guarantees and deposits held now.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Held']], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'active']], 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_acted', 'Done (acts)', 'cm_completion_acts', null, 'Signed completion acts in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'total_amount', 'label' => 'Done']], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'signed']], 'date_field' => 'act_date', 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_paid', 'Paid', 'cm_payments', null, 'Payments under contracts in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Paid']], 'date_field' => 'paid_on', 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_fines', 'Fines', 'cm_fines', null, 'Fines imposed in the period (not waived).',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Fines']], 'filters' => [['column' => 'status', 'op' => '!=', 'value' => 'waived']], 'date_field' => 'imposed_on', 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_overdue_payments', 'Overdue payments', null, $scheduleSql, 'Milestones past due and still unpaid.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'balance', 'label' => 'Overdue']], 'filters' => [['column' => 'balance', 'op' => '>', 'value' => '0']], 'format' => 'money']), 3, 1],
            [$this->chart('cm_chart_by_status', 'Contracts by status', null, <<<'SQL'
                SELECT COALESCE(st.name, 'No status') AS status, c.total_amount + c.amended_amount AS value
                FROM cm_contracts c LEFT JOIN wfm_status st ON st.id = c.status_id
                SQL, 'How many contracts stand where.',
                ['type' => 'donut', 'x' => 'status', 'measures' => [['agg' => 'count', 'label' => 'Contracts']]]), 4, 2],
            [$this->chart('cm_chart_value_by_type', 'Value by type', null, <<<'SQL'
                SELECT COALESCE(t.name, 'No type') AS type, c.total_amount + c.amended_amount AS value, c.direction
                FROM cm_contracts c LEFT JOIN cm_contract_types t ON t.id = c.contract_type_id
                SQL, 'What contracts of each type are worth, by direction.',
                ['type' => 'column', 'x' => 'type', 'split' => 'direction', 'sort' => 'value', 'measures' => [['agg' => 'sum', 'column' => 'value', 'label' => 'Value']], 'format' => 'money']), 8, 2],
            [$this->chart('cm_chart_acted_vs_paid', 'Done vs paid by month', null, <<<'SQL'
                SELECT act_date AS day, total_amount AS acted, 0 AS paid FROM cm_completion_acts WHERE status = 'signed'
                UNION ALL
                SELECT paid_on, 0, amount FROM cm_payments
                SQL, 'Signed acts and payments, month by month.',
                ['type' => 'column', 'x' => 'day', 'bucket' => 'month', 'date_field' => 'day', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'acted', 'label' => 'Done'],
                    ['agg' => 'sum', 'column' => 'paid', 'label' => 'Paid'],
                ]]), 8, 2],
            [$this->chart('cm_chart_collaterals_by_kind', 'Collateral held by kind', 'cm_collaterals', null, 'Guarantees, bonds and deposits held, by kind.',
                ['type' => 'donut', 'x' => 'kind', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Held']], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'active']], 'format' => 'money']), 4, 2],
            [$this->chart('cm_chart_top_contractors', 'Top contractors', null, <<<'SQL'
                SELECT p.name AS contractor, c.total_amount + c.amended_amount AS value, c.id AS contract_id, c.start_date
                FROM cm_contract_parties p JOIN cm_contracts c ON c.id = p.contract_id
                WHERE p.role IN ('contractor', 'consortium member')
                SQL, 'Contractors with the most contract value.',
                ['type' => 'table', 'x' => 'contractor', 'limit' => 10, 'format' => 'money', 'date_field' => 'start_date', 'measures' => [
                    ['agg' => 'sum', 'column' => 'value', 'label' => 'Value'],
                    ['agg' => 'count', 'label' => 'Contracts'],
                ]]), 6, 2],
            [$this->chart('cm_chart_contractor_rating', 'Contractor performance', null, <<<'SQL'
                SELECT p.name AS party, v.overall, v.quality, v.timeliness, v.review_date
                FROM cm_performance_reviews v JOIN cm_contract_parties p ON p.id = v.party_id
                SQL, 'Average review scores by party (1-5).',
                ['type' => 'table', 'x' => 'party', 'limit' => 10, 'date_field' => 'review_date', 'measures' => [
                    ['agg' => 'avg', 'column' => 'overall', 'label' => 'Overall'],
                    ['agg' => 'avg', 'column' => 'quality', 'label' => 'Quality'],
                    ['agg' => 'avg', 'column' => 'timeliness', 'label' => 'Timeliness'],
                    ['agg' => 'count', 'label' => 'Reviews'],
                ]]), 6, 2],
        ];

        $dashboard = $this->meta('cm_dashboard', 'Contracts dashboard', 'dashboard', null, 'Contracts, execution, payments, collaterals and fines at a glance.');
        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'cm_chart_');
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

        $app = $this->meta('cm_app', 'Contracts', 'app', null, 'Contract management: drafts, contracts and parties, changes, execution, payments, collaterals and fines.');
        $this->params($app, [
            $menu('dashboard', 'Dashboard', 'cm_dashboard', [], 'chart'),
            $group('contracts', 'Contracts', 'file', [
                $menu('drafts', 'Drafts', 'cm_contracts_grid', ['filters' => ['status_id' => (string) $this->status['draft']]]),
                $menu('contracts', 'Contracts', 'cm_contracts_tabs'),
                $menu('workspace', 'Contract workspace', 'cm_contract_workspace'),
                $menu('timeline', 'Timeline', 'cm_contracts_timeline'),
                $menu('parties', 'Parties', 'cm_parties_grid'),
                $menu('lines', 'Lines & execution', 'cm_contract_lines_grid'),
            ]),
            $group('changes', 'Changes', 'zap', [
                $menu('extensions', 'Extensions & amendments', 'cm_extensions_grid'),
                $menu('terminations', 'Terminations', 'cm_terminations_grid'),
            ]),
            $group('execution', 'Execution', 'tool', [
                $menu('acts', 'Completion acts', 'cm_acts_grid'),
                $menu('progress', 'Progress reports', 'cm_progress_reports_grid'),
                $menu('reviews', 'Performance reviews', 'cm_reviews_grid'),
            ]),
            $group('finance', 'Finance', 'receipt', [
                $menu('schedule', 'Payment schedule', 'cm_payment_schedule_grid'),
                $menu('payments', 'Payments', 'cm_payments_grid'),
                $menu('balances', 'Contract balances', 'cm_contract_balances'),
                $menu('fines', 'Fines', 'cm_fines_grid'),
            ]),
            $menu('collaterals', 'Collaterals', 'cm_collaterals_tabs', [], 'database'),
            $group('library', 'Library', 'folder', [
                $menu('types', 'Contract types', 'cm_contract_types_grid'),
                $menu('terms', 'Standard terms', 'cm_term_templates_grid'),
                $menu('penalties', 'Penalty types', 'cm_penalty_types_grid'),
            ]),
            $menu('alerts', 'Alerts sent', 'cm_alerts_grid', [], 'calendar'),
        ]);
    }

    /** The "contracts" role: view on every contract meta, full use of its forms, running its stage queries; view on the reused lookups. */
    private function access(): void
    {
        $role = Role::updateOrCreate(['code' => 'contracts'], ['name' => 'Contract manager', 'description' => 'Drafts and manages contracts: parties, terms, changes, execution, payments, collaterals and fines.']);
        $tag = Tag::firstOrCreate(['name' => 'contracts'], ['color' => 'indigo']);
        $types = array_flip($this->types);
        $grant = function (Meta $meta) use ($role, $types) {
            $type = $types[$meta->type_id] ?? null;
            $full = $type === 'form';
            MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], [
                'can_view' => true, 'can_create' => $full, 'can_edit' => $full || $type === 'stage_query', 'can_delete' => $full,
            ]);
        };
        $ids = [];
        foreach ($this->m as $meta) {
            $grant($meta);
            $ids[] = $meta->id;
        }
        foreach (['crm_customers_grid', 'proc_suppliers_grid', 'items', 'crm_item_categories_grid'] as $code) {
            if (isset($this->ext[$code])) {
                MetaPermission::firstOrCreate(['meta_id' => $this->ext[$code]->id, 'role_id' => $role->id], ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false]);
            }
        }
        DB::table('meta_tag_map')->where('tag_id', $tag->id)->whereNotIn('meta_id', $ids)->delete();
        $tagged = DB::table('meta_tag_map')->where('tag_id', $tag->id)->pluck('meta_id')->all();
        $now = now();
        foreach (array_diff($ids, $tagged) as $id) {
            DB::table('meta_tag_map')->insert(['meta_id' => $id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000001)

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
