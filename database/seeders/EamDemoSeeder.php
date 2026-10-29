<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\MetaSchedule;
use App\Models\MetaType;
use App\Models\Schedule;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An Enterprise Asset Management (EAM) demo app built entirely from metas:
 *
 *   Assets (app)
 *   ├─ Overview              -> dashboard: asset counts and value, open work, maintenance spend
 *   ├─ Assets (menu group)
 *   │  ├─ Asset register     -> datagrid with sidebar filters; each row opens its workspace
 *   │  ├─ Asset workspace    -> one asset: its work orders, PM plans, meter readings, transfers, finance
 *   │  └─ Transfers         -> datagrid; saving a transfer moves the asset (process flow)
 *   ├─ Maintenance (menu group)
 *   │  ├─ Work orders        -> datagrid with parts as lines and a "Complete" stage query button
 *   │  ├─ Maintenance calendar -> calendar of scheduled work orders, coloured by type
 *   │  ├─ PM plans           -> datagrid of preventive maintenance plans
 *   │  ├─ PM due now         -> query grid of plans due; a daily schedule raises their work orders
 *   │  └─ Meter readings     -> datagrid
 *   ├─ Finance               -> capitalization, depreciation, revaluation, devaluation and the
 *   │                           journal (EamFinanceSeeder, called at the end)
 *   └─ Setup                 -> tabs: Locations, Categories
 *
 * Automation:
 *   - Transfer form: a process flow step (stage query eam_apply_transfer) moves the asset to
 *     the new location and assignee.
 *   - Work order form: when it was raised from a PM plan, a step (eam_advance_pm) moves the
 *     plan's next due date one interval past today.
 *   - Schedule "daily": the work order form runs once per row of "PM due now".
 *   - Work order grid: "Complete" runs eam_complete_work_order (closes it, stamps the plan's
 *     last_done, puts an asset in repair back in service).
 *
 * The PM date arithmetic SQL is MySQL. Dates are placed around the day the seeder
 * runs. Safe to run again: metas are matched by code and their params rebuilt; sample
 * records are only added to empty tables.
 */
class EamDemoSeeder extends Seeder
{
    use SeedsActions;

    private const ASSET_STATUSES = ['in service', 'in repair', 'idle', 'disposed'];

    private const CONDITIONS = ['new', 'good', 'fair', 'poor'];

    private const LEVELS = ['low', 'medium', 'high', 'critical'];

    private const LOCATION_KINDS = ['site', 'building', 'floor', 'room', 'rack', 'yard', 'warehouse'];

    private const WO_TYPES = ['corrective', 'preventive', 'inspection', 'calibration'];

    private const WO_STATUSES = ['open', 'assigned', 'in progress', 'on hold', 'completed', 'cancelled'];

    private const METERS = ['hours', 'km', 'cycles', 'kWh'];

