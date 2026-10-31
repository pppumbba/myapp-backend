<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Support\Market\Companies;
use App\Support\Market\MarketAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Companies on the market and the people who act for them. */
class CompanyController extends Controller
{
    /** Validation for a member's rights. */
    private const RIGHTS = ['can_bid' => ['sometimes', 'boolean'], 'can_sign' => ['sometimes', 'boolean'], 'can_manage' => ['sometimes', 'boolean']];

    /** POST /api/market/companies {registry_number} — register (or find) a company through ХУР. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['registry_number' => ['required', 'string', 'max:20']]);
        ['company' => $company, 'member' => $member] = Companies::register($request->user(), $data['registry_number']);

        return response()->json(['company' => Companies::show($company), 'member' => $member], 201);
    }

    /** POST /api/market/companies/{company}/representations {method, file?} — ask to act for the company. */
    public function requestRepresentation(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'string', 'in:director,attorney']]);
        ['request' => $made, 'approve_url' => $url] = Companies::requestRepresentation($request->user(), $company, $data['method'], $request->file('file'));

        return response()->json(['request' => $made, 'approve_url' => $url], 201);
    }

    /** GET /api/market/companies/{company}/members — who acts for the company (members only). */
    public function members(Request $request, Company $company): JsonResponse
    {
        abort_unless(MarketAccess::canActFor($request->user(), $company, 'browse'), 403, __('Only the company\'s members can see this.'));

        return response()->json($company->members()->with('user')->orderBy('id')->get()->map(fn (CompanyMember $m) => [
            'id' => $m->id,
            'user' => ['id' => $m->user->id, 'verified_name' => $m->user->verified_name],
            'can_bid' => $m->can_bid,
            'can_sign' => $m->can_sign,
            'can_manage' => $m->can_manage,
        ])->values());
    }

    /** PATCH /api/market/companies/{company}/members/{member} {can_bid, can_sign, can_manage}. */
    public function updateMember(Request $request, Company $company, CompanyMember $member): JsonResponse
    {
        abort_unless($member->company_id === $company->id, 404);

        // All three, so a missing one is never read as "off".
        $rights = $request->validate(array_map(fn () => ['required', 'boolean'], self::RIGHTS));

        return response()->json(['member' => Companies::updateRights($request->user(), $member, $rights)]);
    }

    /** DELETE /api/market/companies/{company}/members/{member}. */
    public function removeMember(Request $request, Company $company, CompanyMember $member): Response
    {
        abort_unless($member->company_id === $company->id, 404);
        Companies::removeMember($request->user(), $member);

        return response()->noContent();
    }

    /** POST /api/market/companies/{company}/invites {can_bid, can_sign, can_manage} — a link for a colleague. */
    public function invite(Request $request, Company $company): JsonResponse
    {
        return response()->json(['url' => Companies::invite($request->user(), $company, $request->validate(self::RIGHTS))], 201);
    }

    /** POST /api/market/invites/{token}/accept — join the company the invite is for. */
    public function acceptInvite(Request $request, string $token): JsonResponse
    {
        return response()->json(['member' => Companies::acceptInvite($request->user(), $token)]);
    }

    /** GET /api/market/approvals/{token} — what the director is asked to approve. */
    public function approval(string $token): JsonResponse
    {
        $request = Companies::approvalFor($token);

        return response()->json([
            'company' => ['name' => $request->company->name, 'registry_number' => $request->company->registry_number],
            'requester' => ['verified_name' => $request->user->verified_name],
            'method' => $request->method,
            'created_at' => $request->created_at,
        ]);
    }

    /** POST /api/market/approvals/{token} {decision} — the director approves or rejects. */
    public function decideApproval(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $decided = Companies::decideByDirector($request->user(), $token, $data['decision'] === 'approve');

        return response()->json(['request' => $decided]);
    }
}
