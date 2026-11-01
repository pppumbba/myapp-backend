<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaType;
use App\Models\Role;
use App\Models\SessionValue;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A query kept to the signed-in user (:user_id) can't be stepped around from the browser. */
class RecordScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $other;

    private Meta $grid;

    private int $mine;

    private int $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('title');
        });

        $this->me = User::factory()->create();
        $this->other = User::factory()->create();
        $this->mine = DB::table('test_orders')->insertGetId(['owner_id' => $this->me->id, 'title' => 'Mine']);
        $this->theirs = DB::table('test_orders')->insertGetId(['owner_id' => $this->other->id, 'title' => 'Theirs']);

        $this->grid = Meta::create([
            'code' => 'my_orders', 'name' => 'My orders', 'type_id' => MetaType::where('code', 'datagrid')->value('id'),
            'table_name' => 'test_orders', 'query_string' => 'SELECT id, owner_id, title FROM test_orders WHERE owner_id = :user_id', 'is_active' => true,
        ]);
        foreach (['id', 'owner_id', 'title'] as $i => $field) {
            MetaParam::create([
                'main_meta_id' => $this->grid->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field,
                'data_type' => $field === 'title' ? 'string' : 'integer', 'is_primary' => $field === 'id',
            ]);
        }

        $role = Role::create(['code' => 'staff', 'name' => 'Staff']);
        $this->me->roles()->attach($role);
        MetaPermission::create(['meta_id' => $this->grid->id, 'role_id' => $role->id, 'can_view' => true, 'can_edit' => true, 'can_delete' => true]);
        Sanctum::actingAs($this->me);
    }

    private function logged(int $id): void
    {
        DB::table('record_log')->insert(['table_name' => 'test_orders', 'record_id' => $id, 'action' => 'update', 'snapshot' => '{"title":"Secret"}', 'created_at' => now()]);
    }

    private function titles(string $query = ''): array
    {
        return array_column($this->getJson("/api/metas/{$this->grid->id}/data{$query}")->assertOk()->json('data'), 'title');
    }

    public function test_a_grid_shows_only_the_users_own_rows(): void
    {
        $this->assertSame(['Mine'], $this->titles());
    }

    public function test_scope_cannot_change_who_the_user_is(): void
    {
        $this->assertSame(['Mine'], $this->titles("?scope[user_id]={$this->other->id}"));
    }

    public function test_a_filter_cannot_change_who_the_user_is(): void
    {
        $this->assertSame(['Mine'], $this->titles("?filter[user_id]={$this->other->id}"));
    }

    public function test_a_get_meta_cannot_be_told_who_the_user_is(): void
    {
        $get = Meta::create(['code' => 'my_get', 'name' => 'My get', 'type_id' => MetaType::where('code', 'get')->value('id'), 'query_string' => 'SELECT :user_id AS who', 'is_active' => true, 'is_open' => true]);

        $this->getJson("/api/metas/{$get->id}/fill?params[user_id]={$this->other->id}")->assertOk()->assertJsonPath('who', $this->me->id);
    }

    public function test_one_record_of_another_user_cannot_be_read_by_its_id(): void
    {
        $this->getJson("/api/metas/{$this->grid->id}/data/{$this->mine}")->assertOk()->assertJsonPath('title', 'Mine');
        $this->getJson("/api/metas/{$this->grid->id}/data/{$this->theirs}")->assertNotFound();
    }

    public function test_one_record_of_another_user_cannot_be_changed_or_deleted(): void
    {
        $this->putJson("/api/metas/{$this->grid->id}/data/{$this->theirs}", ['title' => 'Taken'])->assertNotFound();
        $this->deleteJson("/api/metas/{$this->grid->id}/data/{$this->theirs}")->assertNotFound();

        $this->assertSame('Theirs', DB::table('test_orders')->where('id', $this->theirs)->value('title'));
    }

    public function test_the_log_of_another_users_record_cannot_be_read(): void
    {
        $this->logged($this->theirs);
        $this->logged($this->mine);

        $this->getJson("/api/metas/{$this->grid->id}/data/{$this->mine}/log")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/metas/{$this->grid->id}/data/{$this->theirs}/log")->assertNotFound();
    }

    public function test_a_public_form_does_not_show_its_records_log_to_every_user(): void
    {
        $form = Meta::create(['code' => 'contact', 'name' => 'Contact', 'type_id' => MetaType::where('code', 'form')->value('id'), 'table_name' => 'test_orders', 'is_active' => true, 'is_public' => true]);
        $this->logged($this->theirs);

        $this->getJson("/api/metas/{$form->id}/data/{$this->theirs}/log")->assertForbidden();
    }

    public function test_a_session_value_the_user_has_none_of_cannot_be_supplied(): void
    {
        SessionValue::create(['path' => 'sessionowner', 'query' => 'SELECT owner_id FROM test_orders WHERE owner_id = 0', 'param' => 'userid']);
        $this->grid->update(['query_string' => 'SELECT id, owner_id, title FROM test_orders WHERE owner_id = :sessionowner']);

        $this->assertSame([], $this->titles("?scope[sessionowner]={$this->other->id}"));
    }

    public function test_a_guest_cannot_supply_a_session_value(): void
    {
        SessionValue::create(['path' => 'sessionowner', 'query' => 'SELECT :userid', 'param' => 'userid']);
        $this->grid->update(['is_public' => true, 'query_string' => 'SELECT id, owner_id, title FROM test_orders WHERE owner_id = :sessionowner']);
        $this->app['auth']->forgetGuards();

        $this->assertSame([], $this->titles("?scope[sessionowner]={$this->other->id}"));
    }

    public function test_an_admin_still_opens_any_record(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/metas/{$this->grid->id}/data/{$this->theirs}")->assertOk()->assertJsonPath('title', 'Theirs');
    }
}