    private const DEPARTMENTS = ['Operations', 'Production', 'IT', 'Logistics', 'Finance', 'Facilities'];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->sampleData();
            $this->metas();
        });
        $this->call(EamFinanceSeeder::class);
    }

    private function metas(): void
    {
        $key = ['is_primary' => true, 'is_show' => false];
        $hidden = ['is_show' => false];
        $options = fn (array $list, string $default) => ['render_type' => 'select', 'default_value' => $default, 'json_config' => ['options' => $list]];

        // Setup: where assets are and what kinds there are.
        // Locations sit inside each other (a room in a building on a site): the grid is a tree (is_parent).
        $locationForm = $this->meta('eam_location_form', 'Location form', 'form', 'eam_locations', 'A site, building, room or yard assets can be in.');
        $locationGrid = $this->meta('eam_locations_grid', 'Locations', 'datagrid', 'eam_locations', 'Where assets can be.');
        $this->params($locationForm, [
            ['id', 'ID', 'integer', $key],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $options(self::LOCATION_KINDS, 'building')],
            ['parent_id', 'Inside', 'integer', ['is_select' => true, 'ref_meta_id' => $locationGrid->id, 'render_type' => 'lookup', 'display_field' => 'name']],
            ['address', 'Address', 'string'],
        ]);
        $this->actions($locationGrid, $locationForm);
        $this->params($locationGrid, [
            ['id', 'ID', 'integer', $key],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
            ['parent_id', 'Inside', 'integer', ['is_parent' => true, 'is_show' => false]],
            ['address', 'Address', 'string'],
        ]);

        $categoryForm = $this->meta('eam_category_form', 'Category form', 'form', 'eam_categories', 'A kind of asset and how long it lasts.');
        $this->params($categoryForm, [
            ['id', 'ID', 'integer', $key],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['useful_life_years', 'Useful life (years)', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '5']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);
        $categoryGrid = $this->meta('eam_categories_grid', 'Categories', 'datagrid', 'eam_categories', 'Kinds of assets and their useful life.');
        $this->actions($categoryGrid, $categoryForm);
        $this->params($categoryGrid, [
            ['id', 'ID', 'integer', $key],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['useful_life_years', 'Useful life (years)', 'integer'],
            ['notes', 'Notes', 'text'],
        ]);

        $setup = $this->meta('eam_setup', 'Setup', 'tabs', null, 'Locations and asset categories.');
        $this->params($setup, [
            ['locations', 'Locations', 'string', ['ref_meta_id' => $locationGrid->id]],
            ['categories', 'Categories', 'string', ['ref_meta_id' => $categoryGrid->id]],
        ]);

        $location = ['is_select' => true, 'ref_meta_id' => $locationGrid->id, 'render_type' => 'lookup', 'display_field' => 'name'];
        $category = ['is_select' => true, 'ref_meta_id' => $categoryGrid->id, 'render_type' => 'lookup', 'display_field' => 'name'];

        // The asset register.
        $assetForm = $this->meta('eam_asset_form', 'Asset form', 'form', 'eam_assets', 'Register an asset: what it is, where it is, what it cost.');
        $this->params($assetForm, [
            ['id', 'ID', 'integer', $key],
            ['asset_tag', 'Asset tag', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true, 'json_config' => ['autonumber' => ['format' => 'AST-{yy}{seq:4}']]]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true, 'is_label' => true]],
            ['category_id', 'Category', 'integer', ['is_required' => true] + $category],
            ['location_id', 'Location', 'integer', ['is_required' => true] + $location],
            ['status', 'Status', 'string', ['is_required' => true] + $options(self::ASSET_STATUSES, 'in service')],
            ['condition', 'Condition', 'string', ['is_required' => true] + $options(self::CONDITIONS, 'new')],
            ['criticality', 'Criticality', 'string', ['is_required' => true] + $options(self::LEVELS, 'medium')],
            ['manufacturer', 'Manufacturer', 'string'],
            ['model', 'Model', 'string'],
            ['serial_no', 'Serial number', 'string', ['is_criteria' => true]],
            ['department', 'Department', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::DEPARTMENTS]]],
            ['assigned_to', 'Assigned to', 'string'],
            ['purchase_date', 'Purchased', 'date'],
            ['purchase_cost', 'Purchase cost', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['salvage_value', 'Salvage value', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['useful_life_years', 'Useful life (years)', 'integer', ['min_value' => '1']],
            ['warranty_until', 'Warranty until', 'date'],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $assetColumns = [
            ['id', 'ID', 'integer', $key],
            ['asset_tag', 'Tag', 'string', ['is_criteria' => true]],
            ['name', 'Asset', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['category_id', 'Category', 'integer', ['is_criteria' => true] + $category],
            ['location_id', 'Location', 'integer', ['is_criteria' => true] + $location],
            ['department', 'Department', 'string', ['is_criteria' => true]],
            ['assigned_to', 'Assigned to', 'string'],
            ['criticality', 'Criticality', 'string', ['is_criteria' => true]],
            ['purchase_cost', 'Cost', 'decimal', ['render_type' => 'money']],
            ['carrying_amount', 'Book value', 'decimal', ['render_type' => 'money']],
            ['warranty_until', 'Warranty until', 'date'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true, 'json_config' => ['options' => self::ASSET_STATUSES]]],
        ];
        // A plain grid for lookups; the register below is the same with a sidebar and a workspace.
        $assetLookup = $this->meta('eam_assets_lookup', 'Assets (pick list)', 'datagrid', 'eam_assets', 'Every asset, for picking one.');
        $this->params($assetLookup, $assetColumns);
        $asset = ['is_select' => true, 'ref_meta_id' => $assetLookup->id, 'render_type' => 'lookup', 'display_field' => 'name'];
        $hiddenAsset = ['is_required' => true, 'is_show' => false];

        // Maintenance: work orders with the parts they used as lines.
        $partForm = $this->meta('eam_work_order_part_form', 'Work order part', 'form', 'eam_work_order_parts', 'One part used on a work order.');
        $this->params($partForm, [
            ['id', 'ID', 'integer', $key],
            ['work_order_id', 'Work order', 'integer', $hidden],
            ['part_name', 'Part', 'string', ['is_required' => true]],
            ['quantity', 'Quantity', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '1']],
            ['unit_cost', 'Unit cost', 'decimal', ['is_required' => true, 'min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
        ]);

        $workOrderForm = $this->meta('eam_work_order_form', 'Work order form', 'form', 'eam_work_orders', 'Report a fault or plan work on an asset, and record what was done.');
        $this->params($workOrderForm, [
            ['id', 'ID', 'integer', $key],
            ['wo_no', 'Work order', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => ['format' => 'WO-{yy}{MM}{seq:4}']]]],
            ['asset_id', 'Asset', 'integer', ['is_required' => true] + $asset],
            ['pm_plan_id', 'PM plan', 'integer', $hidden],
            ['title', 'Title', 'string', ['is_required' => true, 'is_label' => true]],
            ['type', 'Type', 'string', ['is_required' => true] + $options(self::WO_TYPES, 'corrective')],
            ['priority', 'Priority', 'string', ['is_required' => true] + $options(self::LEVELS, 'medium')],
            ['status', 'Status', 'string', ['is_required' => true] + $options(self::WO_STATUSES, 'open')],
            ['reported_by', 'Reported by', 'string'],
            ['reported_on', 'Reported on', 'date', ['default_value' => '{today}']],
            ['technician', 'Technician', 'string'],
            ['scheduled_start', 'Scheduled start', 'datetime'],
            ['scheduled_end', 'Scheduled end', 'datetime'],
            ['completed_on', 'Completed on', 'date'],
            ['labor_hours', 'Labor hours', 'decimal', ['min_value' => '0', 'default_value' => '0']],
            ['labor_cost', 'Labor cost', 'decimal', ['min_value' => '0', 'default_value' => '0', 'render_type' => 'money']],
            ['description', 'What is wrong / what to do', 'text', ['render_type' => 'textarea']],
            ['resolution', 'What was done', 'text', ['render_type' => 'textarea']],
            ['parts', 'Parts used', 'detail', ['ref_meta_id' => $partForm->id, 'json_config' => [
                'foreign_key' => 'work_order_id',
                'amount' => ['quantity', 'unit_cost'],
                'total_field' => 'parts_cost',
            ]]],
            ['parts_cost', 'Parts cost', 'decimal', ['render_type' => 'money', 'default_value' => '0']],
        ]);

        $workOrderColumns = [
            ['id', 'ID', 'integer', $key],
            ['wo_no', 'Number', 'string', ['is_criteria' => true]],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['type', 'Type', 'string', ['is_criteria' => true]],
            ['priority', 'Priority', 'string', ['is_criteria' => true]],
            ['technician', 'Technician', 'string', ['is_criteria' => true]],
            ['scheduled_start', 'Scheduled', 'datetime'],
            ['completed_on', 'Completed', 'date'],
            ['total_cost', 'Cost', 'decimal', ['render_type' => 'money']],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true, 'json_config' => ['options' => self::WO_STATUSES]]],
        ];
        $complete = $this->stageQuery('eam_complete_work_order', 'Complete work order', 'eam_work_orders', <<<'SQL'
            UPDATE eam_work_orders
            SET status = 'completed', completed_on = COALESCE(completed_on, :today), updated_at = CURRENT_TIMESTAMP
            WHERE id = :work_order_id AND status NOT IN ('completed', 'cancelled');
            UPDATE eam_pm_plans
            SET last_done = :today, updated_at = CURRENT_TIMESTAMP
            WHERE id = (SELECT pm_plan_id FROM eam_work_orders WHERE id = :work_order_id);
            UPDATE eam_assets
            SET status = 'in service', updated_at = CURRENT_TIMESTAMP
            WHERE status = 'in repair' AND id = (SELECT asset_id FROM eam_work_orders WHERE id = :work_order_id)
            SQL, 'Closes a work order today, stamps its PM plan as done and puts an asset in repair back in service.', [
            ['work_order_id', 'Work order', 'integer', ['is_required' => true]],
        ]);

        $workOrderGrid = $this->meta('eam_work_orders_grid', 'Work orders', 'datagrid', 'eam_work_orders', 'Every job on every asset, open and done.', null, ['filter_layout' => 'sidebar']);
        $this->actions($workOrderGrid, $workOrderForm);
        $this->completeButton($workOrderGrid, $complete);
        $this->params($workOrderGrid, $workOrderColumns);

        // The same columns without the asset, for the asset workspace.
        $assetWorkOrders = $this->meta('eam_asset_work_orders_grid', 'Asset work orders', 'datagrid', 'eam_work_orders', 'The work orders of one asset.');
        $this->actions($assetWorkOrders, $workOrderForm);
        $this->completeButton($assetWorkOrders, $complete);
        $this->params($assetWorkOrders, array_map(fn ($c) => $c[0] === 'asset_id' ? [$c[0], $c[1], $c[2], $hiddenAsset] : $c, $workOrderColumns));

        $calendar = $this->meta('eam_maintenance_calendar', 'Maintenance calendar', 'calendar', 'eam_work_orders', 'When work is scheduled, by type.', $workOrderForm, [
            'start_field' => 'scheduled_start',
            'end_field' => 'scheduled_end',
            'title_field' => 'title',
            'item_label' => 'work order',
            'color_field' => 'type',
            'colors' => ['corrective' => 'red', 'preventive' => 'blue', 'inspection' => 'green', 'calibration' => 'purple'],
        ]);
        $this->params($calendar, [
            ['id', 'ID', 'integer', $key],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['type', 'Type', 'string', ['is_criteria' => true]],
            ['technician', 'Technician', 'string', ['is_criteria' => true]],
            ['scheduled_start', 'Starts', 'datetime'],
            ['scheduled_end', 'Ends', 'datetime'],
            ['status', 'Status', 'string'],
        ]);

        // Preventive maintenance plans.
        $planForm = $this->meta('eam_pm_plan_form', 'PM plan form', 'form', 'eam_pm_plans', 'Recurring work on an asset: what to do and how often.');
        $planFields = [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_required' => true] + $asset],
            ['title', 'Title', 'string', ['is_required' => true, 'is_label' => true]],
            ['type', 'Type', 'string', ['is_required' => true] + $options(self::WO_TYPES, 'preventive')],
            ['priority', 'Priority', 'string', ['is_required' => true] + $options(self::LEVELS, 'medium')],
            ['interval_days', 'Every (days)', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '30']],
            ['next_due', 'Next due', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['last_done', 'Last done', 'date'],
            ['technician', 'Technician', 'string'],
            ['task_list', 'Tasks', 'text', ['render_type' => 'textarea']],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ];
        $this->params($planForm, $planFields);
        $planColumns = [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['title', 'Plan', 'string', ['is_criteria' => true]],
            ['type', 'Type', 'string', ['is_criteria' => true]],
            ['interval_days', 'Every (days)', 'integer'],
            ['next_due', 'Next due', 'date'],
            ['last_done', 'Last done', 'date'],
            ['technician', 'Technician', 'string'],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ];
        $planGrid = $this->meta('eam_pm_plans_grid', 'PM plans', 'datagrid', 'eam_pm_plans', 'Preventive maintenance plans and when each is due.');
        $this->actions($planGrid, $planForm);
        $this->params($planGrid, $planColumns);
        $assetPlans = $this->meta('eam_asset_pm_plans_grid', 'Asset PM plans', 'datagrid', 'eam_pm_plans', 'The PM plans of one asset.');
        $this->actions($assetPlans, $planForm);
        $this->params($assetPlans, array_map(fn ($c) => $c[0] === 'asset_id' ? [$c[0], $c[1], $c[2], $hiddenAsset] : $c, $planColumns));

        // Plans due by today with no work still open on them. Its columns are the work order
        // form's fields, so the daily schedule can save one work order per row.
        $planDue = $this->meta('eam_pm_due_grid', 'PM due now', 'datagrid', 'eam_pm_plans', 'Active plans due today or earlier, with no work order still open.', null, null, <<<'SQL'
            SELECT p.id AS pm_plan_id, p.asset_id, p.title, p.type, p.priority, p.technician,
                   p.task_list AS description, p.next_due, 'PM schedule' AS reported_by
            FROM eam_pm_plans p
            WHERE p.is_active = 1 AND p.next_due <= :today
              AND NOT EXISTS (SELECT 1 FROM eam_work_orders w
                              WHERE w.pm_plan_id = p.id AND w.status NOT IN ('completed', 'cancelled'))
            ORDER BY p.next_due
            SQL);
        $this->params($planDue, [
            ['pm_plan_id', 'Plan', 'integer', $key],
            ['asset_id', 'Asset', 'integer', $asset],
            ['title', 'Plan', 'string', ['is_criteria' => true]],
            ['type', 'Type', 'string'],
            ['priority', 'Priority', 'string'],
            ['technician', 'Technician', 'string'],
            ['next_due', 'Due', 'date'],
            ['description', 'Tasks', 'text', $hidden],
            ['reported_by', 'Reported by', 'string', $hidden],
        ]);

        $advance = $this->stageQuery('eam_advance_pm', 'Advance PM plan', 'eam_pm_plans', <<<'SQL'
            UPDATE eam_pm_plans
            SET next_due = DATE_ADD(GREATEST(next_due, :today), INTERVAL interval_days DAY), updated_at = CURRENT_TIMESTAMP
            WHERE id = :pm_plan_id
            SQL, 'Moves a PM plan’s next due date one interval past today, once its work order is raised.', [
            ['pm_plan_id', 'PM plan', 'integer', ['is_required' => true]],
        ]);
        $this->flow($workOrderForm, $advance, ['pm_plan_id' => 'pm_plan_id'], [['field' => 'pm_plan_id', 'op' => 'not_empty']]);

        $daily = Schedule::firstOrCreate(['code' => 'daily'], ['name' => 'Every day']);
        MetaSchedule::firstOrCreate(['schedule_id' => $daily->id, 'process_id' => $workOrderForm->id, 'view_id' => $planDue->id]);

        // Meter readings.
        $readingForm = $this->meta('eam_meter_reading_form', 'Meter reading form', 'form', 'eam_meter_readings', 'Record a meter reading on an asset.');
        $this->params($readingForm, [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_required' => true] + $asset],
            ['read_on', 'Read on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['meter', 'Meter', 'string', ['is_required' => true] + $options(self::METERS, 'hours')],
            ['value', 'Reading', 'decimal', ['is_required' => true, 'min_value' => '0']],
            ['read_by', 'Read by', 'string', ['default_value' => '{user_name}']],
        ]);
        $readingColumns = [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['read_on', 'Read on', 'date'],
            ['meter', 'Meter', 'string', ['is_criteria' => true]],
            ['value', 'Reading', 'decimal'],
            ['read_by', 'Read by', 'string'],
        ];
        $readingGrid = $this->meta('eam_meter_readings_grid', 'Meter readings', 'datagrid', 'eam_meter_readings', 'Running hours, kilometres and cycles over time.');
        $this->actions($readingGrid, $readingForm);
        $this->params($readingGrid, $readingColumns);
        $assetReadings = $this->meta('eam_asset_meter_readings_grid', 'Asset meter readings', 'datagrid', 'eam_meter_readings', 'The meter readings of one asset.');
        $this->actions($assetReadings, $readingForm);
        $this->params($assetReadings, array_map(fn ($c) => $c[0] === 'asset_id' ? [$c[0], $c[1], $c[2], $hiddenAsset] : $c, $readingColumns));

        // Transfers: saving one moves the asset.
        $transferForm = $this->meta('eam_transfer_form', 'Transfer form', 'form', 'eam_asset_transfers', 'Move an asset to another location or person.');
        $this->params($transferForm, [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_required' => true] + $asset],
            ['from_location_id', 'From', 'integer', $location],
            ['to_location_id', 'To', 'integer', ['is_required' => true] + $location],
            ['moved_on', 'Moved on', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['assigned_to', 'Now with', 'string'],
            ['reason', 'Reason', 'string'],
        ]);
        $transferColumns = [
            ['id', 'ID', 'integer', $key],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['from_location_id', 'From', 'integer', $location],
            ['to_location_id', 'To', 'integer', ['is_criteria' => true] + $location],
            ['moved_on', 'Moved on', 'date'],
            ['assigned_to', 'Now with', 'string'],
            ['reason', 'Reason', 'string'],
        ];
        $transferGrid = $this->meta('eam_transfers_grid', 'Transfers', 'datagrid', 'eam_asset_transfers', 'Every move of an asset between locations and people.');
        $this->actions($transferGrid, $transferForm);
        $this->params($transferGrid, $transferColumns);
        $assetTransfers = $this->meta('eam_asset_transfers_grid', 'Asset transfers', 'datagrid', 'eam_asset_transfers', 'The moves of one asset.');
        $this->actions($assetTransfers, $transferForm);
        $this->params($assetTransfers, array_map(fn ($c) => $c[0] === 'asset_id' ? [$c[0], $c[1], $c[2], $hiddenAsset] : $c, $transferColumns));

        $apply = $this->stageQuery('eam_apply_transfer', 'Apply transfer', 'eam_assets', <<<'SQL'
            UPDATE eam_assets
            SET location_id = :to_location_id, assigned_to = COALESCE(:assigned_to, assigned_to), updated_at = CURRENT_TIMESTAMP
            WHERE id = :asset_id
            SQL, 'Moves an asset to a transfer’s location and person.', [
            ['asset_id', 'Asset', 'integer', ['is_required' => true]],
            ['to_location_id', 'To', 'integer', ['is_required' => true]],
            ['assigned_to', 'Now with', 'string'],
        ]);
        $this->flow($transferForm, $apply, ['asset_id' => 'asset_id', 'to_location_id' => 'to_location_id', 'assigned_to' => 'assigned_to']);

        // One asset and everything about it.
        $workspace = $this->meta('eam_asset_workspace', 'Asset workspace', 'workspace', 'eam_assets', 'One asset: its work orders, PM plans, meter readings and transfers.', $assetForm, [
            'header' => ['asset_tag', 'category_id', 'location_id', 'assigned_to', 'carrying_amount', 'status'],
            'title_field' => 'name',
        ]);
        $scope = ['scope' => ['asset_id' => 'id']];
        $this->params($workspace, [
            ['work_orders', 'Work orders', 'string', ['ref_meta_id' => $assetWorkOrders->id, 'icon_cls' => 'list', 'json_config' => $scope]],
            ['pm_plans', 'PM plans', 'string', ['ref_meta_id' => $assetPlans->id, 'icon_cls' => 'calendar', 'json_config' => $scope]],
            ['readings', 'Meter readings', 'string', ['ref_meta_id' => $assetReadings->id, 'icon_cls' => 'chart', 'json_config' => $scope]],
            ['transfers', 'Transfers', 'string', ['ref_meta_id' => $assetTransfers->id, 'icon_cls' => 'truck', 'json_config' => $scope]],
        ]);

        $assetGrid = $this->meta('eam_assets_grid', 'Asset register', 'datagrid', 'eam_assets', 'Every asset the company owns.', null, [
            'filter_layout' => 'sidebar',
            'drilldown' => ['meta_id' => $workspace->id, 'params' => ['id' => 'id'], 'label' => 'Open workspace'],
        ]);
        $this->actions($assetGrid, $assetForm);
        $this->params($assetGrid, $assetColumns);

        $dashboard = $this->dashboard($categoryGrid, $locationGrid, $assetLookup, $assetGrid, $workOrderGrid);

        $app = $this->meta('eam_app', 'Assets', 'app', null, 'Enterprise asset management: the asset register, maintenance and value.');
        $this->params($app, [
            ['overview', 'Overview', 'string', ['icon_cls' => 'chart', 'ref_meta_id' => $dashboard->id]],
            ['assets', 'Assets', 'string', ['icon_cls' => 'package', 'children' => [
                ['asset_register', 'Asset register', 'string', ['ref_meta_id' => $assetGrid->id]],
                ['asset_workspace', 'Asset workspace', 'string', ['ref_meta_id' => $workspace->id]],
                ['transfers', 'Transfers', 'string', ['ref_meta_id' => $transferGrid->id]],
            ]]],
            ['maintenance', 'Maintenance', 'string', ['icon_cls' => 'tool', 'children' => [
                ['work_orders', 'Work orders', 'string', ['ref_meta_id' => $workOrderGrid->id]],
                ['maintenance_calendar', 'Maintenance calendar', 'string', ['ref_meta_id' => $calendar->id]],
                ['pm_plans', 'PM plans', 'string', ['ref_meta_id' => $planGrid->id]],
                ['pm_due', 'PM due now', 'string', ['ref_meta_id' => $planDue->id]],
                ['meter_readings', 'Meter readings', 'string', ['ref_meta_id' => $readingGrid->id]],
            ]]],
            ['setup', 'Setup', 'string', ['icon_cls' => 'settings', 'ref_meta_id' => $setup->id]],
        ]);
    }

    private function dashboard(Meta $categoryGrid, Meta $locationGrid, Meta $assetLookup, Meta $assetGrid, Meta $workOrderGrid): Meta
    {
        $assets = ['agg' => 'count', 'label' => 'Assets'];
        $spend = ['agg' => 'sum', 'column' => 'total_cost', 'label' => 'Spend'];
        $notDisposed = ['column' => 'status', 'op' => '!=', 'value' => 'disposed'];
        $openWork = [['column' => 'status', 'op' => '!=', 'value' => 'completed'], ['column' => 'status', 'op' => '!=', 'value' => 'cancelled']];
        $toAssets = fn (array $params) => ['drilldown' => ['meta_id' => $assetGrid->id, 'params' => $params]];
        $toWork = fn (array $params) => ['drilldown' => ['meta_id' => $workOrderGrid->id, 'params' => $params]];

        $charts = [
            [$this->chart('eam_chart_in_service', 'Assets in service', 'eam_assets', 'Working assets right now.',
                ['type' => 'kpi', 'measures' => [$assets], 'filters' => [['column' => 'status', 'op' => '=', 'value' => 'in service']]] + $toAssets(['status' => '=in service'])), 3, 1],
            [$this->chart('eam_chart_asset_cost', 'Asset cost', 'eam_assets', 'What the assets we still have cost.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'purchase_cost', 'label' => 'Cost']], 'filters' => [$notDisposed], 'format' => 'money']), 3, 1],
            [$this->chart('eam_chart_open_work', 'Open work orders', 'eam_work_orders', 'Work not yet completed or cancelled.',
                ['type' => 'kpi', 'measures' => [['agg' => 'count', 'label' => 'Work orders']], 'filters' => $openWork]), 3, 1],
            [$this->chart('eam_chart_spend', 'Maintenance spend', 'eam_work_orders', 'Labor and parts on work completed in the period.',
                ['type' => 'kpi', 'measures' => [$spend], 'date_field' => 'completed_on', 'format' => 'money']), 3, 1],
            [$this->chart('eam_chart_book_value', 'Net book value', 'eam_assets', 'What the assets are worth on the books today.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'carrying_amount', 'label' => 'Book value']], 'format' => 'money']), 4, 1],
            [$this->chart('eam_chart_depreciation', 'Depreciation', 'eam_asset_transactions', 'Depreciation posted in the period.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'amount', 'label' => 'Depreciation']], 'date_field' => 'txn_date', 'format' => 'money',
                    'filters' => [['column' => 'txn_type', 'op' => '=', 'value' => 'depreciation'], ['column' => 'status', 'op' => '=', 'value' => 'posted']]]), 4, 1],
            [$this->chart('eam_chart_revaluation_surplus', 'Revaluation surplus', 'eam_assets', 'Revaluation gains held in equity.',
                ['type' => 'kpi', 'measures' => [['agg' => 'sum', 'column' => 'revaluation_surplus', 'label' => 'Surplus']], 'format' => 'money']), 4, 1],
            [$this->chart('eam_chart_spend_by_month', 'Spend by month', 'eam_work_orders', 'Labor and parts, month by month.',
                ['type' => 'column', 'x' => 'completed_on', 'bucket' => 'month', 'date_field' => 'completed_on', 'format' => 'money', 'measures' => [
                    ['agg' => 'sum', 'column' => 'labor_cost', 'label' => 'Labor'],
                    ['agg' => 'sum', 'column' => 'parts_cost', 'label' => 'Parts'],
                ]]), 8, 2],
            [$this->chart('eam_chart_work_by_status', 'Work orders by status', 'eam_work_orders', 'Where the work stands.',
                ['type' => 'donut', 'x' => 'status', 'measures' => [['agg' => 'count', 'label' => 'Work orders']]] + $toWork(['status' => 'x'])), 4, 2],
            [$this->chart('eam_chart_assets_by_category', 'Assets by category', 'eam_assets', 'How many of each kind we have.',
                ['type' => 'bar', 'x' => 'category_id', 'x_lookup' => ['meta_id' => $categoryGrid->id, 'label_field' => 'name'], 'measures' => [$assets], 'filters' => [$notDisposed]] + $toAssets(['category_id' => 'x'])), 4, 2],
            [$this->chart('eam_chart_assets_by_location', 'Assets by location', 'eam_assets', 'Where the assets are.',
                ['type' => 'bar', 'x' => 'location_id', 'x_lookup' => ['meta_id' => $locationGrid->id, 'label_field' => 'name'], 'measures' => [$assets], 'filters' => [$notDisposed]] + $toAssets(['location_id' => 'x'])), 4, 2],
            [$this->chart('eam_chart_assets_by_status', 'Assets by status', 'eam_assets', 'In service, in repair, idle or disposed.',
                ['type' => 'donut', 'x' => 'status', 'measures' => [$assets]] + $toAssets(['status' => 'x'])), 4, 2],
            [$this->chart('eam_chart_cost_by_asset', 'Maintenance by asset', 'eam_work_orders', 'The assets that take the most work.',
                ['type' => 'table', 'x' => 'asset_id', 'x_lookup' => ['meta_id' => $assetLookup->id, 'label_field' => 'name'], 'date_field' => 'completed_on', 'measures' => [
                    $spend,
                    ['agg' => 'sum', 'column' => 'labor_hours', 'label' => 'Labor hours'],
                    ['agg' => 'count', 'label' => 'Work orders'],
                ]]), 12, 2],
        ];

        $dashboard = Meta::updateOrCreate(['code' => 'eam_overview'], [
            'name' => 'Asset overview',
            'type_id' => $this->types['dashboard'],
            'description' => 'Assets, their value and their maintenance at a glance.',
            'is_active' => true,
        ]);

        MetaParam::where('main_meta_id', $dashboard->id)->delete();
        foreach ($charts as $i => [$chart, $w, $h]) {
            $field = Str::after($chart->code, 'eam_chart_');
            MetaParam::create([
                'main_meta_id' => $dashboard->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'data_type' => 'chart',
                'ref_meta_id' => $chart->id,
                'json_config' => ['w' => $w, 'h' => $h],
            ]);
        }

        return $dashboard;
    }

    private function chart(string $code, string $name, string $table, string $description, array $config): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types['chart'],
            'table_name' => $table,
            'description' => $description,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }

    private function stageQuery(string $code, string $name, string $table, string $sql, string $description, array $params): Meta
    {
        $query = $this->meta($code, $name, 'stage_query', $table, $description, null, null, $sql);
        $this->params($query, $params);

        return $query;
    }

    /** Replaces a form's process flow with one step, run after a new record when the criteria hold. */
    private function flow(Meta $form, Meta $step, array $behaviours, ?array $criteria = null): void
    {
        MetaProcessWorkflow::where('main_process_id', $form->id)->delete();
        $workflow = MetaProcessWorkflow::create(['main_process_id' => $form->id, 'process_id' => $step->id, 'order_number' => 1]);
        if ($criteria) {
            MetaProcessTransition::create(['main_process_id' => $form->id, 'source_id' => null, 'target_id' => $workflow->id, 'criteria' => $criteria]);
        }
        foreach ($behaviours as $target => $source) {
            MetaProcessBehaviour::create(['workflow_id' => $workflow->id, 'source_path' => $source, 'target_path' => $target]);
        }
    }

    /** Adds the "Complete" button after a grid's Add/Edit/Delete. */
    private function completeButton(Meta $grid, Meta $complete): void
    {
        MetaAction::create([
            'grid_meta_id' => $grid->id,
            'action' => 'stage_query',
            'label' => 'Complete',
            'icon_cls' => 'check',
            'form_meta_id' => $complete->id,
            'needs_row' => true,
            'show_in_row' => true,
            'order_number' => 4,
            'json_config' => ['params' => ['work_order_id' => 'id']],
        ]);
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
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

            // The row's own attributes win, e.g. is_show false for a hidden asset_id.
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

    private function sampleData(): void
    {
        if (DB::table('eam_assets')->exists()) {
            return;
        }
        $now = now();
        $stamp = fn (array $row) => $row + ['created_at' => $now, 'updated_at' => $now];
        $today = Carbon::today();
        $day = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $at = fn (int $n, string $time) => $today->copy()->addDays($n)->setTimeFromTimeString($time)->toDateTimeString();

        $locations = [];
        // Each inside the one before it that it names (null: at the top): the campus goes five deep, down to its racks.
        foreach ([
            ['UB-C', 'Ulaanbaatar campus', 'site', 'Peace Avenue, Ulaanbaatar', null],
            ['HQ', 'Head office', 'building', 'Peace Avenue 17, Ulaanbaatar', 'UB-C'],
            ['HQ-F2', 'Head office, floor 2', 'floor', 'Head office, floor 2', 'HQ'],
            ['HQ-DC', 'HQ server room', 'room', 'Head office, floor 2', 'HQ-F2'],
            ['HQ-DC-R1', 'Server rack 1', 'rack', 'HQ server room, row A', 'HQ-DC'],
            ['HQ-DC-R2', 'Server rack 2', 'rack', 'HQ server room, row A', 'HQ-DC'],
            ['PLT-1', 'Plant 1', 'site', 'Industrial zone, Khan-Uul', null],
            ['WH-1', 'Central warehouse', 'warehouse', 'Industrial zone, Khan-Uul', 'PLT-1'],
            ['YRD', 'Vehicle yard', 'yard', 'Industrial zone, Khan-Uul', 'PLT-1'],
        ] as [$code, $name, $kind, $address, $inside]) {
            $locations[$code] = DB::table('eam_locations')->insertGetId($stamp(['code' => $code, 'name' => $name, 'kind' => $kind, 'address' => $address, 'parent_id' => $inside ? $locations[$inside] : null]));
        }

        $categories = [];
        foreach ([
            ['IT equipment', 4, 'Laptops, servers, network gear.'],
            ['Vehicles', 8, 'Cars, trucks and forklifts.'],
            ['Production machinery', 12, 'Machines on the production line.'],
            ['HVAC', 15, 'Heating, ventilation and air conditioning.'],
            ['Power', 15, 'Generators and UPS units.'],
            ['Furniture', 10, null],
        ] as [$name, $life, $notes]) {
            $categories[$name] = DB::table('eam_categories')->insertGetId($stamp(['name' => $name, 'useful_life_years' => $life, 'notes' => $notes]));
        }

        $assets = [];
        $yy = $today->format('y');
        foreach ([
            ['Core switch', 'IT equipment', 'HQ-DC', 'Cisco', 'Catalyst 9300', -900, 18500000, 'in service', 'good', 'critical', 'IT', null, 400],
            ['File server', 'IT equipment', 'HQ-DC', 'Dell', 'PowerEdge R650', -700, 32000000, 'in service', 'good', 'high', 'IT', null, 400],
            ['Laptop – finance lead', 'IT equipment', 'HQ', 'Lenovo', 'ThinkPad T14', -300, 4200000, 'in service', 'good', 'low', 'Finance', 'Oyunaa B.', 430],
            ['Delivery truck 1', 'Vehicles', 'YRD', 'Isuzu', 'NPR 75', -1400, 98000000, 'in service', 'fair', 'high', 'Logistics', 'Bat-Erdene D.', -100],
            ['Delivery truck 2', 'Vehicles', 'YRD', 'Isuzu', 'NPR 75', -1100, 102000000, 'in repair', 'fair', 'high', 'Logistics', 'Tuvshin G.', 0],
            ['Forklift', 'Vehicles', 'WH-1', 'Toyota', '8FBE15', -800, 54000000, 'in service', 'good', 'medium', 'Logistics', null, 300],
            ['CNC lathe', 'Production machinery', 'PLT-1', 'Haas', 'ST-20', -1800, 245000000, 'in service', 'good', 'critical', 'Production', null, -600],
            ['Packaging line', 'Production machinery', 'PLT-1', 'Bosch', 'Pack 403', -1200, 310000000, 'in service', 'fair', 'critical', 'Production', null, -100],
            ['Air compressor', 'Production machinery', 'PLT-1', 'Atlas Copco', 'GA 37', -2000, 68000000, 'in repair', 'poor', 'high', 'Production', null, -900],
            ['Rooftop AC unit', 'HVAC', 'HQ', 'Daikin', 'VRV IV', -1500, 76000000, 'in service', 'good', 'medium', 'Facilities', null, 300],
            ['Warehouse heater', 'HVAC', 'WH-1', 'Systemair', 'TOP 65', -2400, 21000000, 'idle', 'fair', 'low', 'Facilities', null, -1200],
            ['Standby generator', 'Power', 'PLT-1', 'Caterpillar', 'C18', -1600, 185000000, 'in service', 'good', 'critical', 'Facilities', null, 200],
            ['Server room UPS', 'Power', 'HQ-DC', 'APC', 'Smart-UPS SRT 10kVA', -600, 14500000, 'in service', 'good', 'high', 'IT', null, 500],
            ['Boardroom table', 'Furniture', 'HQ', 'Steelcase', 'Media:scape', -1300, 6800000, 'in service', 'good', 'low', 'Facilities', null, null],
            ['Old office printer', 'IT equipment', 'HQ', 'HP', 'LaserJet M607', -2300, 3900000, 'disposed', 'poor', 'low', 'Facilities', null, -1500],
        ] as $i => [$name, $category, $location, $maker, $model, $bought, $cost, $status, $condition, $criticality, $department, $assignee, $warranty]) {
            $assets[$name] = DB::table('eam_assets')->insertGetId($stamp([
                'asset_tag' => sprintf('AST-%s%04d', $yy, $i + 1),
                'name' => $name,
                'category_id' => $categories[$category],
                'location_id' => $locations[$location],
                'manufacturer' => $maker,
                'model' => $model,
                'serial_no' => strtoupper(substr($maker, 0, 3)).'-'.(48213 + $i * 731),
                'purchase_date' => $day($bought),
                'purchase_cost' => $cost,
                'salvage_value' => round($cost * 0.1, -3),
                'warranty_until' => $warranty === null ? null : $day($warranty),
                'status' => $status,
                'condition' => $condition,
                'criticality' => $criticality,
                'department' => $department,
                'assigned_to' => $assignee,
            ]));
        }

        $plans = [];
        foreach ([
            ['Delivery truck 1', 'Oil and filter change', 'preventive', 'medium', 90, -2, 'Ganzorig', "Drain and replace engine oil\nReplace oil and air filters\nCheck brakes and tyres"],
            ['Delivery truck 2', 'Oil and filter change', 'preventive', 'medium', 90, 40, 'Ganzorig', "Drain and replace engine oil\nReplace oil and air filters"],
            ['Forklift', 'Battery and mast check', 'inspection', 'medium', 30, 0, 'Ganzorig', "Check battery water and charge\nInspect mast chains and forks"],
            ['CNC lathe', 'Spindle lubrication', 'preventive', 'high', 30, 5, 'Enkhbold', "Top up spindle lubricant\nClean chip conveyor\nCheck coolant concentration"],
            ['Packaging line', 'Belt and sensor service', 'preventive', 'high', 60, 12, 'Enkhbold', "Inspect and tension belts\nClean photo sensors"],
            ['Air compressor', 'Filter and separator', 'preventive', 'high', 120, -10, 'Enkhbold', "Replace intake filter\nReplace oil separator"],
            ['Rooftop AC unit', 'Seasonal service', 'preventive', 'medium', 180, 25, 'Khulan', "Clean coils and filters\nCheck refrigerant pressure"],
            ['Standby generator', 'Monthly load test', 'inspection', 'critical', 30, 3, 'Khulan', "Run 30 minutes under load\nCheck fuel, oil and coolant"],
            ['Server room UPS', 'Battery test', 'inspection', 'high', 180, 60, 'Saraa', 'Run self-test and record runtime'],
            ['Standby generator', 'Annual calibration', 'calibration', 'medium', 365, 120, 'Khulan', 'Calibrate voltage regulator and gauges'],
        ] as [$asset, $title, $type, $priority, $interval, $due, $tech, $tasks]) {
            $plans["{$asset}|{$title}"] = DB::table('eam_pm_plans')->insertGetId($stamp([
                'asset_id' => $assets[$asset], 'title' => $title, 'type' => $type, 'priority' => $priority,
                'interval_days' => $interval, 'next_due' => $day($due), 'last_done' => $day($due - $interval),
                'technician' => $tech, 'task_list' => $tasks, 'is_active' => true,
            ]));
        }

        $ym = $today->format('ym');
        $workOrders = [
            // [asset, plan, title, type, priority, status, technician, start offset, hours, labor/hour, parts, resolution]
            ['Delivery truck 2', null, 'Gearbox grinding in 3rd gear', 'corrective', 'high', 'in progress', 'Ganzorig', -2, 6, 45000, [['Gearbox synchro ring', 2, 380000], ['Gear oil 75W-90 (l)', 4, 32000]], null],
            ['Air compressor', null, 'Overheating, trips after 20 minutes', 'corrective', 'critical', 'on hold', 'Enkhbold', -4, 3, 50000, [['Thermostatic valve', 1, 640000]], 'Waiting for the valve to arrive.'],
            ['Core switch', null, 'Firmware update', 'inspection', 'medium', 'assigned', 'Saraa', 2, 2, 60000, [], null],
            ['Packaging line', $plans['Packaging line|Belt and sensor service'] ?? null, 'Belt and sensor service', 'preventive', 'high', 'open', 'Enkhbold', 12, 0, 0, [], null],
            ['Standby generator', null, 'Monthly load test', 'inspection', 'critical', 'completed', 'Khulan', -27, 1.5, 50000, [], 'Ran 30 min at 70% load, all readings normal.'],
            ['CNC lathe', null, 'Spindle lubrication', 'preventive', 'high', 'completed', 'Enkhbold', -25, 2, 50000, [['Spindle lubricant (l)', 2, 85000]], 'Done; coolant topped up.'],
            ['Forklift', null, 'Battery and mast check', 'inspection', 'medium', 'completed', 'Ganzorig', -30, 1, 45000, [], 'Battery at 92%.'],
            ['Delivery truck 1', null, 'Oil and filter change', 'preventive', 'medium', 'completed', 'Ganzorig', -92, 2, 45000, [['Engine oil 15W-40 (l)', 12, 28000], ['Oil filter', 1, 65000], ['Air filter', 1, 90000]], 'Done at 182,400 km.'],
            ['Delivery truck 1', null, 'Replace front brake pads', 'corrective', 'high', 'completed', 'Ganzorig', -60, 3, 45000, [['Brake pad set', 1, 420000]], 'Pads and one disc replaced.'],
            ['Rooftop AC unit', null, 'Seasonal service', 'preventive', 'medium', 'completed', 'Khulan', -155, 4, 50000, [['Filter set', 1, 260000]], 'Coils cleaned.'],
            ['Air compressor', null, 'Oil leak at separator', 'corrective', 'high', 'completed', 'Enkhbold', -120, 5, 50000, [['Oil separator', 1, 980000], ['Compressor oil (l)', 10, 54000]], 'Separator and seals replaced.'],
            ['File server', null, 'Disk failure in RAID array', 'corrective', 'critical', 'completed', 'Saraa', -75, 2, 60000, [['1.2 TB SAS disk', 1, 1350000]], 'Disk swapped, array rebuilt.'],
            ['Packaging line', null, 'Jam at the sealing station', 'corrective', 'critical', 'completed', 'Enkhbold', -45, 6, 50000, [['Sealing jaw heater', 1, 1150000]], 'Heater replaced, line back after 6 hours.'],
            ['CNC lathe', null, 'Tool turret misalignment', 'calibration', 'high', 'completed', 'Enkhbold', -140, 4, 50000, [], 'Turret realigned and test parts in tolerance.'],
            ['Standby generator', null, 'Annual calibration', 'calibration', 'medium', 'completed', 'Khulan', -170, 3, 50000, [['Fuel filter', 2, 120000]], 'Calibrated.'],
            ['Warehouse heater', null, 'Burner won’t ignite', 'corrective', 'medium', 'cancelled', 'Khulan', -20, 0, 0, [], 'Heater taken out of use instead.'],
        ];
        foreach ($workOrders as $i => [$asset, $plan, $title, $type, $priority, $status, $tech, $start, $hours, $rate, $parts, $resolution]) {
            $done = $status === 'completed';
            $partsCost = array_sum(array_map(fn ($p) => $p[1] * $p[2], $parts));
            $id = DB::table('eam_work_orders')->insertGetId($stamp([
                'wo_no' => sprintf('WO-%s%04d', $ym, $i + 1),
                'asset_id' => $assets[$asset],
                'pm_plan_id' => $plan,
                'title' => $title,
                'type' => $type,
                'priority' => $priority,
                'status' => $status,
                'reported_by' => $type === 'corrective' ? 'Operations desk' : 'PM schedule',
                'technician' => $tech,
                'reported_on' => $day(min($start, 0) - 1),
                'scheduled_start' => $at($start, '09:00'),
                'scheduled_end' => $at($start, sprintf('%02d:00', 9 + max(1, (int) ceil($hours)))),
                'completed_on' => $done ? $day($start) : null,
                'labor_hours' => $hours,
                'labor_cost' => $hours * $rate,
                'parts_cost' => $partsCost,
                'description' => $title,
                'resolution' => $resolution,
            ]));
            foreach ($parts as [$part, $quantity, $unitCost]) {
                DB::table('eam_work_order_parts')->insert($stamp(['work_order_id' => $id, 'part_name' => $part, 'quantity' => $quantity, 'unit_cost' => $unitCost]));
            }
        }

        foreach ([
            ['Delivery truck 1', 'km', 182400, 1150, 'Bat-Erdene D.'],
            ['Delivery truck 2', 'km', 151900, 980, 'Tuvshin G.'],
            ['Forklift', 'hours', 3120, 42, 'Ganzorig'],
            ['Standby generator', 'hours', 860, 3, 'Khulan'],
            ['Air compressor', 'hours', 18450, 160, 'Enkhbold'],
        ] as [$asset, $meter, $start, $step, $reader]) {
            foreach (range(5, 0) as $k => $weeksAgo) {
                DB::table('eam_meter_readings')->insert($stamp([
                    'asset_id' => $assets[$asset], 'read_on' => $day(-7 * $weeksAgo), 'meter' => $meter,
                    'value' => $start + $k * $step, 'read_by' => $reader,
                ]));
            }
        }

        foreach ([
            ['Laptop – finance lead', 'WH-1', 'HQ', -300, 'Oyunaa B.', 'New hire equipment'],
            ['Forklift', 'YRD', 'WH-1', -400, null, 'Moved to the central warehouse'],
            ['Delivery truck 2', 'YRD', 'YRD', -200, 'Tuvshin G.', 'New driver'],
            ['Warehouse heater', 'PLT-1', 'WH-1', -900, null, 'Plant got central heating'],
        ] as [$asset, $from, $to, $on, $assignee, $reason]) {
            DB::table('eam_asset_transfers')->insert($stamp([
                'asset_id' => $assets[$asset], 'from_location_id' => $locations[$from], 'to_location_id' => $locations[$to],
                'moved_on' => $day($on), 'assigned_to' => $assignee, 'reason' => $reason,
            ]));
        }
    }
}
