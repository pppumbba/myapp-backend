<?php

namespace App\Http\Controllers\Api\V1\Market;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Content;
use App\Models\Tender;
use App\Models\TenderApplication;
use App\Support\Market\Tenders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Prequalification: bidders apply, the poster decides. The rules live in Tenders. */
class TenderApplicationController extends Controller
{
    /** GET /api/market/tenders/{tender}/applications — the poster's list. */
    public function index(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        Tenders::requirePoster($request->user(), $tender);

        return response()->json(Tenders::applications($tender));
    }

    /** POST /api/market/tenders/{tender}/applications {company_id?, note?, files[]?}. */
    public function store(Request $request, Tender $tender): JsonResponse
    {
        Tenders::requireVisible($request->user(), $tender);
        $data = $request->validate([
            'company_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:5000'],
            'files' => ['sometimes', 'array', 'max:10'],
            'files.*' => ['file'],
        ]);
        $company = empty($data['company_id']) ? null : Company::findOrFail($data['company_id']);
        $application = Tenders::apply($request->user(), $tender, $company, $data['note'] ?? null, $request->file('files', []));
        $contents = Content::whereIn('id', $application->content_ids ?? [])->get()->keyBy('id');

        return response()->json(Tenders::showApplication($application, $contents), 201);
    }

    /** POST /api/market/tender-applications/{application}/decide {approve, reason?}. */
    public function decide(Request $request, TenderApplication $application): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $application = Tenders::decideApplication($request->user(), $application, $data['approve'], $data['reason'] ?? null);
        $contents = Content::whereIn('id', $application->content_ids ?? [])->get()->keyBy('id');

        return response()->json(Tenders::showApplication($application, $contents));
    }

    /** GET /api/market/tender-applications/{application}/files/{content}. */
    public function file(Request $request, TenderApplication $application, Content $content): StreamedResponse
    {
        abort_unless(Tenders::canReadApplication($request->user(), $application), 404);
        abort_unless(in_array($content->id, $application->content_ids ?? [], true), 404);

        return Storage::disk('local')->download($content->physical_path, $content->file_name);
    }
}
