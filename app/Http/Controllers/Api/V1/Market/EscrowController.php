<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractDispute;
use App\Models\ContractStep;
use App\Support\Access;
use App\Support\Market\Contracts;
use App\Support\Market\Escrow;
use App\Support\Market\Payments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Escrow on a contract's payment steps, disputes, and the staff queue that decides them. */
class EscrowController extends Controller
{
    /** POST /api/market/contract-steps/{step}/fund — the buyer's invoice to pay the step in. */
    public function fund(Request $request, ContractStep $step): JsonResponse
    {
        return response()->json(Payments::show(Escrow::fund($request->user(), $step)), 201);
    }

    /** POST /api/market/contract-steps/{step}/request — the vendor asks for the held step. */
    public function request(Request $request, ContractStep $step): JsonResponse
    {
        Escrow::request($request->user(), $step);

        return $this->contract($request, $step->contract);
    }

    /** POST /api/market/contract-steps/{step}/release — the buyer pays the held step out. */
    public function release(Request $request, ContractStep $step): JsonResponse
    {
        Escrow::release($request->user(), $step);

        return $this->contract($request, $step->contract);
    }

    /** POST /api/market/contract-steps/{step}/reject-release {reason}. */
    public function rejectRelease(Request $request, ContractStep $step): JsonResponse
    {
        Contracts::requireSigner($request->user(), $step->contract, 'buyer');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Escrow::rejectRelease($request->user(), $step, $data['reason']);

        return $this->contract($request, $step->contract);
    }

    /** POST /api/market/contracts/{contract}/disputes {reason}. */
    public function openDispute(Request $request, Contract $contract): JsonResponse
    {
        Contracts::requireSigner($request->user(), $contract);
        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        Escrow::openDispute($request->user(), $contract, $data['reason']);

        return response()->json(Contracts::show($contract->fresh(), $request->user()), 201);
    }

    /** GET /api/market/disputes?status=open|decided — staff only; each with the contract it is about. */
    public function disputes(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['status' => ['nullable', 'in:open,decided']]);
        $page = ContractDispute::with('contract', 'opener')->where('status', $data['status'] ?? 'open')->latest()->latest('id')->paginate(25);

        return response()->json($page->through(fn (ContractDispute $d) => [
            'id' => $d->id,
            'side' => $d->side,
            'opened_by' => $d->opener->verified_name ?? $d->opener->name,
            'reason' => $d->reason,
            'status' => $d->status,
            'resolution' => $d->resolution,
            'decision' => $d->decision,
            'created_at' => $d->created_at,
            'decided_at' => $d->decided_at,
            'contract' => Contracts::show($d->contract, $request->user()),
        ]));
    }

    /** POST /api/market/disputes/{dispute}/decide {resolution: release|refund|continue, decision}. */
    public function decide(Request $request, ContractDispute $dispute): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['resolution' => ['required', 'in:release,refund,continue'], 'decision' => ['required', 'string', 'max:5000']]);

        return response()->json(Escrow::decide($request->user(), $dispute, $data['resolution'], $data['decision']));
    }

    private function contract(Request $request, Contract $contract): JsonResponse
    {
        return response()->json(Contracts::show($contract->fresh(), $request->user()));
    }
}
