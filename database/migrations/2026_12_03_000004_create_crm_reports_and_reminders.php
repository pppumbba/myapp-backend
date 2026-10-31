<?php

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaSchedule;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\Tag;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Next pieces of the CRM app (needs 2026_12_03_000001_create_crm_metas and the
 * crm_task_reminders table of 2026_12_03_000003):
 *
 *   - Customer summary (crm_customer_summary): one row of key figures for a customer
 *     (pipeline, won, invoiced, received, balance, overdue, credit left, open follow-ups,
 *     open complaints, average rating, last activity), the first section of the customer
 *     workspace.
 *   - Sales rep performance (crm_rep_performance): a statement, one row per rep: open and
 *     weighted pipeline, deals won and lost in the period and the win rate, quotations,
 *     invoiced and received for their customers, activities and follow-ups. Under Sales
 *     pipeline in the app menu. The dashboard gets a "Pipeline by rep" chart.
 *   - Follow-up reminders: every day at 07:00 the schedule saves a crm_task_reminder_form
 *     record for each open follow-up due today or earlier (crm_task_reminders_due), and
 *     crm_notify_task_due tells its owner. The reminders sent are under Setup.
 *     Needs the scheduler running (schedule:work or cron).
 *
 * Idempotent: metas are matched by code and their params rebuilt; the rows added to the
 * workspace, dashboard, setup tabs and app menu are matched by field_path.
 */
