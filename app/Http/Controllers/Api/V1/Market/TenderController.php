<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderFile;
use App\Models\TenderItem;
use App\Support\Market\Payments;
use App\Support\Market\Tenders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tenders: draft, edit, cancel and their documents. The rules live in Tenders. */
class TenderController extends Controller
{
    /** GET /api/market/tenders?q=&category_id=&type=&page= — open tenders. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(Tender::TYPES)],
        ]);

        return response()->json(Tenders::listOpen($filters));
    }

    /** GET /api/market/tenders/mine — tenders I posted, any status. */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(Tenders::listMine($request->user()));
    }

    /** POST /api/market/tenders. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->terms(true) + ['company_id' => ['nullable', 'integer']]);

        return response()->json(Tenders::show(Tenders::draft($request->user(), $data), $request->user()), 201);
    }

    /** GET /api/market/tenders/{tender}. */
    public function show(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);

        return response()->json(Tenders::show($tender, $request->user()));
    }

    /** PUT /api/market/tenders/{tender}. */
    public function update(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate($this->terms(false));

        return response()->json(Tenders::show(Tenders::update($request->user(), $tender, $data), $request->user()));
    }

    /** POST /api/market/tenders/{tender}/publish → {tender, payment}: the posting fee to pay. */
    public function publish(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        ['tender' => $tender, 'payment' => $payment] = Tenders::publish($request->user(), $tender);

        return response()->json(['tender' => Tenders::show($tender, $request->user()), 'payment' => Payments::show($payment)]);
    }

    /** POST /api/market/tenders/{tender}/cancel {reason?}. */
    public function cancel(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);

        return response()->json(Tenders::show(Tenders::cancel($request->user(), $tender, $data['reason'] ?? null), $request->user()));
    }

    /** POST /api/market/tenders/{tender}/files {file, item_id?}. */
    public function addFile(Request $request, Tender $tender): JsonResponse
    {
        $request->validate(['file' => ['required', 'file'], 'item_id' => ['nullable', 'integer']]);
        $item = $request->filled('item_id') ? TenderItem::findOrFail($request->integer('item_id')) : null;
        $file = Tenders::addFile($request->user(), $tender, $item, $request->file('file'));

        return response()->json(['id' => $file->id, 'name' => $file->content->file_name, 'size' => (int) $file->content->file_size], 201);
    }

    /** GET /api/market/tender-files/{file}. */
    public function file(Request $request, TenderFile $file): StreamedResponse
    {
        $tender = Tender::findOrFail($file->tender_id);
        abort_unless(Tenders::canReadFiles($request->user(), $tender), 404);

        return Storage::disk('local')->download($file->content->physical_path, $file->content->file_name);
    }

    /** DELETE /api/market/tender-files/{file}. */
    public function deleteFile(Request $request, TenderFile $file): JsonResponse
    {
        $tender = Tender::findOrFail($file->tender_id);
        Tenders::requirePoster($request->user(), $tender);
        abort_unless(in_array($tender->status, ['draft', 'open'], true), 422, __('Documents can be removed while the tender is a draft or open.'));
        $file->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function terms(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(Tender::TYPES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:market_categories,id'],
            'incoterm' => ['sometimes', 'nullable', 'string', 'max:10'],
            'starts_at' => [$required, 'date'],
            'closes_at' => [$required, 'date'],
            'rule' => ['sometimes', 'array'],
            'rule.award' => ['sometimes', Rule::in(Tenders::AWARD)],
            'rule.weights' => ['sometimes', 'array'],
            'rule.weights.*' => ['integer', 'min:0', 'max:100'],
            'rule.visibility' => ['sometimes', Rule::in(Tenders::VISIBILITY)],
            'rule.partial' => ['sometimes', 'boolean'],
            'rule.split' => ['sometimes', Rule::in(Tenders::SPLIT)],
            'rule.irrevocable' => ['sometimes', 'boolean'],
            'rule.valid_until' => ['sometimes', 'nullable', 'date'],
            'rule.security' => ['sometimes', 'numeric', 'min:0', 'max:100000000000'],
            'rule.procedure' => ['sometimes', Rule::in(Tenders::PROCEDURE)],
            'rule.prequalification' => ['sometimes', 'boolean'],
            'rule.middleman' => ['sometimes', Rule::in(Tenders::MIDDLEMAN)],
            'items' => [$required, 'array', 'min:1', 'max:'.config('market.tenders.max_items', 200)],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.category_id' => ['nullable', 'integer', 'exists:market_categories,id'],
            'items.*.description' => ['nullable', 'string', 'max:5000'],
            'items.*.budget' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'items.*.show_budget' => ['sometimes', 'boolean'],
            'items.*.specs' => ['sometimes', 'array', 'max:'.config('market.tenders.max_specs', 50)],
            'items.*.specs.*.label' => ['required', 'string', 'max:255'],
            'items.*.specs.*.kind' => ['sometimes', Rule::in(['text', 'number'])],
            'items.*.specs.*.operator' => ['nullable', Rule::in(Tenders::OPERATORS)],
            'items.*.specs.*.value' => ['nullable', 'string', 'max:255'],
            'items.*.specs.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.specs.*.must' => ['sometimes', 'boolean'],
        ];
    }
}
