<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\User;
use Throwable;

/**
 * Runs a form's process flow after it saves a new record (see the meta_process_* tables).
 *
 * Steps start right after the main form when no transition leads to them, or when a
 * transition from the main form (no source) matches its record. After a step saves, its
 * transitions whose criteria match the step's record start their targets. Each step runs at
 * most once per save, in that order (steps starting together by order_number).
 *
 * Steps save through their form like a person would (MetaDataController::storeAs), as the
 * same user, so a step form with its own flow runs that too, up to MAX_DEPTH deep. A step
 * can be a stage query instead: its behaviours give its :named parameters, and what it
 * passes on is those values plus affected (rows changed). Called inside the main form's
 * transaction: a step refusing stops with 422 and undoes everything.
 */
class ProcessFlow
{
    public const MAX_DEPTH = 5;

    private static int $depth = 0;

    /**
     * @return list<array{step_id: int, process_id: int, process: string, id: mixed}> what each step saved
     */
    public static function run(Meta $main, object $record, ?User $actor): array
    {
        $steps = MetaProcessWorkflow::where('main_process_id', $main->id)->with(['process.type', 'behaviours'])->orderBy('order_number')->orderBy('id')->get()->keyBy('id');
        if ($steps->isEmpty()) {
            return [];
        }
        abort_if(self::$depth >= self::MAX_DEPTH, 422, 'The process flow goes more than '.self::MAX_DEPTH.' forms deep. Check that no form runs itself.');

        $transitions = MetaProcessTransition::where('main_process_id', $main->id)->orderBy('id')->get();
        $led = $transitions->pluck('target_id')->all();

        // Each entry: the step to run and the record it takes its values from.
        $queue = [];
        foreach ($steps as $step) {
            if (! in_array($step->id, $led, true)) {
                $queue[] = [$step, $record];
            }
        }
        foreach ($transitions->whereNull('source_id') as $t) {
            if ($steps->has($t->target_id) && Notifier::conditionsMet($t->criteria ?? [], $record)) {
                $queue[] = [$steps[$t->target_id], $record];
            }
        }

        $ran = [];
        $done = [];
        while ($queue) {
            [$step, $previous] = array_shift($queue);
            if (isset($done[$step->id])) {
                continue;
            }
            $done[$step->id] = true;
            $saved = self::runStep($step, $previous, $record, $actor);
            $ran[] = ['step_id' => $step->id, 'process_id' => $step->process_id, 'process' => $step->process->name, 'id' => $saved->id ?? null];

            foreach ($transitions->where('source_id', $step->id) as $t) {
                if ($steps->has($t->target_id) && Notifier::conditionsMet($t->criteria ?? [], $saved)) {
                    $queue[] = [$steps[$t->target_id], $saved];
                }
            }
        }

        return $ran;
    }

    /** Saves one step's form (or runs its stage query) with the values its behaviours give, or stops with 422 naming the step. */
    private static function runStep(MetaProcessWorkflow $step, object $previous, object $main, ?User $actor): object
    {
        $type = $step->process?->type?->code;
        abort_unless(in_array($type, ['form', 'stage_query'], true), 422, 'A process flow step must be a form or a stage query.');
        $values = [];
        foreach ($step->behaviours as $behaviour) {
            $values[$behaviour->target_path] = self::value($behaviour, $previous, $main);
        }

        self::$depth++;
        try {
            if ($type === 'stage_query') {
                return (object) ($values + ['affected' => StageQuery::run($step->process, $values, $actor)['affected']]);
            }

            return RecordLog::as('process_flow', fn () => MetaDataController::storeAs($actor, $step->process, $values));
        } catch (Throwable $e) {
            abort(422, "{$step->process->name}: ".MetaDataController::failure($e));
        } finally {
            self::$depth--;
        }
    }

    /** "=text" is that text, "main.field" the main record's field, anything else the previous record's. */
    private static function value(MetaProcessBehaviour $behaviour, object $previous, object $main): mixed
    {
        $path = trim($behaviour->source_path);

        return match (true) {
            str_starts_with($path, '=') => substr($path, 1),
            str_starts_with($path, 'main.') => $main->{substr($path, 5)} ?? null,
            default => $previous->{$path} ?? null,
        };
    }
}
