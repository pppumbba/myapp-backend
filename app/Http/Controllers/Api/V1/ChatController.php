<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The messenger. The client polls GET /chats/poll every few seconds for the chat list, unread
 * counts and, for the chat it has open, new messages, who is typing and how far others have read.
 * Every chat route checks the user is a member; others get 404, as if the chat weren't there.
 */
class ChatController extends Controller
{
    /** How many chats the list holds and how many messages one page of a chat has. */
    private const CHATS = 60;

    private const PAGE = 50;

    /**
     * GET /chats/poll?open=&after= — {unread, chats, open}. open: the chat on screen, with its
     * messages after `after` (the last page when 0), who is typing and each member's read mark.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'open' => ['nullable', 'integer'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);
        $chats = $this->summaries($user);
        $open = null;
        if (! empty($data['open'])) {
            $chat = Chat::find($data['open']);
            if ($chat && $chat->hasMember($user)) {
                $open = $this->openState($chat, $user, (int) ($data['after'] ?? 0));
            }
        }

        return response()->json(['unread' => $chats->sum('unread'), 'chats' => $chats->values(), 'open' => $open]);
    }

    /** GET /chats/{chat}/messages?before= — the page of messages before that id, oldest first. */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->member($request, $chat);
        $before = (int) $request->integer('before');
        $rows = $chat->messages()->with('user:id,name')->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();

