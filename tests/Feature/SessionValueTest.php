<?php

namespace Tests\Feature;

use App\Models\CustomerUser;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\SessionValue;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Custom session values: worked out per user at login, used as :named parameters and {defaults}. */
class SessionValueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        // Signing in works the values out in the person's customer.
        CustomerUser::firstOrCreate(['customer_id' => $this->testCustomer->id, 'user_id' => $this->admin->id]);

        Schema::create('test_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('department_id');
        });
        DB::table('test_employees')->insert(['id' => 7, 'user_id' => $this->admin->id, 'department_id' => 3]);

        SessionValue::create(['path' => 'sessionemployeeid', 'query' => 'SELECT id FROM test_employees WHERE user_id = :userid', 'param' => 'userid']);
        // Chained: looked up from the value above.
        SessionValue::create(['path' => 'sessiondepartmentid', 'query' => 'SELECT department_id FROM test_employees WHERE id = :sessionemployeeid', 'param' => 'sessionemployeeid']);
    }

    private function login(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertOk();
        Sanctum::actingAs($this->admin);
    }

    private function getMeta(string $sql): Meta
    {
        return Meta::create(['code' => 'g_'.uniqid(), 'name' => 'Test get', 'type_id' => MetaType::where('code', 'get')->value('id'), 'query_string' => $sql, 'is_active' => true]);
    }

    public function test_values_are_worked_out_at_login_and_bound_in_queries(): void
    {
        $this->login();

        $this->getJson('/api/user/session')->assertOk()->assertExactJson(['sessionemployeeid' => 7, 'sessiondepartmentid' => 3]);

        $get = $this->getMeta('SELECT :sessionemployeeid AS employee_id, :sessiondepartmentid AS department_id, :user_id AS user_id');
        $this->getJson("/api/metas/{$get->id}/fill")->assertOk()
            ->assertJson(['employee_id' => 7, 'department_id' => 3, 'user_id' => $this->admin->id]);
    }

    public function test_values_stay_cached_until_the_next_login_or_a_refresh(): void
    {
        $this->login();
        DB::table('test_employees')->where('id', 7)->update(['department_id' => 9]);

        $this->getJson('/api/user/session')->assertJsonPath('sessiondepartmentid', 3);
        $this->postJson('/api/user/session/refresh')->assertOk()->assertJsonPath('sessiondepartmentid', 9);

        DB::table('test_employees')->where('id', 7)->update(['department_id' => 4]);
        $this->login();
        $this->getJson('/api/user/session')->assertJsonPath('sessiondepartmentid', 4);
    }

    public function test_changing_a_definition_works_every_value_out_again(): void
    {
        $this->login();
        $id = SessionValue::where('path', 'sessiondepartmentid')->value('id');

        $this->putJson("/api/session-values/{$id}", ['path' => 'sessiondepartmentid', 'query' => 'SELECT department_id * 10 FROM test_employees WHERE id = :sessionemployeeid', 'param' => 'sessionemployeeid'])
            ->assertOk();
        $this->getJson('/api/user/session')->assertJsonPath('sessiondepartmentid', 30);
        $this->getJson('/api/session-values')->assertOk()->assertJsonPath('1.value', 30);
    }

    public function test_a_stage_query_default_can_be_a_session_value(): void
    {
        $this->login();
        $query = Meta::create(['code' => 'sq_test', 'name' => 'Test stage', 'type_id' => MetaType::where('code', 'stage_query')->value('id'), 'is_active' => true,
            'query_string' => 'UPDATE test_employees SET department_id = 5 WHERE id = :employee']);
        MetaParam::create(['main_meta_id' => $query->id, 'order_number' => 1, 'field_path' => 'employee', 'param_name' => 'employee', 'data_type' => 'integer', 'default_value' => '{sessionemployeeid}']);

        $this->postJson("/api/metas/{$query->id}/run", ['params' => []])->assertOk()->assertJsonPath('affected', 1);
        $this->assertSame(5, (int) DB::table('test_employees')->where('id', 7)->value('department_id'));
    }

    public function test_only_select_queries_and_only_admins_define_them(): void
    {
        $this->login();
        $this->postJson('/api/session-values', ['path' => 'sessionx', 'query' => 'DELETE FROM test_employees'])
            ->assertUnprocessable()->assertJsonValidationErrors(['query']);
        $this->postJson('/api/session-values', ['path' => 'user_id', 'query' => 'SELECT 1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['path']);
        $this->postJson('/api/session-values/test', ['query' => 'SELECT nope FROM test_employees'])
            ->assertOk()->assertJsonPath('value', null)->assertJsonPath('error', fn ($e) => is_string($e) && $e !== '');
        $this->postJson('/api/session-values/test', ['query' => 'SELECT department_id FROM test_employees WHERE id = :sessionemployeeid', 'param' => 'sessionemployeeid'])
            ->assertOk()->assertJsonPath('value', 3);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/session-values')->assertForbidden();
        $this->getJson('/api/user/session')->assertOk()->assertExactJson(['sessionemployeeid' => null, 'sessiondepartmentid' => null]);
    }
}
