<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access;
use App\Support\MetaTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Metas exported to a file and imported from one, e.g. from a test installation to a live one (admins). */
class MetaTransferController extends Controller
{
    /** GET /meta-transfer/export?ids[]=&with_used=1 — the metas, with all their parts, as a JSON file. */
    public function export(Request $request): StreamedResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:metas,id'],
            'with_used' => ['nullable', 'boolean'],
        ]);
        $package = MetaTransfer::export($input['ids'], (bool) ($input['with_used'] ?? false));
        $name = count($input['ids']) === 1
            ? DB::table('metas')->where('id', $input['ids'][0])->value('code').'.metas.json'
            : 'metas-'.now()->format('Y-m-d').'.metas.json';

        return response()->streamDownload(
            fn () => print (json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $name,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * POST /meta-transfer/import {package, existing: replace|skip|copy, dry_run?} — the file's
     * metas put into this app; with dry_run, only what would happen.
     */
    public function import(Request $request): JsonResponse
    {
        Access::requireAdmin($request->user());
        $input = $request->validate([
            'package' => ['required', 'array'],
            'existing' => ['required', 'in:replace,skip,copy'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        return response()->json(MetaTransfer::import($input['package'], $input['existing'], $request->user(), (bool) ($input['dry_run'] ?? false)));
    }
}
