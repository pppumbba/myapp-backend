<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\RepresentationRequest;
use App\Support\Access;
use App\Support\Market\Companies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff review powers of attorney: the queue, the document, and the decision. Admins only. */
class ReviewController extends Controller
{
    /** GET /api/market/reviews?status=pending|approved|rejected — attorney requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);

        $page = RepresentationRequest::with(['company', 'user', 'content'])
            ->where('method', 'attorney')
            ->where('status', $data['status'] ?? 'pending')
            ->latest()->latest('id')
            ->paginate(25);

        return response()->json($page->through(fn (RepresentationRequest $r) => [
            'id' => $r->id,
            'company' => ['id' => $r->company->id, 'name' => $r->company->name, 'registry_number' => $r->company->registry_number],
            'user' => ['id' => $r->user->id, 'name' => $r->user->name, 'verified_name' => $r->user->verified_name, 'registry_number' => $r->user->registry_number],
            'method' => $r->method,
            'status' => $r->status,
            'reason' => $r->reason,
            'created_at' => $r->created_at,
            'decided_at' => $r->decided_at,
            'file' => $r->content ? ['name' => $r->content->file_name, 'size' => $r->content->file_size] : null,
        ]));
    }

    /** GET /api/market/reviews/{request}/file — the power of attorney document. */
    public function file(Request $request, RepresentationRequest $representation): StreamedResponse
    {
        Access::requireAdmin($request->user());
        $content = $representation->content;
        abort_unless($content && Storage::disk('local')->exists($content->physical_path), 404);

        return Storage::disk('local')->download($content->physical_path, $content->file_name);
    }

    /** POST /api/market/reviews/{request}/decide {decision, reason?}. */
    public function decide(Request $request, RepresentationRequest $representation): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['request' => Companies::decideByStaff($request->user(), $representation, $data['decision'] === 'approve', $data['reason'] ?? null)]);
    }
}
