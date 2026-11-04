<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** People of one customer never show up in another: lists, searches and role lookups. */
class CustomerUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_people_in_tests_join_the_test_customer_unless_outside(): void
    {
        $this->assertTrue(User::factory()->create()->isIn($this->testCustomer));
        $this->assertFalse(User::factory()->outside()->create()->isIn($this->testCustomer));
    }

    public function test_the_user_list_shows_only_members(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $member = User::factory()->create();
        $outsider = User::factory()->outside()->create();

        $ids = collect($this->getJson('/api/users')->assertOk()->json('data') ?? $this->getJson('/api/users')->json())->pluck('id');

        $this->assertContains($member->id, $ids);
        $this->assertNotContains($outsider->id, $ids);
    }

    public function test_people_search_shows_only_members(): void
    {
        Sanctum::actingAs(User::factory()->create());
        User::factory()->create(['name' => 'Zed Inside']);
        User::factory()->outside()->create(['name' => 'Zed Outside']);

        $this->getJson('/api/chats/people?q=Zed')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Zed Inside');
    }

    public function test_with_role_reads_roles_from_the_customer(): void
    {
        $clerk = Role::create(['code' => 'clerk', 'name' => 'Clerk']);
        $member = User::factory()->create();
        $member->roles()->attach($clerk->id);
        User::factory()->create();

        $this->assertSame([$member->id], User::withRole(fn ($q) => $q->where('code', 'clerk'))->pluck('id')->all());
    }
}
