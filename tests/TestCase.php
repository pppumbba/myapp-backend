<?php

namespace Tests;

use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Gives the user a new role that may do these things with these metas: a meta with no
     * permission rows is for admins only.
     *
     * @param  int[]  $metaIds
     * @param  string[]  $abilities
     */
    protected function allow(User $user, array $metaIds, array $abilities = ['view']): User
    {
        $role = Role::create(['code' => 'role_'.Str::random(8), 'name' => 'Test role']);
        foreach ($metaIds as $metaId) {
            MetaPermission::create(['meta_id' => $metaId, 'role_id' => $role->id]
                + array_fill_keys(array_map(fn (string $a) => "can_{$a}", $abilities), true));
        }
        $user->roles()->attach($role->id);

        return $user;
    }
}
