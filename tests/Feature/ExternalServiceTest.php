<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A grid and form whose records live in an external REST service (metas.service_*). */
class ExternalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Meta $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        putenv('SERVICE_TEST_TOKEN=secret-123');
        putenv('DB_SECRET_TEST=do-not-send');

        $this->meta = Meta::create([
            'code' => 'ext_suppliers', 'name' => 'Remote suppliers', 'type_id' => MetaType::where('code', 'form')->value('id'), 'is_active' => true,
            'service_url' => 'https://api.example.test/v1/suppliers',
            'service_headers' => json_encode(['Authorization' => 'Bearer {env:SERVICE_TEST_TOKEN}', 'X-Leak' => '{env:DB_SECRET_TEST}']),
            'service_query' => json_encode(['p' => '{page}', 'limit' => '{per_page}', 'q' => '{search}', 'city' => '{filter.address.city}']),
            'service_list_path' => 'data',
            'service_total_path' => 'meta.total',
            'service_record_path' => 'data',
            'service_update_method' => 'PATCH',
        ]);
        foreach ([
            ['id', 'string', ['is_primary' => true]],
            ['name', 'string', ['is_required' => true, 'is_unique' => true]],
            ['address.city', 'string', ['is_criteria' => true]],
            ['rating', 'integer', ['min_value' => '1', 'max_value' => '5']],
        ] as $i => [$field, $type, $extra]) {
            MetaParam::create(['main_meta_id' => $this->meta->id, 'order_number' => $i + 1, 'field_path' => $field, 'param_name' => $field, 'data_type' => $type] + $extra);
        }
    }

    private function url(string $path = ''): string
    {
        return "/api/metas/{$this->meta->id}/data{$path}";
    }

    public function test_a_grid_lists_the_services_records_by_field_path(): void
    {
        Http::fake(['api.example.test/v1/suppliers*' => Http::response([
            'data' => [['id' => 'sup-a1', 'name' => 'Nomin', 'address' => ['city' => 'Ulaanbaatar'], 'rating' => 4, 'extra' => 'x']],
            'meta' => ['total' => 41],
        ])]);

        $this->getJson($this->url('?page=2&per_page=20&search=nom&filter[address.city]=Ulaanbaatar'))
            ->assertOk()
            ->assertJsonPath('data.0', ['id' => 'sup-a1', 'name' => 'Nomin', 'address.city' => 'Ulaanbaatar', 'rating' => 4])
            ->assertJsonPath('total', 41)->assertJsonPath('last_page', 3)->assertJsonPath('current_page', 2);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://api.example.test/v1/suppliers?')
            && $r['p'] === '2' && $r['limit'] === '20' && $r['q'] === 'nom' && $r['city'] === 'Ulaanbaatar'
            && $r->header('Authorization') === ['Bearer secret-123']
            // Only SERVICE_ names come from .env.
            && $r->header('X-Leak') === ['{env:DB_SECRET_TEST}']);
    }

    public function test_a_form_reads_adds_changes_and_removes_records(): void
    {
        Http::fake([
            'api.example.test/v1/suppliers/sup-a1' => Http::sequence()
                ->push(['data' => ['id' => 'sup-a1', 'name' => 'Nomin', 'address' => ['city' => 'Darkhan'], 'rating' => 3]])
                ->push(['data' => ['id' => 'sup-a1', 'name' => 'Nomin LLC', 'address' => ['city' => 'Darkhan'], 'rating' => 3]])
                ->push(null, 204),
            'api.example.test/v1/suppliers' => Http::response(['data' => ['id' => 'sup-b2', 'name' => 'Steppe', 'address' => ['city' => 'Erdenet'], 'rating' => 5]], 201),
        ]);

        $this->getJson($this->url('/sup-a1'))->assertOk()->assertJson(['id' => 'sup-a1', 'address.city' => 'Darkhan']);

        $this->postJson($this->url(), ['name' => 'Steppe', 'address.city' => 'Erdenet', 'rating' => 5, 'ignored' => 'x'])
            ->assertCreated()->assertJsonPath('id', 'sup-b2');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->data() === ['name' => 'Steppe', 'address' => ['city' => 'Erdenet'], 'rating' => 5]);

        $this->patchJson($this->url('/sup-a1'), ['name' => 'Nomin LLC'])->assertOk()->assertJsonPath('name', 'Nomin LLC');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PATCH' && $r->data() === ['name' => 'Nomin LLC']);

        $this->deleteJson($this->url('/sup-a1'))->assertNoContent();
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.example.test/v1/suppliers/sup-a1');
    }

    public function test_the_params_rules_are_checked_before_anything_is_sent(): void
    {
        Http::fake();

        $this->postJson($this->url(), ['rating' => 9])->assertUnprocessable()->assertJsonValidationErrors(['name', 'rating']);
        Http::assertNothingSent();
    }

    public function test_the_services_refusals_come_back_readable(): void
    {
        Http::fake([
            'api.example.test/v1/suppliers' => Http::response(['message' => 'Invalid', 'errors' => ['name' => ['Name already taken.']]], 422),
            'api.example.test/v1/suppliers/missing' => Http::response([], 404),
            'api.example.test/v1/suppliers/broken' => Http::response(['message' => 'Database down'], 500),
        ]);

        $this->postJson($this->url(), ['name' => 'Nomin'])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Name already taken.');
        $this->getJson($this->url('/missing'))->assertNotFound();
        $this->getJson($this->url('/broken'))->assertStatus(502)->assertJsonPath('message', 'The service answered 500: Database down');
    }

    public function test_a_service_listed_with_a_post_body_is_paged_here_and_read_only(): void
    {
        putenv('SERVICE_TEST_SESSION=sess-9');
        $this->meta->update([
            'service_url' => 'https://erp.example.test/RestWS/runJson',
            'service_headers' => null, 'service_query' => null, 'service_total_path' => null, 'service_record_path' => null,
            'service_list_path' => 'response.result',
            'service_list_body' => json_encode(['request' => [
                'sessionId' => '{env:SERVICE_TEST_SESSION}', 'command' => 'PL_MDVIEW_004',
                'parameters' => ['filterStartDate' => '{filter.start}', 'filterEndDate' => '{filter.end}'],
            ]]),
        ]);
        // Keyed "0", "1", ... as the service sends them.
        $rows = collect(range(1, 25))->mapWithKeys(fn ($i) => [(string) ($i - 1) => ['id' => "r{$i}", 'name' => $i === 7 ? 'Special' : "Item {$i}", 'rating' => $i % 5]])->all();
        Http::fake(['erp.example.test/*' => Http::sequence()
            ->push(['response' => ['status' => 'success', 'result' => $rows]])
            ->push(['response' => ['status' => 'success', 'result' => $rows]])
            ->push(['response' => ['status' => 'success', 'result' => $rows]])
            ->push(['response' => ['status' => 'error', 'text' => 'Session expired']]),
        ]);

        $this->getJson($this->url('?page=2&per_page=10&filter[start]=2026-01-01&filter[end]=2026-12-31'))
            ->assertOk()->assertJsonPath('total', 25)->assertJsonPath('last_page', 3)->assertJsonPath('data.0.id', 'r11')->assertJsonCount(10, 'data');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->data() === ['request' => [
            'sessionId' => 'sess-9', 'command' => 'PL_MDVIEW_004',
            'parameters' => ['filterStartDate' => '2026-01-01', 'filterEndDate' => '2026-12-31'],
        ]]);

        $this->getJson($this->url('?search=spec'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', 'r7');
        $this->getJson($this->url('?sort=rating&dir=desc&per_page=5'))->assertOk()->assertJsonPath('data.0.rating', 4);
        // Opens from the list just shown, without listing again (that would need its filters).
        $this->getJson($this->url('/r7'))->assertOk()->assertJsonPath('name', 'Special');
        Http::assertSentCount(3);
        $this->getJson($this->url())->assertStatus(502)->assertJsonPath('message', 'The service answered: Session expired');

        $this->postJson($this->url(), ['name' => 'New'])->assertStatus(405);
        $this->deleteJson($this->url('/r7'))->assertStatus(405);
    }

    public function test_only_admins_see_the_headers_and_metadata_addresses_are_refused(): void
    {
        $this->getJson("/api/metas/{$this->meta->id}")->assertOk()->assertJsonPath('service_headers', fn ($h) => str_contains($h, 'SERVICE_TEST_TOKEN'));
        Sanctum::actingAs($this->allow(User::factory()->create(), [$this->meta->id]));
        $this->getJson("/api/metas/{$this->meta->id}")->assertOk()->assertJsonMissingPath('service_headers');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson("/api/metas/{$this->meta->id}", ['service_url' => 'ftp://example.test'])->assertUnprocessable()->assertJsonValidationErrors(['service_url']);
        $this->meta->update(['service_url' => 'http://169.254.169.254/latest']);
        Http::fake();
        $this->getJson($this->url())->assertUnprocessable()->assertJsonPath('message', 'This service address is not allowed.');
        Http::assertNothingSent();
    }
}
