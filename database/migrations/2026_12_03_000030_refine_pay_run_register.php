<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The payroll register opens from a run's number instead of an action: 2026_12_03_000029 gave it
 * a custom action, but those open forms, and the register is a report. The row's own drilldown
 * is taken by Payslips, so the register hangs on the run_no column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $grid = Meta::where('code', 'pay_runs_grid')->first();
        $report = Meta::where('code', 'pay_run_report')->first();
        if (! $grid || ! $report) {
            return;
        }

        DB::transaction(function () use ($grid, $report) {
            MetaAction::where('grid_meta_id', $grid->id)->where('form_meta_id', $report->id)->delete();
            MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'run_no')->first()?->update([
                'json_config' => ['drilldown' => ['meta_id' => $report->id, 'params' => ['id' => 'id']]],
            ]);
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $grid = Meta::where('code', 'pay_runs_grid')->first();
        if ($grid) {
            MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'run_no')->first()?->update(['json_config' => null]);
        }
    }
};
