<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Bid;
use App\Models\Company;
use App\Models\Content;
use App\Models\Tender;
use App\Support\Market\Bids;
use App\Support\Market\Payments;
use App\Support\Market\Tenders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bids: send, read, withdraw. The rules live in Bids. */
class BidController extends Controller
{
    /** GET /api/market/bids — my bids and my companies'. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(Bids::mine($request->user()));
    }

    /** POST /api/market/tenders/{tender}/bids → {bid, payments}. */
    public function store(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        $data = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'lines' => ['required', 'array', 'min:1', 'max:'.config('market.tenders.max_items', 200)],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.price' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'specs' => ['sometimes', 'array'],
            'specs.*.spec_id' => ['required', 'integer'],
            'specs.*.value' => ['nullable', 'string', 'max:255'],
            'specs.*.claim' => ['nullable', Rule::in(Bid::CLAIMS)],
            'specs.*.file' => ['nullable', 'file'],
        ]);
        $company = empty($data['company_id']) ? null : Company::findOrFail($data['company_id']);
        ['bid' => $bid, 'payments' => $payments] = Bids::submit($request->user(), $tender, $company, $data);

        return response()->json([
            'bid' => Bids::show($bid, $request->user()),
            'payments' => array_map(fn ($p) => Payments::show($p), $payments),
        ], 201);
    }

    /** GET /api/market/bids/{bid}. */
    public function show(Request $request, Bid $bid): JsonResponse
    {
        abort_unless(Bids::canRead($request->user(), $bid), 404);

        return response()->json(Bids::show($bid, $request->user()));
    }

    /** POST /api/market/bids/{bid}/withdraw. */
    public function withdraw(Request $request, Bid $bid): JsonResponse
    {
        return response()->json(Bids::show(Bids::withdraw($request->user(), $bid), $request->user()));
    }

    /** GET /api/market/bids/{bid}/files/{content} — a datasheet sent with the bid. */
    public function file(Request $request, Bid $bid, Content $content): StreamedResponse
    {
        abort_unless(Bids::canRead($request->user(), $bid), 404);
        abort_unless($bid->specs()->where('content_id', $content->id)->exists(), 404);

        return Storage::disk('local')->download($content->physical_path, $content->file_name);
    }
}