        return response()->json(['messages' => $rows->map(fn (ChatMessage $m) => $this->message($m)), 'more' => $rows->count() === self::PAGE]);
    }

    /** POST /chats/direct {user_id} — the chat with that user, started if there is none yet. */
    public function direct(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$user->id])]]);
        $chat = DB::transaction(function () use ($user, $data) {
            $chat = Chat::firstOrCreate(['direct_key' => Chat::directKey($user->id, $data['user_id'])], ['type' => Chat::DIRECT, 'created_by' => $user->id]);
            $chat->members()->syncWithoutDetaching([$user->id, $data['user_id']]);

            return $chat;
        });

        return response()->json($this->summaryOf($chat, $user));
    }

    /** POST /chats {name, user_ids} — a new group with the user and the people named. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);
        $chat = DB::transaction(function () use ($user, $data) {
            $chat = Chat::create(['type' => Chat::GROUP, 'name' => trim($data['name']), 'created_by' => $user->id]);
            $chat->members()->sync(array_unique([$user->id, ...$data['user_ids']]));
            $chat->post($user, __(':name started the group', ['name' => $user->name]), 'system');

            return $chat;
        });

        return response()->json($this->summaryOf($chat, $user), 201);
    }

    /** PUT /chats/{chat} {name} — renames a group; any member may. */
    public function update(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        abort_unless($chat->type === Chat::GROUP, 422, __('Only a group has a name.'));
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $name = trim($data['name']);
        if ($name !== $chat->name) {
            $chat->update(['name' => $name]);
            $chat->post($user, __(':name renamed the group to :title', ['name' => $user->name, 'title' => $name]), 'system');
        }

        return response()->json($this->summaryOf($chat, $user));
    }

    /** POST /chats/{chat}/members {user_ids} — adds people to a group; any member may. */
    public function addMembers(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        abort_unless($chat->type === Chat::GROUP, 422, __('People can only be added to a group.'));
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:100'], 'user_ids.*' => ['integer', Rule::exists('users', 'id')]]);
        $new = array_values(array_diff(array_unique($data['user_ids']), $chat->members()->pluck('users.id')->all()));
        if ($new) {
            // New members start having read everything before they joined.
            $mark = (int) $chat->messages()->max('id');
            $chat->members()->attach(collect($new)->mapWithKeys(fn ($id) => [$id => ['last_read_message_id' => $mark]])->all());
            $names = User::whereIn('id', $new)->orderBy('name')->pluck('name')->join(', ');
            $chat->post($user, __(':name added :people', ['name' => $user->name, 'people' => $names]), 'system');
        }

        return response()->json($this->summaryOf($chat, $user));
    }

    /** DELETE /chats/{chat}/members/me — leaves a group. A group nobody is left in is removed. */
    public function leave(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        abort_unless($chat->type === Chat::GROUP, 422, __('A direct chat cannot be left.'));
        $chat->post($user, __(':name left', ['name' => $user->name]), 'system');
        $chat->members()->detach($user->id);
        if (! $chat->members()->exists()) {
            $chat->delete();
        }

        return response()->json(['left' => true]);
    }

    /** POST /chats/{chat}/messages {body} */
    public function send(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $body = trim($data['body']);
        abort_if($body === '', 422, __('The message is empty.'));

        return response()->json($this->message($chat->post($user, $body)->setRelation('user', $user)), 201);
    }

    /** POST /chats/{chat}/read {message_id} — the user has seen the chat up to that message (never moves back). */
    public function read(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        $id = (int) $request->validate(['message_id' => ['required', 'integer', 'min:0']])['message_id'];
        DB::table('chat_members')->where('chat_id', $chat->id)->where('user_id', $user->id)->where('last_read_message_id', '<', $id)
            ->update(['last_read_message_id' => min($id, (int) $chat->messages()->max('id'))]);

        return response()->json(['ok' => true]);
    }

    /** POST /chats/{chat}/typing — the user is typing; others see it for a few seconds. */
    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $user = $this->member($request, $chat);
        DB::table('chat_members')->where('chat_id', $chat->id)->where('user_id', $user->id)->update(['typing_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** GET /chats/people?q= — people to start a chat with or add to a group, by name or email. */
    public function people(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $people = User::query()->whereKeyNot($request->user()->id)
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('email', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email']);

        return response()->json($people);
    }

    /** GET /chats/search?q= — messages with that text in the user's chats, newest first. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $rows = ChatMessage::query()->with('user:id,name')->where('kind', 'text')
            ->whereIn('chat_id', DB::table('chat_members')->where('user_id', $request->user()->id)->select('chat_id'))
            ->where('body', 'like', '%'.addcslashes($q, '%_\\').'%')
            ->orderByDesc('id')->limit(30)->get();

        return response()->json($rows->map(fn (ChatMessage $m) => $this->message($m)));
    }

    /** The signed-in user, when they are in the chat; otherwise a 404. */
    private function member(Request $request, Chat $chat): User
    {
        $user = $request->user();
        abort_unless($chat->hasMember($user), 404);

        return $user;
    }

    /** @return array{id: int, chat_id: int, user_id: ?int, user_name: ?string, kind: string, body: string, created_at: string} */
    private function message(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'chat_id' => $m->chat_id,
            'user_id' => $m->user_id,
            'user_name' => $m->user?->name,
            'kind' => $m->kind,
            'body' => $m->body,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** One chat as the list shows it (after a create or change). */
    private function summaryOf(Chat $chat, User $user): array
    {
        return $this->summaries($user, $chat->id)->first();
    }

    /**
     * The user's chats, latest activity first: name (a direct chat is named after the other
     * person), members, last message, unread count and who is typing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function summaries(User $user, ?int $only = null): Collection
    {
        $chats = Chat::query()
            ->join('chat_members as me', fn ($j) => $j->on('me.chat_id', '=', 'chats.id')->where('me.user_id', $user->id))
            ->when($only, fn ($q) => $q->where('chats.id', $only))
            ->select('chats.*', 'me.last_read_message_id as my_read')
            ->orderByRaw('COALESCE(chats.last_message_at, chats.created_at) DESC')
            ->limit(self::CHATS)
            ->with('members:id,name')
            ->get();
        if ($chats->isEmpty()) {
            return collect();
        }
        $ids = $chats->pluck('id');
        $last = ChatMessage::query()->with('user:id,name')->whereIn('id', ChatMessage::query()->selectRaw('MAX(id)')->whereIn('chat_id', $ids)->groupBy('chat_id'))->get()->keyBy('chat_id');
        $unread = DB::table('chat_messages as m')
            ->join('chat_members as me', fn ($j) => $j->on('me.chat_id', '=', 'm.chat_id')->where('me.user_id', $user->id))
            ->whereIn('m.chat_id', $ids)->whereColumn('m.id', '>', 'me.last_read_message_id')
            ->where(fn ($w) => $w->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id))
            ->groupBy('m.chat_id')->selectRaw('m.chat_id, COUNT(*) as n')->pluck('n', 'm.chat_id');
        $since = now()->subSeconds(Chat::TYPING_SECONDS);

        return $chats->map(function (Chat $chat) use ($user, $last, $unread, $since) {
            $others = $chat->members->where('id', '!=', $user->id);
            $message = $last->get($chat->id);

            return [
                'id' => $chat->id,
                'type' => $chat->type,
                'name' => $chat->type === Chat::GROUP ? $chat->name : ($others->first()->name ?? __('Just you')),
                'members' => $chat->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name])->values(),
                'last' => $message ? $this->message($message) : null,
                'unread' => (int) ($unread[$chat->id] ?? 0),
                'typing' => $others->filter(fn (User $m) => $m->pivot->typing_at && Carbon::parse($m->pivot->typing_at)->gte($since))->pluck('name')->values(),
            ];
        });
    }

    /** @return array{messages: Collection, typing: Collection, read: array<int, int>} */
    private function openState(Chat $chat, User $user, int $after): array
    {
        $query = $chat->messages()->with('user:id,name');
        $rows = $after > 0
            ? $query->where('id', '>', $after)->orderBy('id')->limit(200)->get()
            : $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
        $members = $chat->members()->get(['users.id', 'users.name']);
        $since = now()->subSeconds(Chat::TYPING_SECONDS);

        return [
            'chat_id' => $chat->id,
            'messages' => $rows->map(fn (ChatMessage $m) => $this->message($m)),
            'more' => $after === 0 && $rows->count() === self::PAGE,
            'typing' => $members->where('id', '!=', $user->id)->filter(fn (User $m) => $m->pivot->typing_at && Carbon::parse($m->pivot->typing_at)->gte($since))->pluck('name')->values(),
            'read' => $members->where('id', '!=', $user->id)->mapWithKeys(fn (User $m) => [$m->id => (int) $m->pivot->last_read_message_id])->all(),
        ];
    }
}
