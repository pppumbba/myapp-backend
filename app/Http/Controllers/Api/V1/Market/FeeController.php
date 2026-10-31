<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\MarketFee;
use App\Support\Access;
use App\Support\Market\Fees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The market's fees: anyone signed in reads them (they are shown before paying); staff set them. */
class FeeController extends Controller
{
    /** GET /api/market/fees. */
    public function index(): JsonResponse
    {
        return response()->json(Fees::all());
    }

    /** PUT /api/market/fees {fees: [{kind, mode, amount}]}. */
    public function update(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate([
            'fees' => ['required', 'array', 'min:1'],
            'fees.*.kind' => ['required', Rule::in(MarketFee::KINDS)],
            'fees.*.mode' => ['required', Rule::in(Fees::MODES)],
            'fees.*.amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        Fees::update($data['fees']);

        return response()->json(Fees::all());
    }
}
