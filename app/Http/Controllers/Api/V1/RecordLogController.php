<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A record's change log (see RecordLog): every logged save of it, through any meta, newest first. */
class RecordLogController extends Controller
{
    /** GET /metas/{meta}/data/{id}/log?page= — the record of this meta's table. */
    public function index(Request $request, Meta $meta, int $id): JsonResponse
    {
        Access::require($request->user(), $meta, 'view');
        abort_unless($meta->table_name, 422, 'This meta has no table, so its records have no log.');

        $page = DB::table('record_log as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->leftJoin('metas as m', 'm.id', '=', 'l.meta_id')
            ->where('l.table_name', $meta->table_name)
            ->where('l.record_id', $id)
            ->orderByDesc('l.id')
            ->select(['l.id', 'l.meta_id', 'm.name as meta_name', 'l.action', 'l.changes', 'l.snapshot', 'l.source', 'l.batch_id', 'l.created_at', 'l.user_id', 'u.name as user_name'])
            ->paginate(50);

        $page->getCollection()->transform(function ($row) {
            $row->changes = $row->changes === null ? null : json_decode($row->changes, true);
            $row->snapshot = $row->snapshot === null ? null : json_decode($row->snapshot, true);

            return $row;
        });

        return response()->json($page);
    }
}
