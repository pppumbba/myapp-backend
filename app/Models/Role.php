<?php

namespace App\Models;

use App\Models\Concerns\OnCustomerSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A role users can have. The "admin" role sees and changes everything. features are builder
 * abilities: "build" makes metas and changes the user's own, "studio" builds their own apps
 * in the studio (see Builders).
 *
 * Its people are read through user_roles, which lives in the customer's schema while users live
 * in the main one, so nothing here joins the two (a users relation would join in one schema).
 */
class Role extends Model
{
    use OnCustomerSchema;

    public const ADMIN = 'admin';

    /** What a role's features may hold. */
    public const FEATURES = ['build', 'studio'];

    protected $fillable = ['code', 'name', 'description', 'features'];

    /** @return Collection<int, int> the ids of the people with this role */
    public function userIds(): Collection
    {
        return $this->getConnection()->table('user_roles')->where('role_id', $this->id)->pluck('user_id')->map(fn ($id) => (int) $id);
    }

    /** @return EloquentCollection<int, User> the people with this role, by name */
    public function people(): EloquentCollection
    {
        return User::whereIn('id', $this->userIds())->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Gives the role to exactly these people.
     *
     * @param  int[]  $userIds
     * @return array{attached: int[], detached: int[]}
     */
    public function syncPeople(array $userIds): array
    {
        $current = $this->userIds()->all();
        $userIds = array_map('intval', $userIds);
        $attached = array_values(array_diff($userIds, $current));
        $detached = array_values(array_diff($current, $userIds));
        $table = fn () => $this->getConnection()->table('user_roles');
        $table()->insert(array_map(fn (int $id) => ['user_id' => $id, 'role_id' => $this->id], $attached));
        if ($detached) {
            $table()->where('role_id', $this->id)->whereIn('user_id', $detached)->delete();
        }

        return ['attached' => $attached, 'detached' => $detached];
    }

    /** Sets users_count: how many people have this role. */
    public function countPeople(): static
    {
        return $this->setAttribute('users_count', $this->userIds()->count());
    }

    /** Adds users_count to each role, counted in user_roles alone. */
    public function scopeWithPeopleCount(Builder $query): void
    {
        $query->select('roles.*')->selectSub(fn ($q) => $q->from('user_roles')->selectRaw('count(*)')->whereColumn('user_roles.role_id', 'roles.id'), 'users_count');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['features' => 'array'];
    }
}
