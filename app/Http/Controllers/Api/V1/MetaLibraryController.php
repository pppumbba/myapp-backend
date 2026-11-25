<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meta;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use App\Support\Market\MarketNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Each person's own lists of metas on the Meta studio pages: starred, opened lately (Recent) and
 * shared with them. Sharing a meta with a builder lets them change it as if they had made it.
 */
class MetaLibraryController extends Controller
{
    /** How many metas Recent keeps per person. */
    private const RECENT = 50;

    /**
     * GET /metas/library: the user's starred, recent and shared metas, newest first, as ids with
     * when (and who shared). Only metas the user may still view.
     *
     * @return JsonResponse {starred: list<{meta_id, at}>, recent: list<{meta_id, at}>, shared: list<{meta_id, at, by: ?{id, name}}>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::requireBuilder($user);
        $viewable = Access::viewable(Meta::query(), $user)->pluck('id')->flip();
        $mine = fn (string $table, string $at) => DB::table($table)->where('user_id', $user->id)->orderByDesc($at)->get()
            ->filter(fn (object $row) => $viewable->has($row->meta_id))->values();

        $shared = $mine('meta_shares', 'created_at');
        $sharers = User::whereIn('id', $shared->pluck('shared_by')->filter()->unique())->pluck('name', 'id');

        return response()->json([
            'starred' => $mine('meta_stars', 'created_at')->map(fn (object $row) => ['meta_id' => $row->meta_id, 'at' => $this->iso($row->created_at)]),
            'recent' => $mine('meta_views', 'viewed_at')->map(fn (object $row) => ['meta_id' => $row->meta_id, 'at' => $this->iso($row->viewed_at)]),
            'shared' => $shared->map(fn (object $row) => ['meta_id' => $row->meta_id, 'at' => $this->iso($row->created_at),
                'by' => $row->shared_by && $sharers->has($row->shared_by) ? ['id' => $row->shared_by, 'name' => $sharers[$row->shared_by]] : null]),
        ]);
    }

    /** PUT /metas/{meta}/star */
    public function star(Request $request, Meta $meta): Response
    {
        Access::requireBuilder($request->user());
        abort_unless(Access::viewable(Meta::whereKey($meta->id), $request->user())->exists(), 404);
        DB::table('meta_stars')->insertOrIgnore(['user_id' => $request->user()->id, 'meta_id' => $meta->id, 'created_at' => now()]);

        return response()->noContent();
    }

    /** DELETE /metas/{meta}/star */
    public function unstar(Request $request, Meta $meta): Response
    {
        DB::table('meta_stars')->where('user_id', $request->user()->id)->where('meta_id', $meta->id)->delete();

        return response()->noContent();
    }

    /** POST /metas/{meta}/viewed: the meta was opened; Recent keeps the newest 50. */
    public function viewed(Request $request, Meta $meta): Response
    {
        $user = $request->user();
        if (! $user->isBuilder()) {
            return response()->noContent();
        }
        DB::table('meta_views')->upsert([['user_id' => $user->id, 'meta_id' => $meta->id, 'viewed_at' => now()]], ['user_id', 'meta_id'], ['viewed_at']);
        $keep = DB::table('meta_views')->where('user_id', $user->id)->orderByDesc('viewed_at')->limit(self::RECENT)->pluck('meta_id');
        DB::table('meta_views')->where('user_id', $user->id)->whereNotIn('meta_id', $keep)->delete();

        return response()->noContent();
    }

    /**
     * GET /metas/{meta}/shares: who the meta is shared with, and the people it could be shared
     * with (admins and builders of this customer, not the user).
     *
     * @return JsonResponse {shared: list<{id, name, email, avatar, shared_at, by}>, people: list<{id, name, email, avatar, is_admin}>}
     */
    public function shares(Request $request, Meta $meta): JsonResponse
    {
        Access::requireDesign($request->user(), $meta);
        $rows = DB::table('meta_shares')->where('meta_id', $meta->id)->orderBy('created_at')->get();
        $people = $this->people($request->user());
        $names = User::whereIn('id', $rows->pluck('shared_by')->filter())->pluck('name', 'id');

        return response()->json([
            'shared' => $rows->map(fn (object $row) => ($person = $people->firstWhere('id', $row->user_id) ?? User::find($row->user_id)?->only(['id', 'name', 'email', 'avatar']))
                ? [...$person, 'shared_at' => $this->iso($row->created_at), 'by' => $names[$row->shared_by] ?? null] : null)->filter()->values(),
            'people' => $people->values(),
        ]);
    }

    /**
     * PUT /metas/{meta}/shares {user_ids}: shares the meta with exactly these people. Each one
     * newly added gets a notification.
     */
    public function share(Request $request, Meta $meta): JsonResponse
    {
        $user = $request->user();
        Access::requireDesign($user, $meta);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer']]);
        $wanted = collect($data['user_ids'])->map(fn ($id) => (int) $id)->unique();
        $allowed = $this->people($user)->pluck('id');
        abort_if($wanted->diff($allowed)->isNotEmpty(), 422, __('A meta can only be shared with admins and builders of this workspace.'));

        $had = DB::table('meta_shares')->where('meta_id', $meta->id)->pluck('user_id');
        $added = $wanted->diff($had);
        DB::transaction(function () use ($meta, $wanted, $added, $user) {
            DB::table('meta_shares')->where('meta_id', $meta->id)->whereNotIn('user_id', $wanted)->delete();
            DB::table('meta_shares')->insert($added->map(fn (int $id) => ['meta_id' => $meta->id, 'user_id' => $id, 'shared_by' => $user->id, 'created_at' => now()])->values()->all());
        });
        foreach (User::whereIn('id', $added)->get() as $person) {
            MarketNotify::send($person, __(':name shared :meta with you', ['name' => $user->name, 'meta' => $meta->name]), null, "/metas/{$meta->id}");
        }

        return response()->json(['shared' => $wanted->count(), 'added' => $added->count()]);
    }

    /** A raw timestamp column as ISO 8601 with its zone, so browsers read it right. */
    private function iso(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toJSON();
    }

    /**
     * Admins and builders of the current customer other than $user: who a meta can be shared with.
     *
     * @return Collection<int, array{id: int, name: string, email: string, avatar: ?string, is_admin: bool}>
     */
    private function people(User $user): Collection
    {
        $roles = Role::query()->get(['id', 'code', 'features']);
        $adminRoles = $roles->where('code', Role::ADMIN)->pluck('id');
        $builderRoles = $roles->filter(fn (Role $role) => $role->code === Role::ADMIN || array_intersect(Role::FEATURES, $role->features ?? []))->pluck('id');
        $byUser = DB::connection('customer')->table('user_roles')->whereIn('role_id', $builderRoles)->get()->groupBy('user_id');

        return User::inCurrentCustomer()->whereIn('id', $byUser->keys())->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name', 'email', 'avatar'])
            ->map(fn (User $person) => [...$person->only(['id', 'name', 'email', 'avatar']), 'is_admin' => $byUser[$person->id]->pluck('role_id')->intersect($adminRoles)->isNotEmpty()]);
    }
}
