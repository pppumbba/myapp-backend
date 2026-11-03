<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use App\Support\ChartQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The numbers behind chart metas (see ChartQuery for what json_config holds), and the
 * columns the chart builder can pick from.
 */
class ChartController extends Controller
{
    /** GET /metas/{meta}/chart?from=&to= — the chart as saved. */
    public function show(Request $request, Meta $meta): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        $this->requireChart($meta);

        return response()->json(ChartQuery::run($meta, $meta->json_config ?? [], $request->user(), $request->query('from'), $request->query('to')));
    }

    /** POST /metas/{meta}/chart/preview {config, from, to} — its builder tries a config before saving it. */
    public function preview(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        $this->requireChart($meta);
        $data = $request->validate([
            'config' => ['present', 'array'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(ChartQuery::run($meta, $data['config'], $request->user(), $data['from'] ?? null, $data['to'] ?? null));
    }

    /** GET /metas/{meta}/columns — what the chart's table or query offers, for the builder. */
    public function columns(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        $this->requireChart($meta);

        return response()->json(ChartQuery::columns($meta, $request->user()));
    }

    private function requireChart(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'chart', 422, 'Only chart metas draw charts.');
    }
}
