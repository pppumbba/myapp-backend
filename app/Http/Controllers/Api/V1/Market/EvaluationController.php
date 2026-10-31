<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\BidSpec;
use App\Models\Tender;
use App\Support\Market\Awards;
use App\Support\Market\Evaluation;
use App\Support\Market\Tenders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The poster's evaluation of a closed tender. The rules live in Evaluation. */
class EvaluationController extends Controller
{
    /** GET /api/market/tenders/{tender}/evaluation. */
    public function show(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        Tenders::requirePoster($request->user(), $tender);
        abort_if(in_array($tender->status, ['draft', 'open'], true), 422, __('Bids are sealed until the tender closes.'));

        return response()->json(Evaluation::of($tender));
    }

    /** POST /api/market/tenders/{tender}/evaluation/start. */
    public function start(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);

        return response()->json(Tenders::show(Evaluation::start($request->user(), $tender), $request->user()));
    }

    /** POST /api/market/tenders/{tender}/award {lines: [{bid_id, item_id?, quantity?}]}. */
    public function award(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.bid_id' => ['required', 'integer'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $tender = Awards::award($request->user(), $tender, $data['lines']);

        return response()->json(Tenders::show($tender, $request->user()) + ['awards' => Awards::of($tender)]);
    }

    /** POST /api/market/bid-specs/{answer}/override {claim, note?}. */
    public function override(Request $request, BidSpec $answer): JsonResponse
    {
        $data = $request->validate(['claim' => ['required', Rule::in(Bid::CLAIMS)], 'note' => ['nullable', 'string', 'max:2000']]);
        Evaluation::overrideClaim($request->user(), $answer, $data['claim'], $data['note'] ?? null);

        return response()->json(Evaluation::of($answer->bid->tender));
    }

    /** POST /api/market/bids/{bid}/overturn {overturn, note?}. */
    public function overturn(Request $request, Bid $bid): JsonResponse
    {
        $data = $request->validate(['overturn' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        Evaluation::overturn($request->user(), $bid, $data['overturn'], $data['note'] ?? null);

        return response()->json(Evaluation::of($bid->tender));
    }
}
