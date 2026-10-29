<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\MetaParam;
use App\Models\MetaSchedule;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs scheduled forms. For each meta_schedules row, every row of the datagrid (view_id) is
 * saved through the form (process_id) as a new record, the way a person would save it: the
 * same permissions, validation, detail lines and notifications. A form field takes the
 * grid column of the same name, so a grid built on a query can rename its columns to fit
 * the form (SELECT id AS contract_id, ...). The form's own key is never copied.
 *
 * All the grid's rows are read before any is saved, so a form writing into the grid's own
 * table doesn't run over the records it has just made. Each row saves on its own: one that
 * fails is reported and the rest still save.
 *
 * The process can be a stage query instead: it runs once per row, each :named parameter
 * taking the grid column of the same name; created then counts the runs, and affected
 * the rows they changed.
 */
class ScheduledForms
{
    /** A run reads up to this many of a grid's rows. */
    public const MAX_ROWS = 5000;

    /**
     * Runs every schedule that is due now.
     *
     * @return array<string, array<int, array{process: string, view: string, created: int, failed: array, message: ?string}>> results by schedule code
     */
    public static function runDue(): array
    {
        $results = [];
        foreach (Schedule::with('forms')->get() as $schedule) {
            if ($schedule->isDue()) {
                $results[$schedule->code] = self::runSchedule($schedule);
            }
        }

        return $results;
    }

    /**
     * Runs all of a schedule's forms now. Without a user, they run as the first admin.
     *
     * @return array<int, array{process: string, view: string, created: int, affected: ?int, failed: array, message: ?string}>
     */
    public static function runSchedule(Schedule $schedule, ?User $runner = null): array
    {
        $runner ??= User::whereHas('roles', fn ($q) => $q->where('code', Role::ADMIN))->orderBy('id')->first();

        return $schedule->forms()->with(['process.type', 'view'])->orderBy('id')->get()
            ->map(fn (MetaSchedule $link) => self::run($link, $runner))
            ->all();
    }

    /**
     * Saves the form (or runs the stage query) once for each row of the grid.
     *
     * @return array{process: string, view: string, created: int, affected: ?int, failed: array<int, array{row: mixed, message: string}>, message: ?string}
     */
    public static function run(MetaSchedule $link, ?User $runner): array
    {
        $result = ['process' => $link->process?->name ?? '?', 'view' => $link->view?->name ?? '?', 'created' => 0, 'affected' => null, 'failed' => [], 'message' => null];

        try {
            abort_unless($runner, 422, 'There is no administrator to run scheduled forms as.');
            $rows = self::rows($link, $runner);
            $fields = self::fields($link);
            $isQuery = $link->process->type?->code === 'stage_query';
            abort_if($fields === [] && ! $isQuery, 422, 'The form has no field named like a column of the grid.');

            foreach ($rows as $row) {
                $payload = array_intersect_key($row, array_flip($fields));
                try {
                    if ($isQuery) {
                        $result['affected'] = ($result['affected'] ?? 0) + StageQuery::run($link->process, $payload, $runner)['affected'];
                    } else {
                        RecordLog::as('schedule', fn () => MetaDataController::storeAs($runner, $link->process, $payload));
                    }
                    $result['created']++;
                } catch (Throwable $e) {
                    $result['failed'][] = ['row' => $row['id'] ?? null, 'message' => MetaDataController::failure($e)];
                }
            }
        } catch (Throwable $e) {
            $result['message'] = MetaDataController::failure($e);
        }

        $level = $result['message'] || $result['failed'] ? 'warning' : 'info';
        Log::$level("Scheduled form {$result['process']} over {$result['view']}: {$result['created']} saved, ".count($result['failed']).' failed.', $result);

        return $result;
    }

    /**
     * Every row of the grid, as the runner would see it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rows(MetaSchedule $link, User $runner): array
    {
        abort_unless($link->view?->type?->code === 'datagrid', 422, 'The view must be a datagrid.');

        return MetaDataController::rowsAs($runner, $link->view, [], self::MAX_ROWS);
    }

    /**
     * The form fields a grid row can fill: all but its key and detail lines. For a stage
     * query, its parameters the grid has a column for.
     *
     * @return string[]
     */
    private static function fields(MetaSchedule $link): array
    {
        $type = $link->process?->type?->code;
        abort_unless(in_array($type, ['form', 'stage_query'], true), 422, 'The process must be a form or a stage query.');
        $params = MetaParam::where('main_meta_id', $link->process_id)->get();
        $key = $type === 'form' ? ($params->firstWhere('is_primary', true)?->field_path ?? 'id') : null;

        $viewColumns = MetaParam::where('main_meta_id', $link->view_id)->pluck('field_path')->all();

        return $params
            ->reject(fn (MetaParam $p) => $p->field_path === $key || in_array($p->data_type, ['detail', 'subgrid', 'singlefile', 'multifile', 'document'], true))
            ->pluck('field_path')
            ->intersect($viewColumns)
            ->values()
            ->all();
    }
}
