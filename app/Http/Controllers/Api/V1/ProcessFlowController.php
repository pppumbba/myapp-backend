<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Support\Access;
use App\Support\ProcessFlow;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A form's process flow, for the builder: its steps (meta_process_workflow) with their
 * behaviours and canvas spots, and the transitions between them. Saved whole, like a page's layout.
 */
class ProcessFlowController extends Controller
{
    /** GET /metas/{meta}/process-flow */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireForm($meta);

        return response()->json($this->flow($meta));
    }

    /**
     * GET /metas/{meta}/process-flow/ports?ids=1,2 — what this form and these metas take in
     * and pass on, by meta id (see ProcessFlow::ports).
     */
    public function ports(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireForm($meta);

        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));
        $metas = Meta::with('type')->whereIn('id', [...$ids, $meta->id])->get();

        return response()->json((object) $metas->mapWithKeys(fn (Meta $m) => [$m->id => ProcessFlow::ports($m, $request->user())])->all());
    }

    /**
     * PUT /metas/{meta}/process-flow
     * {main_at: {x, y}|null,
     *  steps: [{key, process_id, x, y, behaviours: [{source_path, target_path}]}],
     *  transitions: [{source: key|null, target: key, criteria: [{field, op, value}]}]}
     * Keys only tie transitions and values to steps within this request; the old flow is
     * replaced. A value from another step comes as "step:key.field" and is kept as
     * "stepN.field", N being that step's place in the list (its order_number).
     */
    public function save(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireForm($meta);

        $data = $request->validate([
            'steps' => ['present', 'array'],
            'steps.*.key' => ['required', 'string', 'distinct'],
            'steps.*.process_id' => ['required', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($meta) {
                if ((int) $value === $meta->id) {
                    $fail('A form cannot run itself.');
                } elseif (! Meta::whereKey($value)->whereHas('type', fn ($q) => $q->whereIn('code', ProcessFlow::STEP_TYPES))->exists()) {
                    $fail('Pick a form, a stage query or a get.');
                }
            }],
            'steps.*.x' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'steps.*.y' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'main_at' => ['nullable', 'array'],
            'main_at.x' => ['required_with:main_at', 'integer', 'min:0', 'max:20000'],
            'main_at.y' => ['required_with:main_at', 'integer', 'min:0', 'max:20000'],
            'steps.*.behaviours' => ['array'],
            'steps.*.behaviours.*.source_path' => ['required', 'string', 'max:255'],
            'steps.*.behaviours.*.target_path' => ['required', 'string', 'max:255'],
            'transitions' => ['present', 'array'],
            'transitions.*.source' => ['nullable', 'string'],
            'transitions.*.target' => ['required', 'string'],
            'transitions.*.criteria' => ['nullable', 'array'],
            'transitions.*.criteria.*.field' => ['required', 'string', 'max:255'],
            'transitions.*.criteria.*.op' => ['required', 'in:=,!=,>,<,>=,<=,contains,empty,not_empty'],
            'transitions.*.criteria.*.value' => ['nullable'],
        ]);

        $keys = array_column($data['steps'], 'key');
        foreach ($data['transitions'] as $i => $t) {
            abort_unless(in_array($t['target'], $keys, true) && ($t['source'] === null || in_array($t['source'], $keys, true)), 422, 'Transition '.($i + 1).' points at a step that is not in the flow.');
            abort_if($t['source'] === $t['target'], 422, 'Transition '.($i + 1).' leads a step to itself.');
        }
        $order = array_flip($keys);
        foreach ($data['steps'] as $i => $s) {
            foreach ($s['behaviours'] ?? [] as $j => $b) {
                if (preg_match('/^step:(.+?)\.(.+)$/', $b['source_path'], $m)) {
                    abort_unless(isset($order[$m[1]]), 422, 'Step '.($i + 1).' takes a value from a step that is not in the flow.');
                    $data['steps'][$i]['behaviours'][$j]['source_path'] = 'step'.$order[$m[1]].'.'.$m[2];
                }
            }
        }

        DB::transaction(function () use ($meta, $data) {
            MetaProcessWorkflow::where('main_process_id', $meta->id)->delete();
            $ids = [];
            foreach ($data['steps'] as $order => $s) {
                $step = MetaProcessWorkflow::create(['main_process_id' => $meta->id, 'process_id' => $s['process_id'], 'order_number' => $order, 'pos_x' => $s['x'] ?? null, 'pos_y' => $s['y'] ?? null]);
                $step->behaviours()->createMany(array_map(fn (array $b) => ['source_path' => $b['source_path'], 'target_path' => $b['target_path']], $s['behaviours'] ?? []));
                $ids[$s['key']] = $step->id;
            }
            foreach ($data['transitions'] as $t) {
                MetaProcessTransition::create([
                    'main_process_id' => $meta->id,
                    'source_id' => $t['source'] === null ? null : $ids[$t['source']],
                    'target_id' => $ids[$t['target']],
                    'criteria' => $t['criteria'] ?? [],
                ]);
            }
            if (array_key_exists('main_at', $data)) {
                $config = $meta->json_config ?? [];
                $config['process_main_at'] = $data['main_at'] ? ['x' => $data['main_at']['x'], 'y' => $data['main_at']['y']] : null;
                $meta->json_config = $config;
                $meta->save();
            }
        });

        return response()->json($this->flow($meta));
    }

    /** @return array{main_at: mixed, steps: mixed, transitions: mixed} */
    private function flow(Meta $meta): array
    {
        return [
            'main_at' => $meta->fresh()->json_config['process_main_at'] ?? null,
            'steps' => MetaProcessWorkflow::where('main_process_id', $meta->id)->with(['process:id,code,name,type_id', 'process.type:id,code', 'behaviours'])->orderBy('order_number')->orderBy('id')->get(),
            'transitions' => MetaProcessTransition::where('main_process_id', $meta->id)->orderBy('id')->get(),
        ];
    }

    private function requireForm(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'form', 422, 'Only forms have process flows.');
    }
}
