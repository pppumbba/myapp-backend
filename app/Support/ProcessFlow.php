<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\MetaDataController;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 * passes on is those values plus affected (rows changed). Or a get: its behaviours give its
 * :named parameters, and it passes on the first row its query finds (nothing: all empty).
 * Called inside the main form's transaction: a step refusing stops with 422 and undoes everything.
 *
 * A behaviour's source_path: "=text" a fixed value, "main.field" the main record's,
 * "stepN.field" what the step with order_number N passed on (empty until it has run), and a
 * bare "field" the record of whatever led to this step.
 */
class ProcessFlow
{
    public const MAX_DEPTH = 5;

    /** The kinds of meta a step can be. */
    public const STEP_TYPES = ['form', 'stage_query', 'get'];

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
        $passed = [];
        while ($queue) {
            [$step, $previous] = array_shift($queue);
            if (isset($done[$step->id])) {
                continue;
            }
            $done[$step->id] = true;
            $saved = self::runStep($step, $previous, $record, $passed, $actor);
            $passed[$step->order_number] = $saved;
            $ran[] = ['step_id' => $step->id, 'process_id' => $step->process_id, 'process' => $step->process->name, 'id' => $saved->id ?? null];

            foreach ($transitions->where('source_id', $step->id) as $t) {
                if ($steps->has($t->target_id) && Notifier::conditionsMet($t->criteria ?? [], $saved)) {
                    $queue[] = [$steps[$t->target_id], $saved];
                }
            }
        }

        return $ran;
    }

    /**
     * Saves one step's form (or runs its stage query or get) with the values its behaviours
     * give, or stops with 422 naming the step.
     *
     * @param  array<int, object>  $passed  what the steps run so far passed on, by order_number
     */
    private static function runStep(MetaProcessWorkflow $step, object $previous, object $main, array $passed, ?User $actor): object
    {
        $type = $step->process?->type?->code;
        abort_unless(in_array($type, self::STEP_TYPES, true), 422, 'A process flow step must be a form, a stage query or a get.');
        $values = [];
        foreach ($step->behaviours as $behaviour) {
            $values[$behaviour->target_path] = self::value($behaviour, $previous, $main, $passed);
        }

        self::$depth++;
        try {
            if ($type === 'stage_query') {
                return (object) ($values + ['affected' => StageQuery::run($step->process, $values, $actor)['affected']]);
            }
            if ($type === 'get') {
                return MetaDataController::fillAs($actor, $step->process, $values);
            }

            return RecordLog::as('process_flow', fn () => MetaDataController::storeAs($actor, $step->process, $values));
        } catch (Throwable $e) {
            abort(422, "{$step->process->name}: ".MetaDataController::failure($e));
        } finally {
            self::$depth--;
        }
    }

    /**
     * "=text" is that text, "main.field" the main record's field, "stepN.field" what step N
     * passed on, anything else the previous record's.
     *
     * @param  array<int, object>  $passed
     */
    private static function value(MetaProcessBehaviour $behaviour, object $previous, object $main, array $passed): mixed
    {
        $path = trim($behaviour->source_path);
        if (preg_match('/^step(\d+)\.(.+)$/', $path, $m)) {
            return ($passed[(int) $m[1]] ?? null)?->{$m[2]} ?? null;
        }

        return match (true) {
            str_starts_with($path, '=') => substr($path, 1),
            str_starts_with($path, 'main.') => $main->{substr($path, 5)} ?? null,
            default => $previous->{$path} ?? null,
        };
    }

    /**
     * What a node on the builder's canvas takes in and passes on, as field/label pairs.
     * A form takes its fields (not its key or lines) and passes on the saved record (with id).
     * A stage query takes its parameters and passes them on plus affected. A get takes its
     * parameters (and any other :name in its query) and passes on its query's columns.
     *
     * @return array{inputs: list<array{field: string, label: string}>, outputs: list<array{field: string, label: string}>}
     */
    public static function ports(Meta $meta, ?User $user): array
    {
        $params = $meta->params()->whereNull('parent_id')->orderBy('order_number')->get()
            ->reject(fn (MetaParam $p) => in_array($p->data_type, ['detail', 'subgrid'], true));
        $port = fn (string $field, ?string $label = null) => ['field' => $field, 'label' => $label ?: $field];
        $all = $params->map(fn (MetaParam $p) => $port($p->field_path, $p->label_name))->values()->all();

        switch ($meta->type?->code) {
            case 'form':
                $inputs = $params->reject(fn (MetaParam $p) => $p->is_primary || $p->field_path === 'id')
                    ->map(fn (MetaParam $p) => $port($p->field_path, $p->label_name))->values()->all();
                $outputs = collect($all)->contains('field', 'id') ? $all : [$port('id'), ...$all];

                return ['inputs' => $inputs, 'outputs' => $outputs];
            case 'stage_query':
                return ['inputs' => $all, 'outputs' => [...$all, $port('affected', 'Rows changed')]];
            case 'get':
                $sql = trim((string) $meta->query_string);
                $named = $sql === '' ? [] : array_diff(array_unique(NamedSql::bind($sql, [])[2]), array_keys(NamedSql::builtins($user)));
                $inputs = collect($all);
                foreach ($named as $name) {
                    if (! $inputs->contains('field', $name)) {
                        $inputs->push($port($name));
                    }
                }

                return ['inputs' => $inputs->values()->all(), 'outputs' => array_map($port, self::columns($sql, $user))];
            default:
                return ['inputs' => [], 'outputs' => []];
        }
    }

    /**
     * The columns a get's query returns, read from the statement without fetching a row
     * (every :name empty). A query that cannot run that way gives none.
     *
     * @return list<string>
     */
    private static function columns(string $sql, ?User $user): array
    {
        if ($sql === '') {
            return [];
        }
        try {
            [$sql, $bindings] = NamedSql::bind(rtrim($sql, "; \n\r\t"), NamedSql::builtins($user));
            $statement = DB::getPdo()->prepare("SELECT * FROM ({$sql}) process_flow_columns LIMIT 0");
            $statement->execute($bindings);
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $columns[] = (string) $statement->getColumnMeta($i)['name'];
            }

            return $columns;
        } catch (Throwable) {
            return [];
        }
    }
}
