<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Websites as visitors see them: published pages only, open to anyone. */
class PublicSiteController extends Controller
{
    /**
     * GET /site?host=&path= (a site on its own domain) or /site?code=&path= (at /site/{code}) —
     * the site's look and menu and the page at that address (page null when there's none),
     * counting the visit.
     */
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required_without:code', 'nullable', 'string', 'max:190'],
            'code' => ['required_without:host', 'nullable', 'string', 'max:100'],
            'path' => ['nullable', 'string', 'max:500'],
        ]);
        $site = Sites::resolve($data['host'] ?? null, isset($data['host']) ? null : ($data['code'] ?? null));
        abort_unless($site, 404, 'There is no website here.');

        $result = Sites::page($site, $data['path'] ?? '/');
        if ($result['page']) {
            Sites::visit($site, $result['page']['path'], $request->userAgent());
        }

        return response()->json($result);
    }
}
