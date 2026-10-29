<?php

namespace App\Support;

use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\User;
use App\Models\WfmStatusPermission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what. Admins may do everything. Anyone else needs a role with the ability on
 * the meta: a meta with no permission rows is for admins only. Workflow statuses are the
 * other way round: a status with no rows is open, and once it has rows a role is needed,
 * separately for moving records in (can_enter) and seeing them (can_see).
 *
 * A public meta (is_public) may also be opened and added to by anyone, signed in or not, e.g.
 * a portal's screens. Its records can be read only through grids, never through a form, so
 * what one visitor sends through a public form stays hidden from the next.
 */
class Access
{
    /** What anyone may do with a public meta. */
    public const PUBLIC_ABILITIES = ['view', 'create'];

    /** Stops with 403 unless the user is an admin. */
    public static function requireAdmin(?User $user): void
    {
        abort_unless($user?->isAdmin(), 403, 'Only administrators can do this.');
    }

    /** Whether the user may view, create, edit or delete through this meta. */
    public static function can(?User $user, Meta|int $meta, string $ability): bool
    {
        if ($user?->isAdmin()) {
            return true;
        }
        if (in_array($ability, self::PUBLIC_ABILITIES, true) && self::isPublic($meta)) {
            return true;
        }

        return self::granted($user, $meta, $ability);
    }

    /** Whether the user may read records (and their files) through this meta: public forms only take new ones. */
    public static function canReadRecords(?User $user, Meta|int $meta): bool
    {
        $meta = $meta instanceof Meta ? $meta : Meta::find($meta);
        if (! $meta) {
            return false;
        }
        if ($user?->isAdmin() || self::granted($user, $meta, 'view')) {
            return true;
        }
        $meta->loadMissing('type');

        return $meta->type?->code !== 'form' && self::can($user, $meta, 'view');
    }

    /** Stops with 403 unless the user may read records through this meta. */
    public static function requireRecords(?User $user, Meta $meta): void
    {
        abort_unless(self::canReadRecords($user, $meta), $user ? 403 : 401, $user ? __("You don't have permission to open this.") : 'Unauthenticated.');
    }

    /** Whether one of the user's roles has the ability on the meta. */
    private static function granted(?User $user, Meta|int $meta, string $ability): bool
    {
        $metaId = $meta instanceof Meta ? $meta->id : $meta;

        return $user !== null && MetaPermission::where('meta_id', $metaId)
            ->whereIn('role_id', $user->roleIds())->where("can_{$ability}", true)->exists();
    }

    private static function isPublic(Meta|int $meta): bool
    {
        return $meta instanceof Meta ? (bool) $meta->is_public : Meta::whereKey($meta)->where('is_public', true)->exists();
    }

    /** Stops with 403 unless the user may do this with the meta; a guest gets 401, as signing in may help. */
    public static function require(?User $user, Meta|int $meta, string $ability): void
    {
        abort_if(! $user && ! self::can(null, $meta, $ability), 401, 'Unauthenticated.');
        abort_unless(self::can($user, $meta, $ability), 403, match ($ability) {
            'view' => __("You don't have permission to open this."),
            'create' => __("You don't have permission to add records to this."),
            'edit' => __("You don't have permission to change records in this."),
            'delete' => __("You don't have permission to delete records from this."),
            default => __("You don't have permission to :verb this.", ['verb' => $ability]),
        });
    }

    /** Limits a metas query to the ones the user may view. */
    public static function viewable(Builder $query, ?User $user): Builder
    {
        if ($user?->isAdmin()) {
            return $query;
        }
        $roles = $user?->roleIds() ?? [];

        return $query->where(fn ($q) => $q->where('metas.is_public', true)->orWhereExists(fn ($p) => $p->from('meta_permissions')
            ->whereColumn('meta_permissions.meta_id', 'metas.id')->whereIn('role_id', $roles)->where('can_view', true)));
    }

    /**
     * For every meta that has permission rows or is public, what this user may do with it.
     * Metas not listed are closed. Admins get an empty list: everything is open to them.
     *
     * @return array<int, array{view: bool, create: bool, edit: bool, delete: bool}>
     */
    public static function metaRules(?User $user): array
    {
        if ($user?->isAdmin()) {
            return [];
        }
        $roles = $user?->roleIds() ?? [];
        $rules = [];
        foreach (MetaPermission::all()->groupBy('meta_id') as $metaId => $rows) {
            $mine = $rows->whereIn('role_id', $roles);
            $rules[$metaId] = array_combine(MetaPermission::ABILITIES, array_map(fn ($a) => $mine->contains("can_{$a}", true), MetaPermission::ABILITIES));
        }
        foreach (Meta::where('is_public', true)->pluck('id') as $metaId) {
            $rules[$metaId] = array_merge($rules[$metaId] ?? array_fill_keys(MetaPermission::ABILITIES, false), array_fill_keys(self::PUBLIC_ABILITIES, true));
        }

        return $rules;
    }

    /** Whether the user may move a record into this status. */
    public static function canEnter(?User $user, int $statusId): bool
    {
        return self::statusAllows($user, $statusId, 'can_enter');
    }

    /**
     * Statuses whose records this user may not see: ones with can_see rows, none of them
     * for the user's roles.
     *
     * @return int[]
     */
    public static function hiddenStatuses(?User $user): array
    {
        if ($user?->isAdmin()) {
            return [];
        }
        $roles = $user?->roleIds() ?? [];

        return WfmStatusPermission::where('can_see', true)->get()->groupBy('status_id')
            ->reject(fn ($rows) => $rows->whereIn('role_id', $roles)->isNotEmpty())
            ->keys()->map(fn ($id) => (int) $id)->all();
    }

    private static function statusAllows(?User $user, int $statusId, string $column): bool
    {
        if ($user?->isAdmin()) {
            return true;
        }
        $rows = WfmStatusPermission::where('status_id', $statusId)->where($column, true);
        if (! (clone $rows)->exists()) {
            return true;
        }

        return $user !== null && $rows->whereIn('role_id', $user->roleIds())->exists();
    }
}
