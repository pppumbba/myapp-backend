<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Tender;
use App\Support\Market\Bids;
use App\Support\Market\Negotiation;
use App\Support\Market\Tenders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Negotiated tenders: shortlist, private messages, revisions, final round. Rules in Negotiation. */
class NegotiationController extends Controller
{
    /** POST /api/market/tenders/{tender}/shortlist {bid_ids[]}. */
    public function shortlist(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        $data = $request->validate(['bid_ids' => ['required', 'array', 'min:1'], 'bid_ids.*' => ['integer']]);

        return response()->json(Tenders::show(Negotiation::shortlist($request->user(), $tender, $data['bid_ids']), $request->user()));
    }

    /** POST /api/market/tenders/{tender}/final-round {closes_at?}. */
    public function finalRound(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        $data = $request->validate(['closes_at' => ['nullable', 'date']]);

        return response()->json(Tenders::show(Negotiation::finalRound($request->user(), $tender, $data['closes_at'] ?? null), $request->user()));
    }

    /** POST /api/market/tenders/{tender}/finish-negotiation. */
    public function finish(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);

        return response()->json(Tenders::show(Negotiation::finish($request->user(), $tender), $request->user()));
    }

    /** GET /api/market/bids/{bid}/messages. */
    public function messages(Request $request, Bid $bid): JsonResponse
    {
        return response()->json(Negotiation::messages($request->user(), $bid));
    }

    /** POST /api/market/bids/{bid}/messages {body}. */
    public function message(Request $request, Bid $bid): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $message = Negotiation::message($request->user(), $bid, $data['body']);

        return response()->json(['id' => $message->id, 'side' => $message->side, 'body' => $message->body, 'created_at' => $message->created_at], 201);
    }

    /** POST /api/market/bids/{bid}/revise {lines, specs?, lead_time_days?}. */
    public function revise(Request $request, Bid $bid): JsonResponse
    {
        $data = $request->validate([
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'specs' => ['sometimes', 'array'],
            'specs.*.spec_id' => ['required', 'integer'],
            'specs.*.value' => ['nullable', 'string', 'max:255'],
            'specs.*.claim' => ['nullable', 'in:meets,exceeds,fails'],
        ]);

        return response()->json(Bids::show(Negotiation::revise($request->user(), $bid, $data), $request->user()), 201);
    }
}