return new class extends Migration
{
    /** @var array<string, int> meta type code => id */
    private array $types = [];

    /** @var array<string, Meta> the metas made here, by code */
    private array $m = [];

    /** @var array<string, Meta> existing CRM metas, by code */
    private array $crm = [];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('crm_task_reminders')) {
            return;
        }
        $this->types = DB::table('meta_types')->pluck('id', 'code')->all();
        $this->crm = Meta::where('code', 'like', 'crm\_%')->get()->keyBy('code')->all();
        foreach (['crm_app', 'crm_customer_workspace', 'crm_dashboard', 'crm_setup', 'crm_tasks_grid', 'crm_customers_grid', 'crm_users_lookup', 'crm_opportunities_grid', 'crm_quotation_workflow', 'crm_complaint_workflow'] as $code) {
            if (! isset($this->crm[$code])) {
                return;
            }
        }

        DB::transaction(function () {
            $this->customerSummary();
            $this->repPerformance();
            $this->reminders();
            $this->access();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::transaction(function () {
            $codes = ['crm_customer_summary', 'crm_rep_performance', 'crm_chart_pipeline_by_rep', 'crm_task_reminder_form', 'crm_task_reminders_due', 'crm_task_reminders_grid', 'crm_notify_task_due'];
            $ids = DB::table('metas')->whereIn('code', $codes)->pluck('id');
            DB::table('meta_schedules')->whereIn('process_id', $ids)->orWhereIn('view_id', $ids)->delete();
            DB::table('schedules')->where('code', '0 7 * * *')->whereNotExists(fn ($q) => $q->from('meta_schedules')->whereColumn('meta_schedules.schedule_id', 'schedules.id'))->delete();
            // Rows pointing at them (workspace section, dashboard chart, setup tab, menu entry) go with them.
            DB::table('meta_params')->whereIn('ref_meta_id', $ids)->delete();
            DB::table('metas')->whereIn('id', $ids)->delete();
        });
    }

    // ---------------------------------------------------------------- customer 360 summary

    private function customerSummary(): void
    {
        $q = $this->statuses('crm_quotation_workflow');
        $c = $this->statuses('crm_complaint_workflow');
        $summary = $this->meta('crm_customer_summary', 'Customer summary', 'datagrid', null, 'One customer at a glance: pipeline, sales, what they owe, follow-ups and service.', null, null, strtr(<<<'SQL'
            SELECT c.id AS customer_id,
                   COALESCE(o.open_amount, 0) AS open_pipeline,
                   COALESCE(o.weighted, 0) AS weighted_pipeline,
                   COALESCE(o.won_amount, 0) AS won_amount,
                   COALESCE(q.open_quotes, 0) AS open_quotations,
                   COALESCE(i.invoiced, 0) AS invoiced,
                   COALESCE(p.received, 0) AS received,
                   COALESCE(i.invoiced, 0) - COALESCE(p.received, 0) AS balance,
                   COALESCE(od.overdue, 0) AS overdue,
                   CASE WHEN c.credit_limit IS NULL THEN NULL
                        ELSE c.credit_limit - (COALESCE(i.invoiced, 0) - COALESCE(p.received, 0)) END AS credit_left,
                   COALESCE(t.open_tasks, 0) AS open_followups,
                   COALESCE(t.overdue_tasks, 0) AS overdue_followups,
                   COALESCE(k.open_complaints, 0) AS open_complaints,
                   f.avg_rating,
                   a.last_activity
            FROM crm_customers c
            LEFT JOIN (SELECT customer_id,
                              SUM(CASE WHEN stage NOT IN ('won', 'lost') THEN amount ELSE 0 END) AS open_amount,
                              SUM(CASE WHEN stage NOT IN ('won', 'lost') THEN amount * probability / 100 ELSE 0 END) AS weighted,
                              SUM(CASE WHEN stage = 'won' THEN amount ELSE 0 END) AS won_amount
                       FROM crm_opportunities WHERE customer_id = :customer_id GROUP BY customer_id) o ON o.customer_id = c.id
            LEFT JOIN (SELECT customer_id, COUNT(*) AS open_quotes
                       FROM crm_quotations
                       WHERE customer_id = :customer_id AND (status_id IS NULL OR status_id NOT IN ({accepted}, {rejected}))
                       GROUP BY customer_id) q ON q.customer_id = c.id
            LEFT JOIN (SELECT customer_id, SUM(total_amount) AS invoiced
                       FROM crm_invoices WHERE customer_id = :customer_id AND status <> 'cancelled' GROUP BY customer_id) i ON i.customer_id = c.id
            LEFT JOIN (SELECT customer_id, SUM(amount) AS received
                       FROM crm_payments WHERE customer_id = :customer_id GROUP BY customer_id) p ON p.customer_id = c.id
            LEFT JOIN (SELECT x.customer_id, SUM(x.balance) AS overdue
                       FROM (SELECT d.customer_id,
                                    d.total_amount - COALESCE((SELECT SUM(pp.amount) FROM crm_payments pp WHERE pp.invoice_id = d.id), 0) AS balance
                             FROM crm_invoices d
                             WHERE d.customer_id = :customer_id AND d.status <> 'cancelled' AND d.due_date < :today) x
                       WHERE x.balance > 0 GROUP BY x.customer_id) od ON od.customer_id = c.id
            LEFT JOIN (SELECT customer_id, SUM(status = 'open') AS open_tasks, SUM(status = 'open' AND due_on < :today) AS overdue_tasks
                       FROM crm_tasks WHERE customer_id = :customer_id GROUP BY customer_id) t ON t.customer_id = c.id
            LEFT JOIN (SELECT customer_id, COUNT(*) AS open_complaints
                       FROM crm_complaints
                       WHERE customer_id = :customer_id AND (status_id IS NULL OR status_id NOT IN ({resolved}, {closed}))
                       GROUP BY customer_id) k ON k.customer_id = c.id
            LEFT JOIN (SELECT customer_id, ROUND(AVG(rating), 1) AS avg_rating
                       FROM crm_feedback WHERE customer_id = :customer_id AND rating IS NOT NULL GROUP BY customer_id) f ON f.customer_id = c.id
            LEFT JOIN (SELECT customer_id, MAX(activity_at) AS last_activity
                       FROM crm_activities WHERE customer_id = :customer_id GROUP BY customer_id) a ON a.customer_id = c.id
            WHERE c.id = :customer_id
            SQL, [
            '{accepted}' => (string) $q['accepted'], '{rejected}' => (string) $q['rejected'],
            '{resolved}' => (string) $c['resolved'], '{closed}' => (string) $c['closed'],
        ]));
        $money = ['render_type' => 'money'];
        $this->params($summary, [
            ['customer_id', 'Customer', 'integer', ['is_primary' => true, 'is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
            ['open_pipeline', 'Open pipeline', 'decimal', $money],
            ['weighted_pipeline', 'Weighted', 'decimal', $money],
            ['won_amount', 'Won', 'decimal', $money],
            ['open_quotations', 'Open quotations', 'integer'],
            ['invoiced', 'Invoiced', 'decimal', $money],
            ['received', 'Received', 'decimal', $money],
            ['balance', 'Balance', 'decimal', $money],
            ['overdue', 'Overdue', 'decimal', $money],
            ['credit_left', 'Credit left', 'decimal', $money],
            ['open_followups', 'Open follow-ups', 'integer'],
            ['overdue_followups', 'Overdue follow-ups', 'integer'],
            ['open_complaints', 'Open complaints', 'integer'],
            ['avg_rating', 'Rating (1-5)', 'decimal'],
            ['last_activity', 'Last activity', 'datetime'],
        ]);

        // The workspace's first section (order 0, before the timeline).
        $this->extraRow($this->crm['crm_customer_workspace'], null, 'summary', 'Summary', $summary, ['scope' => ['customer_id' => 'id']], 0);
    }

    // ---------------------------------------------------------------- sales rep performance

    private function repPerformance(): void
    {
        $statement = $this->meta('crm_rep_performance', 'Sales rep performance', 'statement', null, 'Each sales rep: open pipeline now; deals won and lost, quotations, invoiced and received for their customers, and activities in the period; follow-ups now. Won and lost count by when the deal last changed.', null, [
            'sort' => [['field' => 'won_amount', 'dir' => 'desc']],
            'drilldown' => ['meta_id' => $this->crm['crm_opportunities_grid']->id, 'params' => ['owner_id' => 'id'], 'label' => 'Their deals'],
        ], <<<'SQL'
            SELECT u.id, u.name AS rep_name,
                   COALESCE(op.open_count, 0) AS open_deals,
                   COALESCE(op.open_amount, 0) AS open_pipeline,
                   COALESCE(op.weighted, 0) AS weighted_pipeline,
                   COALESCE(cl.won_count, 0) AS won_deals,
                   COALESCE(cl.won_amount, 0) AS won_amount,
                   COALESCE(cl.lost_count, 0) AS lost_deals,
                   CASE WHEN COALESCE(cl.won_count, 0) + COALESCE(cl.lost_count, 0) = 0 THEN NULL
                        ELSE ROUND(100 * cl.won_count / (cl.won_count + cl.lost_count), 1) END AS win_rate_pct,
                   COALESCE(qt.quotes, 0) AS quotations,
                   COALESCE(inv.invoiced, 0) AS invoiced,
                   COALESCE(pay.received, 0) AS received,
                   COALESCE(act.activities, 0) AS activities,
                   COALESCE(tk.open_tasks, 0) AS open_followups,
                   COALESCE(tk.overdue_tasks, 0) AS overdue_followups
            FROM users u
            LEFT JOIN (SELECT owner_id, COUNT(*) AS open_count, SUM(amount) AS open_amount, SUM(amount * probability / 100) AS weighted
                       FROM crm_opportunities WHERE stage NOT IN ('won', 'lost') GROUP BY owner_id) op ON op.owner_id = u.id
            LEFT JOIN (SELECT owner_id, SUM(stage = 'won') AS won_count, SUM(CASE WHEN stage = 'won' THEN amount ELSE 0 END) AS won_amount,
                              SUM(stage = 'lost') AS lost_count
                       FROM crm_opportunities
                       WHERE stage IN ('won', 'lost')
                         AND (:from_date IS NULL OR DATE(updated_at) >= :from_date)
                         AND (:to_date IS NULL OR DATE(updated_at) <= :to_date)
                       GROUP BY owner_id) cl ON cl.owner_id = u.id
            LEFT JOIN (SELECT owner_id, COUNT(*) AS quotes
                       FROM crm_quotations
                       WHERE (:from_date IS NULL OR issue_date >= :from_date) AND (:to_date IS NULL OR issue_date <= :to_date)
                       GROUP BY owner_id) qt ON qt.owner_id = u.id
            LEFT JOIN (SELECT c.owner_id, SUM(i.total_amount) AS invoiced
                       FROM crm_invoices i JOIN crm_customers c ON c.id = i.customer_id
                       WHERE i.status <> 'cancelled'
                         AND (:from_date IS NULL OR i.invoice_date >= :from_date) AND (:to_date IS NULL OR i.invoice_date <= :to_date)
                       GROUP BY c.owner_id) inv ON inv.owner_id = u.id
            LEFT JOIN (SELECT c.owner_id, SUM(p.amount) AS received
                       FROM crm_payments p JOIN crm_customers c ON c.id = p.customer_id
                       WHERE (:from_date IS NULL OR p.paid_on >= :from_date) AND (:to_date IS NULL OR p.paid_on <= :to_date)
                       GROUP BY c.owner_id) pay ON pay.owner_id = u.id
            LEFT JOIN (SELECT owner_id, COUNT(*) AS activities
                       FROM crm_activities
                       WHERE (:from_date IS NULL OR DATE(activity_at) >= :from_date) AND (:to_date IS NULL OR DATE(activity_at) <= :to_date)
                       GROUP BY owner_id) act ON act.owner_id = u.id
            LEFT JOIN (SELECT owner_id, SUM(status = 'open') AS open_tasks, SUM(status = 'open' AND due_on < :today) AS overdue_tasks
                       FROM crm_tasks GROUP BY owner_id) tk ON tk.owner_id = u.id
            WHERE op.owner_id IS NOT NULL OR cl.owner_id IS NOT NULL OR qt.owner_id IS NOT NULL OR inv.owner_id IS NOT NULL
               OR pay.owner_id IS NOT NULL OR act.owner_id IS NOT NULL OR tk.owner_id IS NOT NULL
            SQL);
        $sum = fn () => ['json_config' => ['aggregate' => 'sum']];
        $money = fn () => ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']];
        $this->params($statement, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['rep_name', 'Sales rep', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['open_deals', 'Open deals', 'integer', $sum()],
            ['open_pipeline', 'Open pipeline', 'decimal', $money()],
            ['weighted_pipeline', 'Weighted', 'decimal', $money()],
            ['won_deals', 'Won', 'integer', $sum()],
            ['won_amount', 'Won amount', 'decimal', $money()],
            ['lost_deals', 'Lost', 'integer', $sum()],
            ['win_rate_pct', 'Win rate %', 'decimal'],
            ['quotations', 'Quotations', 'integer', $sum()],
            ['invoiced', 'Invoiced', 'decimal', $money()],
            ['received', 'Received', 'decimal', $money()],
            ['activities', 'Activities', 'integer', $sum()],
            ['open_followups', 'Open follow-ups', 'integer', $sum()],
            ['overdue_followups', 'Overdue follow-ups', 'integer', $sum()],
            ['from_date', 'From', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'To', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        $pipeline = MetaParam::where('main_meta_id', $this->crm['crm_app']->id)->whereNull('parent_id')->where('field_path', 'pipeline')->first();
        if ($pipeline) {
            $this->extraRow($this->crm['crm_app'], $pipeline->id, 'rep_performance', 'Sales rep performance', $statement, null);
        }

        $chart = $this->meta('crm_chart_pipeline_by_rep', 'Pipeline by rep', 'chart', null, 'Open and weighted pipeline of each sales rep.', null, [
            'type' => 'bar', 'x' => 'rep', 'sort' => 'value', 'limit' => 10, 'format' => 'money', 'measures' => [
                ['agg' => 'sum', 'column' => 'amount', 'label' => 'Pipeline'],
                ['agg' => 'sum', 'column' => 'weighted', 'label' => 'Weighted'],
            ],
        ], <<<'SQL'
            SELECT COALESCE(u.name, '(no owner)') AS rep, o.amount, o.amount * o.probability / 100 AS weighted, o.expected_close
            FROM crm_opportunities o
            LEFT JOIN users u ON u.id = o.owner_id
            WHERE o.stage NOT IN ('won', 'lost')
            SQL);
        $this->extraRow($this->crm['crm_dashboard'], null, 'pipeline_by_rep', null, $chart, ['w' => 12, 'h' => 2], null, 'chart');
    }

    // ---------------------------------------------------------------- follow-up reminders

    private function reminders(): void
    {
        $users = $this->crm['crm_users_lookup'];
        $customers = $this->crm['crm_customers_grid'];
        $tasks = $this->crm['crm_tasks_grid'];
        $lookup = fn (Meta $grid, string $display = 'name') => ['is_select' => true, 'ref_meta_id' => $grid->id, 'render_type' => 'lookup', 'display_field' => $display];

        $form = $this->meta('crm_task_reminder_form', 'Follow-up reminder', 'form', 'crm_task_reminders', 'A reminder sent to a follow-up’s owner; saved each morning by the schedule for follow-ups due or overdue.');
        $this->params($form, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['task_id', 'Follow-up', 'integer', ['is_required' => true] + $lookup($tasks, 'title')],
            ['title', 'What to do', 'string', ['is_required' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', $lookup($customers)],
            ['owner_id', 'Who', 'integer', $lookup($users)],
            ['due_on', 'Due', 'date', ['is_required' => true]],
            ['reminded_on', 'Reminded on', 'date', ['is_required' => true, 'default_value' => '{today}']],
        ]);

        // Open follow-ups due today or earlier, not yet reminded today: one reminder each.
        $due = $this->meta('crm_task_reminders_due', 'Follow-ups to remind', 'datagrid', null, 'Open follow-ups due today or overdue that have had no reminder today; the daily schedule saves a reminder for each.', null, null, <<<'SQL'
            SELECT t.id, t.id AS task_id, t.title, t.customer_id, t.owner_id, t.due_on,
                   :today AS reminded_on, DATEDIFF(:today, t.due_on) AS days_overdue
            FROM crm_tasks t
            WHERE t.status = 'open' AND t.owner_id IS NOT NULL AND t.due_on <= :today
              AND NOT EXISTS (SELECT 1 FROM crm_task_reminders r WHERE r.task_id = t.id AND r.reminded_on = :today)
            SQL);
        $this->params($due, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['task_id', 'Follow-up', 'integer', ['is_show' => false]],
            ['title', 'What to do', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $lookup($customers)],
            ['owner_id', 'Who', 'integer', ['is_criteria' => true] + $lookup($users)],
            ['due_on', 'Due', 'date'],
            ['days_overdue', 'Days overdue', 'integer'],
            ['reminded_on', 'Remind on', 'date', ['is_show' => false]],
        ]);

        $log = $this->meta('crm_task_reminders_grid', 'Reminders sent', 'datagrid', 'crm_task_reminders', 'The follow-up reminders sent, newest first.', null, ['filter_layout' => 'dropdown']);
        $this->params($log, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['reminded_on', 'Reminded on', 'date', ['is_criteria' => true]],
            ['title', 'What to do', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['task_id', 'Follow-up', 'integer', $lookup($tasks, 'title')],
            ['customer_id', 'Customer', 'integer', ['is_criteria' => true] + $lookup($customers)],
            ['owner_id', 'Who', 'integer', ['is_criteria' => true] + $lookup($users)],
            ['due_on', 'Due', 'date'],
        ]);
        $this->extraRow($this->crm['crm_setup'], null, 'reminders', 'Reminders sent', $log, null);

        // The schedule runs as the first admin, so the actor is not left out of the recipients.
        $notification = $this->meta('crm_notify_task_due', 'Follow-up due', 'notification', null, 'Tells the owner each morning about a follow-up that is due or overdue.', null, [
            'event' => 'created',
            'source_meta_id' => $form->id,
            'kind' => 'alert',
            'title' => 'Follow-up due: {title}',
            'body' => 'Due {due_on}. Customer: {customer_id}.',
            'link_url' => "/run/{$tasks->id}?record={task_id}",
            'exclude_actor' => false,
        ]);
        MetaParam::where('main_meta_id', $notification->id)->delete();
        MetaParam::create(['main_meta_id' => $notification->id, 'order_number' => 1, 'field_path' => 'owner_id', 'param_name' => 'owner_id', 'data_type' => 'field', 'is_show' => true]);

        $schedule = Schedule::firstOrCreate(['code' => '0 7 * * *'], ['name' => 'Every day at 07:00']);
        MetaSchedule::firstOrCreate(['schedule_id' => $schedule->id, 'process_id' => $form->id, 'view_id' => $due->id]);
    }

    // ---------------------------------------------------------------- who may use it

    /** The "crm" role: view on each new meta, full use of the form; tagged crm like the rest. */
    private function access(): void
    {
        $role = Role::where('code', 'crm')->first();
        $tag = Tag::firstOrCreate(['name' => 'crm'], ['color' => 'blue']);
        $now = now();
        foreach ($this->m as $meta) {
            if ($role) {
                $full = $meta->type_id === $this->types['form'];
                MetaPermission::updateOrCreate(['meta_id' => $meta->id, 'role_id' => $role->id], ['can_view' => true, 'can_create' => $full, 'can_edit' => $full, 'can_delete' => $full]);
            }
            if (! DB::table('meta_tag_map')->where('tag_id', $tag->id)->where('meta_id', $meta->id)->exists()) {
                DB::table('meta_tag_map')->insert(['meta_id' => $meta->id, 'tag_id' => $tag->id, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    // ---------------------------------------------------------------- helpers (as in 2026_12_03_000001)

    /** @return array<string, int> status code => id */
    private function statuses(string $workflow): array
    {
        return DB::table('wfm_status')->where('workflow_meta_id', $this->crm[$workflow]->id)->pluck('id', 'code')->all();
    }

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

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
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

    /**
     * Adds (or refreshes) one row pointing at $target in an existing meta's params, matched by
     * field_path: a workspace section, a dashboard chart, a tab or a menu entry. New rows go
     * last unless $order is given.
     */
    private function extraRow(Meta $meta, ?int $parentId, string $field, ?string $label, Meta $target, ?array $config, ?int $order = null, string $type = 'string'): void
    {
        $existing = MetaParam::where('main_meta_id', $meta->id)->where('field_path', $field)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))->first();
        $values = ['label_name' => $label, 'data_type' => $type, 'ref_meta_id' => $target->id, 'json_config' => $config, 'is_show' => true];
        if ($existing) {
            $existing->update($values + ($order !== null ? ['order_number' => $order] : []));

            return;
        }
        $last = MetaParam::where('main_meta_id', $meta->id)
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))->max('order_number');
        MetaParam::create($values + [
            'main_meta_id' => $meta->id,
            'parent_id' => $parentId,
            'order_number' => $order ?? ((int) $last + 1),
            'field_path' => $field,
            'param_name' => $field,
        ]);
    }
};
