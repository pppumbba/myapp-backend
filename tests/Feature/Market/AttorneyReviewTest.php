<?php

namespace Tests\Feature\Market;

use App\Models\Company;
use App\Models\RepresentationRequest;
use App\Models\User;
use App\Support\Market\MarketAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttorneyReviewTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    private User $admin;

    private User $requester;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
        $this->requester = $this->verifiedUser('ДА92120334');
        Sanctum::actingAs($this->requester);
        $this->company = Company::findOrFail($this->postJson('/api/market/companies', ['registry_number' => '6234567'])->json('company.id'));
    }

    private function sendAttorney(string $name = 'itgemjlel.pdf'): int
    {
        Sanctum::actingAs($this->requester);

        return $this->post("/api/market/companies/{$this->company->id}/representations", [
            'method' => 'attorney',
            'file' => UploadedFile::fake()->create($name, 200),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('approve_url', null)->json('request.id');
    }

    public function test_an_attorney_request_waits_in_the_queue_and_an_admin_approves_it(): void
    {
        $id = $this->sendAttorney();
        $this->assertSame(1, $this->admin->userNotifications()->count());

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/market/reviews')->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.user.registry_number', 'ДА92120334')
            ->assertJsonPath('data.0.file.name', 'itgemjlel.pdf');
        $this->get("/api/market/reviews/{$id}/file")->assertOk()->assertDownload('itgemjlel.pdf');
        $this->postJson("/api/market/reviews/{$id}/decide", ['decision' => 'approve'])->assertOk();

        $this->assertSame('approved', RepresentationRequest::find($id)->status);
        $this->assertTrue(MarketAccess::canActFor($this->requester, $this->company, 'sign'));
        $this->getJson('/api/market/reviews')->assertJsonCount(0, 'data');
        $this->getJson('/api/market/reviews?status=approved')->assertJsonPath('data.0.id', $id);
    }

    public function test_rejecting_needs_a_reason_and_tells_the_requester(): void
    {
        $id = $this->sendAttorney();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/market/reviews/{$id}/decide", ['decision' => 'reject'])->assertStatus(422);
        $this->postJson("/api/market/reviews/{$id}/decide", ['decision' => 'reject', 'reason' => 'The stamp is missing.'])->assertOk();
        $this->postJson("/api/market/reviews/{$id}/decide", ['decision' => 'approve'])->assertStatus(422);

        $this->assertSame('rejected', RepresentationRequest::find($id)->status);
        $this->assertSame('The stamp is missing.', $this->requester->userNotifications()->sole()->body);
        $this->assertFalse(MarketAccess::canActFor($this->requester, $this->company, 'browse'));
    }

    public function test_the_attorney_file_survives_the_daily_prune_of_unsaved_uploads(): void
    {
        $id = $this->sendAttorney();
        $this->travel(3)->days();

        $this->artisan('contents:prune')->assertSuccessful();

        Sanctum::actingAs($this->admin);
        $this->get("/api/market/reviews/{$id}/file")->assertOk();
    }

    public function test_non_admins_cannot_see_the_queue_or_the_file(): void
    {
        $id = $this->sendAttorney();
        Sanctum::actingAs($this->verifiedUser('УБ90010112'));

        $this->getJson('/api/market/reviews')->assertForbidden();
        $this->getJson("/api/market/reviews/{$id}/file")->assertForbidden();
        $this->postJson("/api/market/reviews/{$id}/decide", ['decision' => 'approve'])->assertForbidden();
    }

    public function test_the_attorney_file_must_be_a_pdf_or_image(): void
    {
        Sanctum::actingAs($this->requester);

        $this->post("/api/market/companies/{$this->company->id}/representations", ['method' => 'attorney'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/market/companies/{$this->company->id}/representations", [
            'method' => 'attorney',
            'file' => UploadedFile::fake()->create('virus.exe', 10),
        ], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, RepresentationRequest::count());
    }
}
