<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Support\Market\Contracts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contracts between a buyer and a vendor: each side sees and changes only its own. */
class ContractController extends Controller
{
    /** GET /api/market/contracts — the user's contracts on either side. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(Contracts::listFor($user)->through(fn (Contract $c) => Contracts::summary($c, $user)));
    }

    /** POST /api/market/contracts — a direct contract; the user is the buyer. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'buyer_company_id' => ['nullable', 'integer'],
            'vendor_registry_number' => ['required', 'string', 'max:20'],
            ...$this->terms(true),
        ]);
        $contract = Contracts::draft($request->user(), $data);

        return response()->json(Contracts::show($contract->fresh(), $request->user()), 201);
    }

    /** GET /api/market/contracts/{contract}. */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        Contracts::requireSide($request->user(), $contract);

        return response()->json(Contracts::show($contract, $request->user()));
    }

    /** PUT /api/market/contracts/{contract} — new terms before both have signed. */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        Contracts::requireSide($request->user(), $contract);
        $contract = Contracts::update($request->user(), $contract, $request->validate($this->terms(false)));

        return response()->json(Contracts::show($contract->fresh(), $request->user()));
    }

    /** POST /api/market/contracts/{contract}/cancel {reason?}. */
    public function cancel(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $contract = Contracts::cancel($request->user(), $contract, $data['reason'] ?? null);

        return response()->json(Contracts::show($contract->fresh(), $request->user()));
    }

    /** POST /api/market/contracts/{contract}/sign — where to sign in with DAN to confirm the signature. */
    public function sign(Request $request, Contract $contract): JsonResponse
    {
        return response()->json(['url' => Contracts::startSigning($request->user(), $contract)]);
    }

    /** POST /api/market/contracts/{contract}/start — the vendor starts the work. */
    public function start(Request $request, Contract $contract): JsonResponse
    {
        return $this->shown($request, Contracts::start($request->user(), $contract));
    }

    /** POST /api/market/contracts/{contract}/deliver {note?} — the vendor says it is delivered or done. */
    public function deliver(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->shown($request, Contracts::deliver($request->user(), $contract, $data['note'] ?? null));
    }

    /** POST /api/market/contracts/{contract}/confirm — the buyer accepts the delivery. */
    public function confirm(Request $request, Contract $contract): JsonResponse
    {
        return $this->shown($request, Contracts::confirm($request->user(), $contract));
    }

    /** POST /api/market/contracts/{contract}/reject {reason} — the buyer turns the delivery down. */
    public function reject(Request $request, Contract $contract): JsonResponse
    {
        Contracts::requireSigner($request->user(), $contract, 'buyer');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->shown($request, Contracts::reject($request->user(), $contract, $data['reason']));
    }

    /** POST /api/market/contracts/{contract}/rate {stars, comment?}. */
    public function rate(Request $request, Contract $contract): JsonResponse
    {
        Contracts::requireSigner($request->user(), $contract);
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);

        return $this->shown($request, Contracts::rate($request->user(), $contract, $data['stars'], $data['comment'] ?? null));
    }

    /** GET /api/market/contracts/{contract}/pdf — the signed contract. */
    public function pdf(Request $request, Contract $contract): StreamedResponse
    {
        Contracts::requireSide($request->user(), $contract);
        abort_unless($contract->pdf_path && Storage::disk('local')->exists($contract->pdf_path), 404);

        return Storage::disk('local')->download($contract->pdf_path, "{$contract->number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    private function shown(Request $request, Contract $contract): JsonResponse
    {
        return response()->json(Contracts::show($contract->fresh(), $request->user()));
    }

    /**
     * The terms either side writes.
     *
     * @return array<string, mixed>
     */
    private function terms(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:255'],
            'items' => [$required, 'array', 'min:1', 'max:200'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'delivery_date' => ['sometimes', 'nullable', 'date'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pledge' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pledge_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'middleman' => ['sometimes', Rule::in(Contract::MIDDLEMAN)],
            'steps' => ['sometimes', 'array', 'max:20'],
            'steps.*.label' => ['required', 'string', 'max:100'],
            'steps.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
