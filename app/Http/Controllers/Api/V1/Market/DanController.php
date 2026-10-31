<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\CompanyMember;
use App\Models\RepresentationRequest;
use App\Support\Market\Companies;
use App\Support\Market\Contracts;
use App\Support\Market\Dan\FakeDanDriver;
use App\Support\Market\Verification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifying people through DAN: start (API), the callback the browser comes back to (web),
 * the fake DAN page used until the real one is connected, and what the market knows about
 * the signed-in user.
 */
class DanController extends Controller
{
    /** POST /api/market/dan/start — where to send the browser to sign in with DAN. */
    public function start(Request $request): JsonResponse
    {
        return response()->json(['url' => Verification::start($request->user())]);
    }

    /**
     * GET /auth/dan/callback — back from DAN: verify the account (back to the market account), or
     * confirm a contract signature (back to the contract).
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $back = Verification::consume((string) $request->query('state'), (string) $request->query('code'));
        } catch (HttpException $e) {
            return $this->toFrontend('/market/account', ['dan_error' => $e->getMessage()]);
        }

        if (($back['purpose']['type'] ?? null) === 'sign') {
            $path = '/market/contracts/'.(int) $back['purpose']['contract'];
            try {
                Contracts::completeSigning($back['user'], $back['purpose'], $back['citizen']);
            } catch (HttpException $e) {
                return $this->toFrontend($path, ['sign_error' => $e->getMessage()]);
            }

            return $this->toFrontend($path, ['signed' => 1]);
        }

        try {
            Verification::verify($back['user'], $back['citizen']);
        } catch (HttpException $e) {
            return $this->toFrontend('/market/account', ['dan_error' => $e->getMessage()]);
        }

        return $this->toFrontend('/market/account', ['dan' => 'ok']);
    }

    /** GET /auth/dan/fake — the test people to sign in as; never in production. */
    public function fake(Request $request): View
    {
        abort_if(app()->isProduction() || config('market.dan.driver') !== 'fake', 404);

        return view('market.dan-fake', ['state' => (string) $request->query('state'), 'citizens' => FakeDanDriver::citizens()]);
    }

    /** GET /api/market/me — the user's verification, companies and representation requests. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'verified' => $user->isVerified() ? [
                'name' => $user->verified_name,
                'registry_number' => $user->registry_number,
                'verified_at' => $user->verified_at,
            ] : null,
            'memberships' => $user->companyMemberships()->with('company')->get()->map(fn (CompanyMember $m) => [
                'company' => Companies::show($m->company),
                'can_bid' => $m->can_bid,
                'can_sign' => $m->can_sign,
                'can_manage' => $m->can_manage,
            ])->values(),
            'requests' => RepresentationRequest::with('company')->where('user_id', $user->id)->latest()->get()
                ->map(fn (RepresentationRequest $r) => [
                    'id' => $r->id,
                    'company' => ['id' => $r->company->id, 'name' => $r->company->name],
                    'method' => $r->method,
                    'status' => $r->status,
                    'reason' => $r->reason,
                    'created_at' => $r->created_at,
                ])->values(),
        ]);
    }

    /** @param  array<string, string|int>  $query */
    private function toFrontend(string $path, array $query): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').$path.'?'.http_build_query($query));
    }
}
