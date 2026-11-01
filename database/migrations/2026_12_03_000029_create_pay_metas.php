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
 * The payroll app, built only from metas (tables and rules: 2026_12_03_000027 / 000028):
 *
 *   Payroll (app pay_app)
 *   ├─ Payroll runs    one a month; saving a new one makes its slips; Approve, Paid, Reopen, Void
 *   ├─ Payslips        adjust days, one-off items; print
 *   ├─ Bank list       what to pay whom, from approved and paid runs
 *   ├─ Employees       salary, bank, recurring pay items, linked login
 *   ├─ Dashboard
 *   ├─ Setup           departments, pay items, rates
 *   └─ My payslips     for the signed-in employee only
 *
 * Who sees what is the point of this app. pay_employee reaches only metas whose query is kept to
 * :user_id, including the line meta of its report: a plain line form would list everyone's lines
 * to anyone who may view it. pay_officer prepares; only pay_manager approves, reopens, pays, voids.
 *
 * Idempotent: metas matched by code, their params, actions and flows rebuilt. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const RUN_STATUSES = ['draft', 'approved', 'paid', 'void'];

    /** @var array<string, int> */
    private array $types = [];

    /** @var array<string, Meta> the metas made here, by code */
    private array $m = [];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('pay_runs')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        if (! $this->types && ! DB::pretending()) {
            return;
        }

        DB::transaction(function () {
            $this->setup();
            $this->employees();
            $this->stageQueries();
            $this->runs();
            $this->slips();
            $this->selfService();
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
            $ids = DB::table('metas')->where('code', 'like', 'pay\_%')->pluck('id');
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->whereNotIn('main_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('ref_meta_id', $ids)->whereNotIn('id', $ids)->update(['ref_meta_id' => null]);
            DB::table('metas')->whereIn('id', $ids)->delete();
            DB::table('roles')->whereIn('code', ['pay_employee', 'pay_officer', 'pay_manager'])->delete();
            DB::table('tags')->where('name', 'payroll')->delete();
            DB::table('autonumbers')->where('table_name', 'like', 'pay\_%')->delete();
        });
    }

    // ---------------------------------------------------------------- departments, pay items, rates, pick lists

    private function setup(): void
    {
        $form = $this->meta('pay_department_form', 'Department form', 'form', 'pay_departments', 'A department of the company.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ]);
        $grid = $this->meta('pay_departments_grid', 'Departments', 'datagrid', 'pay_departments', 'The departments of the company.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['is_active', 'Active', 'boolean'],
        ]);

        $kinds = ['earning', 'deduction'];
        $form = $this->meta('pay_component_form', 'Pay item form', 'form', 'pay_components', 'Something added to pay or taken from it. Earnings say whether insurance and income tax apply; deductions come off net pay.');
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $this->options($kinds, 'earning')],
            ['default_amount', 'Usual amount', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['is_taxable', 'Income tax applies', 'boolean', ['default_value' => '1']],
            ['is_insurable', 'Social insurance applies', 'boolean', ['default_value' => '1']],
            ['order_number', 'Order', 'integer', ['min_value' => '1', 'default_value' => '1']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ]);
        $grid = $this->meta('pay_components_grid', 'Pay items', 'datagrid', 'pay_components', 'What can appear on a payslip besides base pay.');
        $this->actions($grid, $form);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true, 'json_config' => ['options' => $kinds]]],
            ['default_amount', 'Usual amount', 'decimal', ['render_type' => 'money']],
            ['is_taxable', 'Income tax', 'boolean'],
            ['is_insurable', 'Insurance', 'boolean'],
            ['is_active', 'Active', 'boolean'],
        ]);
        $lookup = $this->meta('pay_components_lookup', 'Pay items (pick list)', 'datagrid', 'pay_components', 'The active pay items.', null, null,
            'SELECT id, name, kind, default_amount FROM pay_components WHERE is_active = 1');
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string'],
            ['default_amount', 'Usual amount', 'decimal', ['render_type' => 'money']],
        ]);

        $form = $this->meta('pay_rate_form', 'Rates form', 'form', 'pay_rates', 'The insurance and tax rates from a date on. A new run takes the latest rates in force for its month; add a row when the law changes, old runs keep theirs.', null, null, null, ['is_log' => true]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['effective_from', 'In force from', 'date', ['is_required' => true]],
            ['si_employee_pct', 'Social insurance, employee %', 'decimal', ['is_required' => true, 'min_value' => '0', 'max_value' => '100']],
            ['si_employer_pct', 'Social insurance, employer %', 'decimal', ['is_required' => true, 'min_value' => '0', 'max_value' => '100']],
            ['si_monthly_cap', 'Insurable pay cap a month (empty: none)', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['pit_pct', 'Income tax %', 'decimal', ['is_required' => true, 'min_value' => '0', 'max_value' => '100']],
            ['monthly_tax_credit', 'Tax credit a month', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
        ]);
        $grid = $this->meta('pay_rates_grid', 'Rates', 'datagrid', 'pay_rates', 'Insurance and income tax rates over time.');
        $this->actions($grid, $form, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['code', 'Code', 'string'],
            ['name', 'Name', 'string', ['is_label' => true]],
            ['effective_from', 'In force from', 'date'],
            ['si_employee_pct', 'Insurance, employee %', 'decimal'],
            ['si_employer_pct', 'Insurance, employer %', 'decimal'],
            ['si_monthly_cap', 'Cap a month', 'decimal', ['render_type' => 'money']],
            ['pit_pct', 'Income tax %', 'decimal'],
            ['monthly_tax_credit', 'Tax credit', 'decimal', ['render_type' => 'money']],
        ]);

        $lookup = $this->meta('pay_users_lookup', 'Logins (pick list)', 'datagrid', 'users', 'The logins an employee can be linked to.', null, null, 'SELECT id, name, email FROM users');
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
        ]);

        $lookup = $this->meta('pay_runs_lookup', 'Payroll runs (pick list)', 'datagrid', 'pay_runs', 'Runs by number and month; no amounts.', null, null, <<<'SQL'
            SELECT r.id, r.run_no, r.period_start, r.status, CONCAT(r.run_no, ' · ', DATE_FORMAT(r.period_start, '%Y-%m')) AS label
            FROM pay_runs r
            WHERE r.status != 'void'
            SQL);
        $this->params($lookup, [
            ['id', 'ID', 'integer', $this->key()],
            ['label', 'Run', 'string', ['is_label' => true, 'is_show' => false]],
            ['run_no', 'Run', 'string', ['is_criteria' => true]],
            ['period_start', 'Month', 'date'],
            ['status', 'Status', 'string'],
        ]);
    }

    // ---------------------------------------------------------------- employees

    private function employees(): void
    {
        $item = $this->meta('pay_employee_item_form', 'Recurring pay item', 'form', 'pay_employee_components', 'A pay item this employee gets every month. Leave the amount empty for its usual amount.');
        $this->params($item, [
            ['id', 'ID', 'integer', $this->key()],
            ['employee_id', 'Employee', 'integer', ['is_show' => false]],
            ['component_id', 'Pay item', 'integer', ['is_required' => true] + $this->lookup('pay_components_lookup')],
            ['amount', 'Amount', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
        ]);

        $form = $this->meta('pay_employee_form', 'Employee form', 'form', 'pay_employees', 'An employee, what they earn, and where it is paid. Setting an end date takes them off later runs.', null, null, null, ['is_log' => true]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['employee_no', 'Employee no', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'EMP-{seq:4}']]]],
            ['last_name', 'Last name', 'string', ['is_required' => true]],
            ['first_name', 'First name', 'string', ['is_required' => true, 'is_label' => true]],
            ['register_no', 'Register no', 'string'],
            ['department_id', 'Department', 'integer', $this->lookup('pay_departments_grid')],
            ['position', 'Position', 'string'],
            ['hire_date', 'Hired', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['end_date', 'Left', 'date'],
            ['base_salary', 'Base salary a month', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['bank_name', 'Bank', 'string'],
            ['bank_account', 'Bank account', 'string'],
            ['phone', 'Phone', 'string'],
            ['email', 'Email', 'string'],
            ['user_id', 'Login (sees their own payslips)', 'integer', $this->lookup('pay_users_lookup')],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['items', 'Recurring pay items', 'detail', ['ref_meta_id' => $item->id, 'json_config' => ['foreign_key' => 'employee_id']]],
        ]);
        $grid = $this->meta('pay_employees_grid', 'Employees', 'datagrid', 'pay_employees', 'Everyone on the payroll.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT e.id, e.employee_no, e.full_name, d.name AS department_name, e.position, e.hire_date, e.end_date, e.base_salary, e.bank_name, e.bank_account,
                   u.name AS login_name, CASE WHEN e.end_date IS NULL OR CURDATE() <= e.end_date THEN 1 ELSE 0 END AS is_current
            FROM pay_employees e
            LEFT JOIN pay_departments d ON d.id = e.department_id
            LEFT JOIN users u ON u.id = e.user_id
            SQL);
        $this->actions($grid, $form, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['employee_no', 'No', 'string', ['is_criteria' => true]],
            ['full_name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['department_name', 'Department', 'string', ['is_criteria' => true]],
            ['position', 'Position', 'string', ['is_criteria' => true]],
            ['hire_date', 'Hired', 'date'],
            ['end_date', 'Left', 'date'],
            ['base_salary', 'Base salary', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['bank_name', 'Bank', 'string'],
            ['bank_account', 'Account', 'string'],
            ['login_name', 'Login', 'string'],
            ['is_current', 'Current', 'boolean', ['is_criteria' => true]],
        ]);
    }

    // ---------------------------------------------------------------- what stage queries do: the triggers hold the rules

    private function stageQueries(): void
    {
        $id = ['run_id', 'Run', 'integer', ['is_required' => true]];
        $onBooks = 'e.hire_date <= :period_end AND (e.end_date IS NULL OR :period_start <= e.end_date)';

        // Neither statement may read the table its rows' triggers update: see 2026_12_03_000028.
        $this->stageQuery('pay_generate_slips', 'Generate payslips', 'pay_slips', <<<SQL
            INSERT INTO pay_slips (run_id, employee_id, created_at, updated_at)
            SELECT :run_id, e.id, NOW(), NOW()
            FROM pay_employees e
            WHERE {$onBooks}
              AND NOT EXISTS (SELECT 1 FROM pay_slips s WHERE s.run_id = :run_id AND s.employee_id = e.id);
            INSERT INTO pay_slip_lines (run_id, employee_id, component_id, amount, created_at, updated_at)
            SELECT :run_id, c.employee_id, c.component_id, c.amount, NOW(), NOW()
            FROM pay_employee_components c
            JOIN pay_employees e ON e.id = c.employee_id
            JOIN pay_components p ON p.id = c.component_id AND p.is_active = 1
            WHERE {$onBooks}
              AND NOT EXISTS (SELECT 1 FROM pay_slip_lines l WHERE l.run_id = :run_id AND l.employee_id = c.employee_id)
            SQL, 'Makes a slip for everyone on the books in the month who has none yet, with their recurring pay items. Existing slips stay as they are.', [
            $id,
            ['period_start', 'From', 'date', ['is_required' => true]],
            ['period_end', 'To', 'date', ['is_required' => true]],
        ]);

        $this->stageQuery('pay_approve_run', 'Approve run', 'pay_runs', <<<'SQL'
            UPDATE pay_runs r SET r.status = 'approved', r.approved_by = :user_id, r.updated_at = NOW()
            WHERE r.id = :run_id AND r.status = 'draft'
            SQL, 'Locks the run and its payslips, and puts them on the bank list. Employees can now see their payslips.', [$id]);
        $this->stageQuery('pay_reopen_run', 'Reopen run', 'pay_runs', <<<'SQL'
            UPDATE pay_runs r SET r.status = 'draft', r.updated_at = NOW()
            WHERE r.id = :run_id AND r.status = 'approved'
            SQL, 'Takes an approved run back to draft so its payslips can be changed.', [$id]);
        $this->stageQuery('pay_mark_paid', 'Mark as paid', 'pay_runs', <<<'SQL'
            UPDATE pay_runs r SET r.status = 'paid', r.paid_by = :user_id, r.updated_at = NOW()
            WHERE r.id = :run_id AND r.status = 'approved'
            SQL, 'Records that the bank payments went out. This is final: corrections go in a later month.', [$id]);
        $this->stageQuery('pay_void_run', 'Void run', 'pay_runs', <<<'SQL'
            UPDATE pay_runs r SET r.status = 'void', r.void_reason = :reason, r.updated_at = NOW()
            WHERE r.id = :run_id AND r.status IN ('draft', 'approved')
            SQL, 'Drops a run that was not paid, freeing its month for a new one.', [
            $id,
            ['reason', 'Why', 'string', ['is_required' => true]],
        ]);
    }

    // ---------------------------------------------------------------- runs

    private function runs(): void
    {
        $month = $this->meta('pay_run_month_get', 'Run of a month', 'get', null, 'The run a month already has, if any.', null, null, <<<'SQL'
            SELECT r.run_no FROM pay_runs r
            WHERE r.status != 'void' AND DATE_FORMAT(r.period_start, '%Y-%m') = DATE_FORMAT(:month, '%Y-%m')
            LIMIT 1
            SQL);

        $print = $this->meta('pay_slip_print', 'Payslip (register row)', 'form', 'pay_slips', 'The columns of the payroll register.');
        $this->params($print, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_id', 'Run', 'integer', ['is_show' => false]],
            ['employee_name', 'Employee', 'string'],
            ['department_name', 'Department', 'string'],
            ['gross_pay', 'Gross', 'decimal', ['render_type' => 'money']],
            ['si_employee', 'Insurance', 'decimal', ['render_type' => 'money']],
            ['pit', 'Income tax', 'decimal', ['render_type' => 'money']],
            ['deductions_total', 'Deductions', 'decimal', ['render_type' => 'money']],
            ['net_pay', 'Net', 'decimal', ['render_type' => 'money']],
        ]);
        $money = ['render_type' => 'money'];
        $report = $this->meta('pay_run_report', 'Payroll register', 'report', 'pay_runs', 'A run on paper: everyone, what they earned and what they take home.', null, [
            'title' => 'Payroll register', 'number_field' => 'run_no', 'note' => 'Prepared and approved.',
        ]);
        $this->params($report, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_no', 'Run', 'string'],
            ['period_start', 'From', 'date'],
            ['period_end', 'To', 'date'],
            ['pay_date', 'Pay date', 'date'],
            ['status', 'Status', 'string'],
            ['employee_count', 'Employees', 'integer'],
            ['working_days', 'Working days', 'decimal'],
            ['slips', 'Payslips', 'detail', ['ref_meta_id' => $print->id, 'json_config' => ['foreign_key' => 'run_id']]],
            ['gross_total', 'Gross', 'decimal', $money],
            ['si_employee_total', 'Employee insurance', 'decimal', $money],
            ['pit_total', 'Income tax', 'decimal', $money],
            ['deductions_total', 'Deductions', 'decimal', $money],
            ['net_total', 'Net to pay', 'decimal', $money],
            ['si_employer_total', 'Employer insurance', 'decimal', $money],
            ['employer_cost_total', 'Cost to the company', 'decimal', $money],
            ['prepared_signature', 'Prepared by', 'signature'],
            ['approved_signature', 'Approved by', 'signature'],
        ]);

        $form = $this->meta('pay_run_form', 'Payroll run', 'form', 'pay_runs', 'Pick any day of the month. Saving a new run makes everyone\'s payslips; to change the month or working days later, void it and start again.', null, ['save_and_add' => false], null, [
            'is_log' => true,
            'var_fnc_expression_string' => "const MONTH_GET = {$month->id};",
            'load_expression_string' => <<<'JS'
                readonly('status');
                hide('status', mode === 'new');
                if (mode === 'edit') {
                  readonly('period_start');
                  readonly('working_days');
                  if (get('status') !== 'draft') {
                    readonly('pay_date');
                    readonly('notes');
                    message('This run is ' + get('status') + ' and cannot be changed.');
                  }
                }
                JS,
            'before_save_expression_string' => <<<'JS'
                if (mode === 'edit' && get('status') !== 'draft') {
                  error('This run is ' + get('status') + ' and cannot be changed.');
                }
                if (mode === 'new') {
                  const taken = await fill(MONTH_GET, { month: String(get('period_start')) });
                  if (taken.run_no) {
                    error('This month already has run ' + taken.run_no + '.');
                  }
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_no', 'Run', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'PR-{yy}{MM}{seq:3}']]]],
            ['period_start', 'Month', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['working_days', 'Working days', 'decimal', ['is_required' => true, 'min_value' => '0.5', 'max_value' => '31', 'default_value' => '22']],
            ['pay_date', 'Pay date', 'date', ['is_required' => true]],
            ['status', 'Status', 'string', ['default_value' => 'draft', 'json_config' => ['options' => self::RUN_STATUSES]]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $this->flow($form, [
            'generate' => ['process' => $this->m['pay_generate_slips'], 'map' => ['run_id' => 'id', 'period_start' => 'period_start', 'period_end' => 'period_end']],
        ]);

        $slips = $this->slipGrid();
        $grid = $this->meta('pay_runs_grid', 'Payroll runs', 'datagrid', 'pay_runs', 'A run a month: make it, check the payslips, approve, pay.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $slips->id, 'params' => ['run_id' => 'id'], 'label' => 'Payslips'],
        ], <<<'SQL'
            SELECT r.id, r.run_no, r.status, r.period_start, r.period_end, r.pay_date, r.employee_count, r.gross_total, r.si_employee_total, r.pit_total, r.deductions_total,
                   r.net_total, r.si_employer_total, r.employer_cost_total, r.void_reason
            FROM pay_runs r
            SQL);
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'add', 'New run', 'plus', $form, null, 1, null, false, false);
        $this->action($grid, 'edit', 'Open', 'edit', $form, null, 2, ['id' => 'id'], true, true);
        $this->action($grid, 'stage_query', 'Approve', 'check', $this->m['pay_approve_run'], null, 3, ['run_id' => 'id'], true, true);
        $this->action($grid, 'stage_query', 'Mark as paid', 'check', $this->m['pay_mark_paid'], null, 4, ['run_id' => 'id'], true, false);
        $this->action($grid, 'stage_query', 'Generate payslips', 'zap', $this->m['pay_generate_slips'], null, 5, ['run_id' => 'id', 'period_start' => 'period_start', 'period_end' => 'period_end'], true, false);
        $this->action($grid, 'stage_query', 'Reopen', 'file', $this->m['pay_reopen_run'], null, 6, ['run_id' => 'id'], true, false);
        $this->action($grid, 'stage_query', 'Void', 'trash', $this->m['pay_void_run'], null, 7, ['run_id' => 'id'], true, false);
        $this->action($grid, 'custom', 'Payroll register', 'file', $report, null, 8, ['id' => 'id'], true, false);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_no', 'Run', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => self::RUN_STATUSES]]],
            ['period_start', 'From', 'date'],
            ['period_end', 'To', 'date'],
            ['pay_date', 'Pay date', 'date'],
            ['employee_count', 'Employees', 'integer'],
            ['gross_total', 'Gross', 'decimal', ['render_type' => 'money']],
            ['si_employee_total', 'Insurance', 'decimal', ['render_type' => 'money']],
            ['pit_total', 'Income tax', 'decimal', ['render_type' => 'money']],
            ['deductions_total', 'Deductions', 'decimal', ['render_type' => 'money']],
            ['net_total', 'Net', 'decimal', ['render_type' => 'money']],
            ['employer_cost_total', 'Company cost', 'decimal', ['render_type' => 'money']],
            ['void_reason', 'Void reason', 'string'],
        ]);
    }

    // ---------------------------------------------------------------- payslips and the bank list

    /** Made before the runs grid, which drills into it. */
    private function slipGrid(): Meta
    {
        $line = $this->meta('pay_slip_line_form', 'Pay item on a slip', 'form', 'pay_slip_lines', 'A pay item this month. Leave the amount empty for its usual amount.');
        $this->params($line, [
            ['id', 'ID', 'integer', $this->key()],
            ['slip_id', 'Slip', 'integer', ['is_show' => false]],
            ['component_id', 'Pay item', 'integer', ['is_required' => true] + $this->lookup('pay_components_lookup')],
            ['amount', 'Amount', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['notes', 'Notes', 'string'],
        ]);

        $printLine = $this->meta('pay_slip_line_print', 'Pay item (print)', 'form', 'pay_slip_lines', 'The pay items a payslip shows.');
        $this->params($printLine, $this->printLineParams());
        $report = $this->meta('pay_slip_report', 'Payslip', 'report', 'pay_slips', 'One employee\'s pay for one month, for the office.', null, ['title' => 'Payslip', 'number_field' => 'employee_no']);
        $this->params($report, $this->slipReportParams($printLine, true));

        $status = $this->meta('pay_slip_status_get', 'Status of a slip\'s run', 'get', null, 'Tells the slip form whether its run can still change.', null, null, <<<'SQL'
            SELECT r.status AS run_status, r.run_no FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id WHERE s.id = :id
            SQL);

        $money = ['render_type' => 'money'];
        $form = $this->meta('pay_slip_form', 'Payslip', 'form', 'pay_slips', 'Change the days or base salary and add one-off items. The amounts are worked out again when you save.', null, ['save_and_add' => false], null, [
            'is_log' => true,
            'load_expression_string' => <<<'JS'
                ['run_no', 'run_status', 'employee_no', 'employee_name', 'department_name', 'base_pay', 'earnings_total', 'gross_pay', 'si_employee', 'taxable_pay', 'pit', 'deductions_total', 'net_pay', 'si_employer'].forEach((f) => readonly(f));
                if (get('run_status') && get('run_status') !== 'draft') {
                  readonly('worked_days');
                  readonly('base_salary');
                  readonly('notes');
                  message('This run is ' + get('run_status') + ', so its payslips cannot be changed.');
                }
                JS,
            'before_save_expression_string' => <<<'JS'
                if (get('run_status') && get('run_status') !== 'draft') {
                  error('This run is ' + get('run_status') + ', so its payslips cannot be changed.');
                }
                JS,
        ]);
        $this->params($form, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_no', 'Run', 'string'],
            ['run_status', 'Run status', 'string'],
            ['employee_no', 'Employee no', 'string'],
            ['employee_name', 'Employee', 'string', ['is_label' => true]],
            ['department_name', 'Department', 'string'],
            ['base_salary', 'Base salary', 'decimal', ['min_value' => '0', 'render_type' => 'money']],
            ['worked_days', 'Worked days', 'decimal', ['min_value' => '0']],
            ['base_pay', 'Base pay', 'decimal', $money],
            ['earnings_total', 'Other earnings', 'decimal', $money],
            ['gross_pay', 'Gross', 'decimal', $money],
            ['si_employee', 'Social insurance', 'decimal', $money],
            ['taxable_pay', 'Taxable', 'decimal', $money],
            ['pit', 'Income tax', 'decimal', $money],
            ['deductions_total', 'Deductions', 'decimal', $money],
            ['net_pay', 'Net pay', 'decimal', $money],
            ['si_employer', 'Employer insurance', 'decimal', $money],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
            ['lines', 'Pay items', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'slip_id']]],
        ]);

        $run = ['is_criteria' => true, 'is_show' => false] + $this->lookup('pay_runs_lookup', 'label');
        $grid = $this->meta('pay_slips_grid', 'Payslips', 'datagrid', 'pay_slips', 'Everyone\'s pay by month. Open a slip to adjust it while its run is a draft.', null, [
            'filter_layout' => 'dropdown',
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Payslip'],
        ], <<<'SQL'
            SELECT s.id, s.run_id, r.run_no, r.status AS run_status, r.period_start, s.employee_no, s.employee_name, s.department_name, s.base_salary, s.worked_days,
                   s.gross_pay, s.si_employee, s.pit, s.deductions_total, s.net_pay
            FROM pay_slips s
            JOIN pay_runs r ON r.id = s.run_id
            WHERE r.status != 'void'
            SQL);
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $this->action($grid, 'edit', 'Open', 'edit', $form, $status, 1, ['id' => 'id'], true, true);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_id', 'Run', 'integer', $run],
            ['run_no', 'Run', 'string', ['is_criteria' => true]],
            ['run_status', 'Status', 'string', ['render_type' => 'status', 'json_config' => ['options' => self::RUN_STATUSES]]],
            ['period_start', 'Month', 'date'],
            ['employee_no', 'No', 'string', ['is_criteria' => true]],
            ['employee_name', 'Employee', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['department_name', 'Department', 'string', ['is_criteria' => true]],
            ['worked_days', 'Days', 'decimal'],
            ['gross_pay', 'Gross', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['si_employee', 'Insurance', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['pit', 'Income tax', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['deductions_total', 'Deductions', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
            ['net_pay', 'Net', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);

        return $grid;
    }

    private function slips(): void
    {
        $run = ['is_criteria' => true, 'is_show' => false] + $this->lookup('pay_runs_lookup', 'label');
        $grid = $this->meta('pay_bank_list_grid', 'Bank list', 'datagrid', 'pay_slips', 'What to pay whom: the approved and paid runs. Export it for the bank.', null, ['filter_layout' => 'dropdown'], <<<'SQL'
            SELECT s.id, s.run_id, r.run_no, r.pay_date, s.employee_no, s.employee_name, s.bank_name, s.bank_account, s.net_pay
            FROM pay_slips s
            JOIN pay_runs r ON r.id = s.run_id
            WHERE r.status IN ('approved', 'paid')
            SQL);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_id', 'Run', 'integer', $run],
            ['run_no', 'Run', 'string', ['is_criteria' => true]],
            ['pay_date', 'Pay date', 'date'],
            ['employee_no', 'No', 'string', ['is_criteria' => true]],
            ['employee_name', 'Employee', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['bank_name', 'Bank', 'string', ['is_criteria' => true]],
            ['bank_account', 'Account', 'string'],
            ['net_pay', 'Amount', 'decimal', ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']]],
        ]);
    }

    // ---------------------------------------------------------------- what an employee sees: their own, once approved

    private function selfService(): void
    {
        $own = "JOIN pay_employees e ON e.id = s.employee_id AND e.user_id = :user_id\n JOIN pay_runs r ON r.id = s.run_id AND r.status IN ('approved', 'paid')";

        $line = $this->meta('pay_my_slip_line_print', 'My pay item (print)', 'form', 'pay_slip_lines', 'The pay items of my own payslips.', null, null, <<<SQL
            SELECT l.id, l.slip_id, l.component_name, l.kind, l.amount, l.notes
            FROM pay_slip_lines l
            JOIN pay_slips s ON s.id = l.slip_id
            {$own}
            SQL);
        $this->params($line, $this->printLineParams());

        $report = $this->meta('pay_my_slip_report', 'My payslip', 'report', 'pay_slips', 'My pay for one month.', null, ['title' => 'Payslip', 'number_field' => 'employee_no'], <<<SQL
            SELECT s.id
            FROM pay_slips s
            {$own}
            SQL);
        $this->params($report, $this->slipReportParams($line, false));

        $grid = $this->meta('pay_my_slips_grid', 'My payslips', 'datagrid', 'pay_slips', 'Your own payslips, once a run is approved.', null, [
            'drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id'], 'label' => 'Payslip'],
        ], <<<SQL
            SELECT s.id, r.run_no, r.period_start, r.pay_date, r.status AS run_status, s.gross_pay, s.si_employee, s.pit, s.deductions_total, s.net_pay
            FROM pay_slips s
            {$own}
            SQL);
        $this->params($grid, [
            ['id', 'ID', 'integer', $this->key()],
            ['run_no', 'Run', 'string', ['is_label' => true]],
            ['period_start', 'Month', 'date'],
            ['pay_date', 'Pay date', 'date'],
            ['run_status', 'Status', 'string', ['render_type' => 'status', 'json_config' => ['options' => self::RUN_STATUSES]]],
            ['gross_pay', 'Gross', 'decimal', ['render_type' => 'money']],
            ['si_employee', 'Insurance', 'decimal', ['render_type' => 'money']],
            ['pit', 'Income tax', 'decimal', ['render_type' => 'money']],
            ['deductions_total', 'Deductions', 'decimal', ['render_type' => 'money']],
            ['net_pay', 'Net', 'decimal', ['render_type' => 'money']],
        ]);
    }

    private function printLineParams(): array
    {
        return [
            ['id', 'ID', 'integer', $this->key()],
            ['slip_id', 'Slip', 'integer', ['is_show' => false]],
            ['component_name', 'Item', 'string'],
            ['kind', 'Kind', 'string'],
            ['amount', 'Amount', 'decimal', ['render_type' => 'money']],
            ['notes', 'Notes', 'string'],
        ];
    }

    private function slipReportParams(Meta $line, bool $office): array
    {
        $money = ['render_type' => 'money'];
        $rows = [
            ['id', 'ID', 'integer', $this->key()],
            ['employee_no', 'Employee no', 'string'],
            ['run_id', 'Run', 'integer', ['is_select' => true, 'ref_meta_id' => $this->m['pay_runs_lookup']->id, 'display_field' => 'run_no']],
            ['employee_name', 'Employee', 'string'],
            ['department_name', 'Department', 'string'],
            ['position', 'Position', 'string'],
            ['bank_name', 'Bank', 'string'],
            ['bank_account', 'Account', 'string'],
            ['worked_days', 'Worked days', 'decimal'],
            ['base_salary', 'Base salary', 'decimal', $money],
            ['lines', 'Pay items', 'detail', ['ref_meta_id' => $line->id, 'json_config' => ['foreign_key' => 'slip_id']]],
            ['base_pay', 'Base pay', 'decimal', $money],
            ['earnings_total', 'Other earnings', 'decimal', $money],
            ['gross_pay', 'Gross', 'decimal', $money],
            ['si_employee', 'Social insurance', 'decimal', $money],
            ['pit', 'Income tax', 'decimal', $money],
            ['deductions_total', 'Deductions', 'decimal', $money],
            ['net_pay', 'Net pay', 'decimal', $money],
        ];
        if ($office) {
            $rows[] = ['si_employer', 'Employer insurance', 'decimal', $money];
        }

        return $rows;
    }

    // ---------------------------------------------------------------- dashboard

    private function dashboard(): void
    {
        $paid = "r.status IN ('approved', 'paid')";
        $latest = "SELECT r.net_total, r.employer_cost_total, r.employee_count FROM pay_runs r WHERE {$paid} ORDER BY r.period_start DESC LIMIT 1";
        $kpi = fn (string $agg, ?string $column, string $label, ?string $format = null) => array_filter([
            'type' => 'kpi', 'measures' => [array_filter(['agg' => $agg, 'column' => $column, 'label' => $label])], 'format' => $format,
        ]);
        $charts = [
            [$this->chart('pay_chart_net', 'Net pay, latest run', $latest, 'What people took home in the latest approved or paid run.', $kpi('sum', 'net_total', 'Net', 'money')), 3, 1],
            [$this->chart('pay_chart_cost', 'Company cost, latest run', $latest, 'Gross pay plus employer insurance.', $kpi('sum', 'employer_cost_total', 'Cost', 'money')), 3, 1],
            [$this->chart('pay_chart_employees', 'Current employees', 'SELECT e.id FROM pay_employees e WHERE e.end_date IS NULL OR CURDATE() <= e.end_date', 'Everyone on the books today.', $kpi('count', null, 'Employees')), 3, 1],
            [$this->chart('pay_chart_drafts', 'Runs in draft', "SELECT r.id FROM pay_runs r WHERE r.status = 'draft'", 'Runs waiting for approval.', $kpi('count', null, 'Runs')), 3, 1],
            [$this->chart('pay_chart_by_month', 'Company cost by month', "SELECT r.period_start, r.net_total, r.si_employee_total + r.pit_total + r.si_employer_total AS contributions FROM pay_runs r WHERE {$paid}", 'Net pay, and what went to insurance and tax.',
                ['type' => 'column', 'x' => 'period_start', 'bucket' => 'month', 'date_field' => 'period_start', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'net_total', 'label' => 'Net pay'],
                    ['agg' => 'sum', 'column' => 'contributions', 'label' => 'Insurance and tax'],
                ]]), 6, 2],
            [$this->chart('pay_chart_by_department', 'Gross by department', "SELECT COALESCE(s.department_name, 'None') AS department, r.period_start, s.gross_pay FROM pay_slips s JOIN pay_runs r ON r.id = s.run_id WHERE {$paid}", 'Gross pay in the period picked above.',
                ['type' => 'bar', 'x' => 'department', 'sort' => 'value', 'date_field' => 'period_start', 'format' => 'money', 'measures' => [['agg' => 'sum', 'column' => 'gross_pay', 'label' => 'Gross']]]), 6, 2],
        ];

        $dashboard = $this->meta('pay_dashboard', 'Payroll dashboard', 'dashboard', null, 'The latest run at the top; below, the period picked above.');
        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'pay_chart_');
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

        $app = $this->meta('pay_app', 'Payroll', 'app', null, 'Monthly payroll: employees, runs, payslips, the bank list, and employees\' own payslips.', null, ['menu_style' => 'floating']);
        $this->params($app, [
            $menu('runs', 'Payroll runs', 'pay_runs_grid', 'calendar'),
            $menu('slips', 'Payslips', 'pay_slips_grid', 'receipt'),
            $menu('bank', 'Bank list', 'pay_bank_list_grid', 'file'),
            $menu('employees', 'Employees', 'pay_employees_grid', 'users'),
            $menu('dashboard', 'Dashboard', 'pay_dashboard', 'chart'),
            ['setup', 'Setup', 'string', ['icon_cls' => 'settings', 'children' => [
                $menu('departments', 'Departments', 'pay_departments_grid'),
                $menu('items', 'Pay items', 'pay_components_grid'),
                $menu('rates', 'Rates', 'pay_rates_grid'),
            ]]],
            $menu('mine', 'My payslips', 'pay_my_slips_grid', 'file'),
        ]);
    }

    /**
     * pay_employee: the app, their own payslips, the run pick list (no amounts). Nothing else.
     * pay_officer: reads everything; keeps employees, departments and draft runs. Cannot approve or pay.
     * pay_manager: everything here.
     */
    private function access(): void
    {
        $manager = Role::updateOrCreate(['code' => 'pay_manager'], ['name' => 'Payroll manager', 'description' => 'Approves, reopens, pays and voids payroll runs, and keeps pay items and rates.']);
        $officer = Role::updateOrCreate(['code' => 'pay_officer'], ['name' => 'Payroll officer', 'description' => 'Keeps employees and prepares payroll runs. Cannot approve or pay them.']);
        $employee = Role::updateOrCreate(['code' => 'pay_employee'], ['name' => 'Employee (payslips)', 'description' => 'Sees their own approved payslips, once linked to an employee.']);
        $tag = Tag::firstOrCreate(['name' => 'payroll'], ['color' => 'success']);
        $types = array_flip($this->types);

        $view = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];
        $keep = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false];
        $run = ['can_view' => true, 'can_create' => false, 'can_edit' => true, 'can_delete' => false];
        $managerOnly = ['pay_approve_run', 'pay_reopen_run', 'pay_mark_paid', 'pay_void_run', 'pay_component_form', 'pay_rate_form'];
        $mine = ['pay_my_slips_grid', 'pay_my_slip_report', 'pay_my_slip_line_print'];
        $officerRights = [
            'pay_employee_form' => $keep, 'pay_employee_item_form' => $keep, 'pay_department_form' => $keep, 'pay_run_form' => $keep,
            'pay_slip_form' => $run, 'pay_slip_line_form' => $keep, 'pay_generate_slips' => $run,
        ];

        foreach ($this->m as $code => $meta) {
            $type = $types[$meta->type_id] ?? null;
            $form = $type === 'form';
            MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $manager->id], [
                'can_view' => true, 'can_create' => $form, 'can_edit' => $form || $type === 'stage_query', 'can_delete' => $form,
            ]);
            if (! in_array($code, $managerOnly, true) && ! in_array($code, $mine, true)) {
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $officer->id], $officerRights[$code] ?? $view);
            }
            if (in_array($code, [...$mine, 'pay_app', 'pay_runs_lookup'], true)) {
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $employee->id], $view);
            }
            if (! DB::table('meta_tag_map')->where('meta_id', $meta->id)->where('tag_id', $tag->id)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $meta->id, 'tag_id' => $tag->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000024)

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

    private function lookup(string $grid, string $display = 'name'): array
    {
        return ['is_select' => true, 'ref_meta_id' => $this->m[$grid]->id, 'render_type' => 'lookup', 'display_field' => $display];
    }

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

    private function chart(string $code, string $name, string $query, string $description, array $config): Meta
    {
        return $this->meta($code, $name, 'chart', null, $description, null, $config, $query);
    }

    /** Replaces a form's process flow (see 2026_12_03_000001). */
    private function flow(Meta $form, array $steps): void
    {
        MetaProcessTransition::where('main_process_id', $form->id)->delete();
        MetaProcessWorkflow::where('main_process_id', $form->id)->delete();
        $order = 0;
        foreach ($steps as $step) {
            $workflow = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $step['process']->id, 'order_number' => ++$order]);
            foreach ($step['map'] as $target => $source) {
                MetaProcessBehaviour::create(['workflow_id' => $workflow->id, 'source_path' => $source, 'target_path' => $target]);
            }
        }
    }
};
