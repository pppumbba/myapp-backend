<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The supplier form's payment terms: a detail with json_config.one, one record per supplier,
 * taken by a save only when its criteria holds (seeded: foreign suppliers).
 */
class OneToOneDetailTest extends TestCase
{
    use RefreshDatabase;

    private int $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_supplier_form')->value('id');
    }

    private function type(string $code): int
    {
        return DB::table('proc_supplier_types')->where('code', $code)->value('id');
    }

    private function supplier(array $extra = []): array
    {
        return $extra + ['name' => 'Gobi Steel', 'country' => 'Mongolia', 'is_active' => true, 'supplier_type_id' => $this->type('foreign')];
    }

    private function criteria(string $criteria): void
    {
        $param = MetaParam::where('main_meta_id', $this->form)->where('field_path', 'terms')->first();
        $param->update(['json_config' => ['criteria' => $criteria] + $param->json_config]);
    }

    public function test_it_saves_and_loads_as_one_object(): void
    {
        $id = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['terms' => ['payment_terms' => 'net_60', 'currency' => 'USD', 'credit_limit' => 1000]]))
            ->assertCreated()
            ->assertJsonPath('terms.payment_terms', 'net_60')
            ->assertJsonPath('terms.supplier_id', fn ($v) => $v !== null)
            ->json('id');

        $this->getJson("/api/metas/{$this->form}/data/{$id}")->assertOk()->assertJsonPath('terms.currency', 'USD');
        $this->assertSame(1, DB::table('proc_supplier_terms')->where('supplier_id', $id)->count());
    }

    public function test_a_supplier_without_terms_loads_null(): void
    {
        $id = $this->postJson("/api/metas/{$this->form}/data", $this->supplier())->assertCreated()->assertJsonPath('terms', null)->json('id');

        $this->assertSame(0, DB::table('proc_supplier_terms')->where('supplier_id', $id)->count());
    }

    public function test_saving_again_updates_the_same_record_even_without_its_id(): void
    {
        $saved = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['terms' => ['payment_terms' => 'net_30', 'currency' => 'MNT']]))->json();

        $this->putJson("/api/metas/{$this->form}/data/{$saved['id']}", ['terms' => ['payment_terms' => 'prepaid', 'currency' => 'MNT']])
            ->assertOk()
            ->assertJsonPath('terms.id', $saved['terms']['id'])
            ->assertJsonPath('terms.payment_terms', 'prepaid');
        $this->assertSame(1, DB::table('proc_supplier_terms')->where('supplier_id', $saved['id'])->count());
    }

    public function test_its_fields_are_validated_where_they_sit(): void
    {
        $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['terms' => ['payment_terms' => 'net_90', 'currency' => '']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms.currency']);
    }

    public function test_null_removes_it_and_deleting_the_record_takes_it_along(): void
    {
        $saved = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['terms' => ['payment_terms' => 'net_30', 'currency' => 'MNT']]))->json();

        $this->putJson("/api/metas/{$this->form}/data/{$saved['id']}", ['terms' => null])->assertOk()->assertJsonPath('terms', null);
        $this->assertDatabaseMissing('proc_supplier_terms', ['id' => $saved['terms']['id']]);

        $other = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['name' => 'Other', 'terms' => ['payment_terms' => 'net_30', 'currency' => 'MNT']]))->json();
        $this->deleteJson("/api/metas/{$this->form}/data/{$other['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('proc_supplier_terms')->where('supplier_id', $other['id'])->count());
    }

    public function test_a_save_that_leaves_it_out_keeps_it(): void
    {
        $saved = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['terms' => ['payment_terms' => 'net_15', 'currency' => 'MNT']]))->json();

        $this->putJson("/api/metas/{$this->form}/data/{$saved['id']}", ['name' => 'Gobi Steel LLC'])->assertOk()->assertJsonPath('terms.payment_terms', 'net_15');
    }

    public function test_a_supplier_whose_type_fails_the_criteria_skips_terms_unchecked(): void
    {
        $id = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['supplier_type_id' => $this->type('local'), 'terms' => ['currency' => '']]))
            ->assertCreated()
            ->assertJsonPath('terms', null)
            ->json('id');

        $this->assertSame(0, DB::table('proc_supplier_terms')->where('supplier_id', $id)->count());
    }

    public function test_an_update_judges_the_criteria_by_the_saved_record_when_the_field_is_not_sent(): void
    {
        $local = $this->postJson("/api/metas/{$this->form}/data", $this->supplier(['supplier_type_id' => $this->type('local')]))->json('id');
        $this->putJson("/api/metas/{$this->form}/data/{$local}", ['terms' => ['payment_terms' => 'net_30', 'currency' => 'MNT']])->assertOk()->assertJsonPath('terms', null);

        // Becoming foreign in the same save is enough.
        $this->putJson("/api/metas/{$this->form}/data/{$local}", ['supplier_type_id' => $this->type('foreign'), 'terms' => ['payment_terms' => 'net_30', 'currency' => 'MNT']])
            ->assertOk()
            ->assertJsonPath('terms.payment_terms', 'net_30');
    }

    public function test_criteria_can_ask_for_a_role_or_the_app_the_form_was_opened_in(): void
    {
        $buyer = Role::create(['code' => 'buyer', 'name' => 'Buyer']);
        $app = Meta::where('code', 'proc_app')->value('id');
        $this->criteria("roleId == {$buyer->id} || appMetaId == {$app}");
        $body = $this->supplier(['supplier_type_id' => null, 'terms' => ['payment_terms' => 'net_15', 'currency' => 'MNT']]);

        $this->postJson("/api/metas/{$this->form}/data", ['name' => 'Plain'] + $body)->assertCreated()->assertJsonPath('terms', null);
        $this->postJson("/api/metas/{$this->form}/data", ['name' => 'In the app', '_context' => ['app_meta_id' => $app]] + $body)
            ->assertCreated()
            ->assertJsonPath('terms.payment_terms', 'net_15');

        $user = User::factory()->create();
        $user->roles()->attach($buyer->id);
        Sanctum::actingAs($this->allow($user, [$this->form], ['view', 'create']));
        $this->postJson("/api/metas/{$this->form}/data", ['name' => 'By a buyer'] + $body)->assertCreated()->assertJsonPath('terms.payment_terms', 'net_15');
    }

    public function test_criteria_that_do_not_read_are_refused_when_saved(): void
    {
        $param = MetaParam::where('main_meta_id', $this->form)->where('field_path', 'terms')->value('id');

        $this->putJson("/api/params/{$param}", ['json_config' => ['foreign_key' => 'supplier_id', 'one' => true, 'criteria' => 'roleId == (1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['json_config']);
    }
}
