<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Access;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The notification builder's tools for a notification meta (see Notifier): what it would
 * say about a record and to whom, sending it now (or as a test to yourself), and what it
 * has sent. Admins only.
 */
class NotificationRuleController extends Controller
{
    /** GET /metas/{meta}/notification/preview?record_id= — about that record, else the newest one. */
    public function preview(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        [$record, $id] = $this->record($request, $meta);
        $message = Notifier::compose($meta, $record, $id, $request->user());

        return response()->json([
            'record_id' => $id,
            'matches' => $record ? Notifier::matches($meta, $record) : null,
            'type' => $message['type'],
            'title' => $message['title'],
            'body' => $message['body'],
            'link' => $message['link'],
            'email' => Notifier::emails($meta),
            'recipient_count' => $message['recipients']->count(),
            'recipients' => $message['recipients']->take(20)->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
        ]);
    }

    /** POST /metas/{meta}/notification/send {record_id?, test?} — a test goes only to you. */
    public function send(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $data = $request->validate(['record_id' => ['nullable', 'integer'], 'test' => ['sometimes', 'boolean']]);
        [$record, $id] = $this->record($request, $meta, $data['record_id'] ?? null);
        $test = (bool) ($data['test'] ?? false);

        $sent = Notifier::send($meta, $record, $id, $request->user(), only: $test ? collect([$request->user()]) : null);

        return response()->json(['sent' => $sent]);
    }

    /** GET /metas/{meta}/notification/log — the last 25 it sent, newest first. */
    public function log(Request $request, Meta $meta): JsonResponse
    {
        Access::requireAdmin($request->user());
        $this->requireNotification($meta);

        return response()->json(
            UserNotification::where('notification_meta_id', $meta->id)
                ->join('users', 'users.id', '=', 'user_notifications.user_id')
                ->latest('user_notifications.id')->limit(25)
                ->get(['user_notifications.id', 'user_notifications.title', 'user_notifications.read_at', 'user_notifications.created_at',
                    'user_notifications.email_status', 'user_notifications.email_error', 'users.name as user_name'])
        );
    }

    /**
     * The record a preview or send is about: the one asked for, else the newest in the
     * watched table. A manual notification may have none.
     *
     * @return array{0: ?object, 1: ?int}
     */
    private function record(Request $request, Meta $meta, ?int $recordId = null): array
    {
        $this->requireNotification($meta);
        $recordId ??= $request->integer('record_id') ?: null;
        $table = Notifier::sourceTable($meta);
        if (! $table) {
            return [null, null];
        }
        $record = $recordId ? DB::table($table)->where('id', $recordId)->first() : DB::table($table)->orderByDesc('id')->first();
        abort_if($recordId && ! $record, 404, 'That record was not found.');

        return [$record, $record ? (int) $record->id : null];
    }

    private function requireNotification(Meta $meta): void
    {
        $meta->loadMissing('type');
        abort_unless($meta->type?->code === 'notification', 422, 'Only notification metas send notifications.');
    }
}
