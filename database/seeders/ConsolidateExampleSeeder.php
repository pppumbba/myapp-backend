<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An example of a consolidate button (migration 2026_12_04_000008 makes the tables): service
 * jobs done for customers are picked in their grid and merged into one invoice.
 *
 *   Service billing ─ Service jobs   cx_jobs_grid → cx_job_form
 *                   │                  └ Make invoice (consolidate, get cx_job_as_invoice) → cx_invoice_form
 *                   └ Invoices       cx_invoices_grid → cx_invoice_form (lines: cx_invoice_line_form)
 *
 * The get gives each picked job as one row, its columns named like the invoice's fields
 * (technician AS technicians, job_date AS period_from and period_to…). The invoice's group_type
 * fields merge them: the customer is the first, the technicians and job numbers are joined,
 * jobs counts them, the period runs from the earliest to the latest date. Lines group by item:
 * quantities add up, prices average, descriptions are joined. Each line's amount and the total
 * are formulas (calculated fields).
 *
 * Idempotent: metas matched by code, their params and actions rebuilt; demo jobs only when there
 * are none. No permission rows: admins only until roles are given.
 */
class ConsolidateExampleSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $types = [];

    public function run(): void
    {
        if (! Schema::hasTable('cx_service_jobs') || ! Schema::hasTable('cx_invoice_lines')) {
            return;
        }
        $this->types = MetaType::pluck('id', 'code')->all();
        if (array_diff(['app', 'datagrid', 'form', 'get'], array_keys($this->types))) {
            return;
        }

        DB::transaction(function () {
            $status = fn (array $list, string $default) => ['render_type' => 'select', 'default_value' => $default, 'json_config' => ['options' => $list]];

            // A job, as it is entered and edited.
            $jobForm = $this->meta('cx_job_form', 'Service job', 'form', 'cx_service_jobs', 'One piece of work done for a customer.');
            $this->params($jobForm, [
                ['id', 'ID', 'integer', $this->key()],
                ['job_no', 'Job no', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'JOB-{seq:4}']]]],
                ['job_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
                ['customer', 'Customer', 'string', ['is_required' => true]],
                ['technician', 'Technician', 'string', ['is_required' => true]],
                ['item', 'Item', 'string', ['is_required' => true]],
                ['description', 'Description', 'string'],
                ['qty', 'Qty', 'decimal', ['is_required' => true, 'default_value' => '1', 'min_value' => '0']],
                ['price', 'Price', 'decimal', ['is_required' => true, 'min_value' => '0', 'render_type' => 'money']],
                ['status', 'Status', 'string', ['is_required' => true] + $status(['open', 'done'], 'done')],
            ]);

            // The invoice's lines: one per item, merged from the picked jobs.
            $lineForm = $this->meta('cx_invoice_line_form', 'Invoice line', 'form', 'cx_invoice_lines', 'One item on an invoice.');
            $this->params($lineForm, [
                ['id', 'ID', 'integer', $this->key()],
                ['invoice_id', 'Invoice', 'integer', ['is_show' => false]],
                ['item', 'Item', 'string', ['is_required' => true, 'group_type' => 'group']],
                ['description', 'Description', 'string', ['group_type' => 'concat']],
                ['qty', 'Qty', 'decimal', ['is_required' => true, 'default_value' => '1', 'group_type' => 'sum']],
                ['price', 'Price', 'decimal', ['is_required' => true, 'render_type' => 'money', 'group_type' => 'avg']],
                ['amount', 'Amount', 'decimal', ['render_type' => 'money', 'expression_string' => 'round(qty * price, 2)']],
            ]);

            $invoiceForm = $this->meta('cx_invoice_form', 'Invoice', 'form', 'cx_invoices', 'Bills a customer for service jobs. Make invoice on Service jobs fills it from the picked jobs.');
            $this->params($invoiceForm, [
                ['id', 'ID', 'integer', $this->key()],
                ['invoice_no', 'Invoice no', 'string', ['is_required' => true, 'is_unique' => true, 'is_label' => true, 'json_config' => ['autonumber' => ['format' => 'INV-{yy}{MM}{seq:3}']]]],
                ['invoice_date', 'Invoice date', 'date', ['is_required' => true, 'default_value' => '{today}']],
                ['customer', 'Customer', 'string', ['is_required' => true, 'group_type' => 'first']],
                ['technicians', 'Technicians', 'string', ['group_type' => 'concat']],
                ['period_from', 'Work from', 'date', ['group_type' => 'min']],
                ['period_to', 'Work to', 'date', ['group_type' => 'max']],
                ['jobs', 'Jobs', 'integer', ['group_type' => 'count']],
                ['job_numbers', 'Job numbers', 'text', ['render_type' => 'textarea', 'group_type' => 'concat']],
                ['lines', 'Lines', 'detail', ['ref_meta_id' => $lineForm->id, 'json_config' => ['foreign_key' => 'invoice_id']]],
                ['total', 'Total', 'decimal', ['render_type' => 'money', 'expression_string' => 'round(sum(lines.amount), 2)']],
            ]);

            // Each picked job as one row, its columns named like the invoice's fields.
            $get = $this->meta('cx_job_as_invoice', 'Job as invoice rows', 'get', null, 'A picked job, its columns named like the invoice and its lines.', null, <<<'SQL'
                SELECT customer, technician AS technicians, job_date AS period_from, job_date AS period_to,
                       job_no AS jobs, job_no AS job_numbers, item, description, qty, price
                FROM cx_service_jobs
                WHERE id = :id
                SQL);

            $jobsGrid = $this->meta('cx_jobs_grid', 'Service jobs', 'datagrid', 'cx_service_jobs', 'Tick the jobs of one customer and press Make invoice.', ['filter_layout' => 'sidebar']);
            $this->params($jobsGrid, [
                ['id', 'ID', 'integer', $this->key()],
                ['job_no', 'Job no', 'string', ['is_criteria' => true]],
                ['job_date', 'Date', 'date'],
                ['customer', 'Customer', 'string', ['is_criteria' => true]],
                ['technician', 'Technician', 'string', ['is_criteria' => true]],
                ['item', 'Item', 'string', ['is_criteria' => true]],
                ['description', 'Description', 'string'],
                ['qty', 'Qty', 'decimal'],
                ['price', 'Price', 'decimal', ['render_type' => 'money']],
                ['status', 'Status', 'string', ['is_criteria' => true, 'render_type' => 'status', 'json_config' => ['options' => ['open', 'done']]]],
            ]);
            $this->actions($jobsGrid, $jobForm, [
                ['action' => 'consolidate', 'label' => 'Make invoice', 'icon_cls' => 'file', 'form_meta_id' => $invoiceForm->id, 'get_meta_id' => $get->id, 'needs_row' => true, 'json_config' => ['params' => ['id' => 'id']]],
            ]);

            $invoicesGrid = $this->meta('cx_invoices_grid', 'Invoices', 'datagrid', 'cx_invoices', 'Invoices made from service jobs.');
            $this->params($invoicesGrid, [
                ['id', 'ID', 'integer', $this->key()],
                ['invoice_no', 'Invoice no', 'string', ['is_criteria' => true]],
                ['invoice_date', 'Date', 'date'],
                ['customer', 'Customer', 'string', ['is_criteria' => true]],
                ['technicians', 'Technicians', 'string'],
                ['jobs', 'Jobs', 'integer'],
                ['total', 'Total', 'decimal', ['render_type' => 'money']],
            ]);
            $this->actions($invoicesGrid, $invoiceForm);

            $app = $this->meta('cx_app', 'Service billing', 'app', null, 'An example of consolidate: service jobs merged into invoices.');
            $this->params($app, [
                ['service_jobs', 'Service jobs', 'string', ['icon_cls' => 'tool', 'ref_meta_id' => $jobsGrid->id]],
                ['invoices', 'Invoices', 'string', ['icon_cls' => 'file', 'ref_meta_id' => $invoicesGrid->id]],
            ]);

            $this->demoJobs();
        });
    }

    /** A few jobs for two customers, so there is something to pick. */
    private function demoJobs(): void
    {
        if (DB::table('cx_service_jobs')->exists()) {
            return;
        }
        $jobs = [
            ['2026-11-02', 'Blue Sky LLC', 'Bat', 'Labour hour', 'Router setup', 2, 45000],
            ['2026-11-03', 'Blue Sky LLC', 'Dorj', 'Labour hour', 'Printer repair', 1.5, 45000],
            ['2026-11-03', 'Blue Sky LLC', 'Dorj', 'Network cable (m)', 'Office 2 cabling', 30, 2500],
            ['2026-11-05', 'Blue Sky LLC', 'Bat', 'Labour hour', 'Server check', 3, 50000],
            ['2026-11-06', 'Blue Sky LLC', 'Bat', 'Network cable (m)', 'Meeting room', 12, 2500],
            ['2026-11-04', 'Green Farm Co', 'Saraa', 'Labour hour', 'Laptop cleanup', 1, 45000],
            ['2026-11-07', 'Green Farm Co', 'Saraa', 'SSD 512GB', 'Laptop upgrade', 2, 180000],
            ['2026-11-07', 'Green Farm Co', 'Bat', 'Labour hour', 'Laptop upgrade', 2, 45000],
        ];
        $now = now();
        foreach ($jobs as $i => [$date, $customer, $technician, $item, $description, $qty, $price]) {
            DB::table('cx_service_jobs')->insert([
                'job_no' => sprintf('JOB-%04d', $i + 1), 'job_date' => $date, 'customer' => $customer, 'technician' => $technician,
                'item' => $item, 'description' => $description, 'qty' => $qty, 'price' => $price, 'status' => 'done',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Add, Edit and Delete through $form, then $extra buttons.
     *
     * @param  list<array<string, mixed>>  $extra
     */
    private function actions(Meta $grid, Meta $form, array $extra = []): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();
        $buttons = [
            ['action' => 'add', 'label' => 'Add', 'icon_cls' => 'plus', 'form_meta_id' => $form->id, 'needs_row' => false],
            ['action' => 'edit', 'label' => 'Edit', 'icon_cls' => 'edit', 'form_meta_id' => $form->id, 'needs_row' => true, 'show_in_row' => true, 'json_config' => ['params' => ['id' => 'id']]],
            ['action' => 'delete', 'label' => 'Delete', 'icon_cls' => 'trash', 'form_meta_id' => $form->id, 'needs_row' => true, 'show_in_row' => true, 'json_config' => ['params' => ['id' => 'id']]],
            ...$extra,
        ];
        foreach ($buttons as $i => $button) {
            MetaAction::create($button + ['grid_meta_id' => $grid->id, 'order_number' => $i + 1]);
        }
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?array $config = null, ?string $query = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'json_config' => $config,
            'query_string' => $query,
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
