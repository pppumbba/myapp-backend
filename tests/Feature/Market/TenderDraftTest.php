<?php

namespace Tests\Feature\Market;

use App\Models\Content;
use App\Models\Tender;
use App\Models\User;
use App\Support\Contents;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderDraftTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_a_verified_person_or_signer_drafts_a_tender_with_items_and_specs(): void
    {
        $person = $this->verifiedUser('ДА92120334', 'Solo Poster');
        Sanctum::actingAs($person);
        $this->postJson('/api/market/tenders', $this->tenderPayload())->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('role', 'poster')
            ->assertJsonPath('organizer.type', 'person')
            ->assertJsonPath('items.0.name', 'Laptop')
            ->assertJsonPath('items.0.specs.0.operator', 'gte')
            ->assertJsonPath('items.0.specs.0.must', true)
            ->assertJsonPath('rule.visibility', 'sealed');

        $director = $this->verifiedUser('УБ90010112');
        $company = $this->companyOf($director, '5123456');
        Sanctum::actingAs($director);
        $this->postJson('/api/market/tenders', $this->tenderPayload(['company_id' => $company->id]))->assertCreated()
            ->assertJsonPath('organizer.type', 'company')
            ->assertJsonPath('organizer.registry_number', '5123456');

        // Not verified, or no sign right for the company.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/market/tenders', $this->tenderPayload())->assertForbidden();
        Sanctum::actingAs($person);
        $this->postJson('/api/market/tenders', $this->tenderPayload(['company_id' => $company->id]))->assertForbidden();
    }

    public function test_only_the_poster_edits_a_draft(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->draftTender();

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->putJson("/api/market/tenders/{$tender->id}", ['title' => 'Mine now'])->assertForbidden();
        $this->getJson("/api/market/tenders/{$tender->id}")->assertNotFound();

        Sanctum::actingAs($poster);
        $this->putJson("/api/market/tenders/{$tender->id}", ['title' => 'Laptops, revised', 'items' => [['name' => 'Laptop', 'quantity' => 5, 'specs' => []]]])
            ->assertOk()->assertJsonPath('title', 'Laptops, revised')->assertJsonCount(1, 'items');

        $tender->update(['status' => 'open']);
        $this->putJson("/api/market/tenders/{$tender->id}", ['title' => 'Too late'])->assertStatus(422);

        $this->postJson("/api/market/tenders/{$tender->id}/cancel", ['reason' => 'Budget cut'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame('Budget cut', $tender->fresh()->cancel_reason);
        $this->postJson("/api/market/tenders/{$tender->id}/cancel")->assertStatus(422);
    }

    public function test_rule_is_validated(): void
    {
        $director = $this->verifiedUser('УБ90010112');
        Sanctum::actingAs($director);

        $this->postJson('/api/market/tenders', $this->tenderPayload(['rule' => ['award' => 'best_value', 'weights' => ['price' => 50, 'lead' => 20, 'quality' => 20]]]))
            ->assertStatus(422)->assertJsonValidationErrors('rule.weights');
        $this->postJson('/api/market/tenders', $this->tenderPayload(['closes_at' => now()->subDay()->toDateTimeString()]))
            ->assertStatus(422)->assertJsonValidationErrors('closes_at');
        $this->postJson('/api/market/tenders', $this->tenderPayload(['rule' => ['split' => 'quantity', 'partial' => false]]))
            ->assertStatus(422)->assertJsonValidationErrors('rule.split');
        $this->postJson('/api/market/tenders', $this->tenderPayload(['items' => [['name' => 'Laptop', 'specs' => [['label' => 'Memory', 'kind' => 'number', 'operator' => 'gte', 'value' => 'lots']]]]]))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.specs.0.value');

        $this->postJson('/api/market/tenders', $this->tenderPayload(['rule' => ['award' => 'best_value', 'weights' => ['price' => 50, 'lead' => 25, 'quality' => 25], 'visibility' => 'open']]))
            ->assertCreated()->assertJsonPath('rule.weights.lead', 25)->assertJsonPath('rule.visibility', 'open');
    }

    public function test_documents_upload_and_only_parties_download(): void
    {
        Storage::fake('local');
        ['poster' => $poster, 'tender' => $tender] = $this->draftTender();
        $item = $tender->items->first();

        Sanctum::actingAs($poster);
        $fileId = $this->post("/api/market/tenders/{$tender->id}/files", ['file' => UploadedFile::fake()->create('specs.pdf', 100, 'application/pdf'), 'item_id' => $item->id], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('name', 'specs.pdf')->json('id');
        $this->post("/api/market/tenders/{$tender->id}/files", ['file' => UploadedFile::fake()->create('run.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('items.0.files.0.name', 'specs.pdf');
        $this->get("/api/market/tender-files/{$fileId}")->assertOk();

        // A draft's documents are the poster's; once open, anyone signed in reads them.
        $other = $this->verifiedUser('ДА92120334');
        Sanctum::actingAs($other);
        $this->get("/api/market/tender-files/{$fileId}", ['Accept' => 'application/json'])->assertNotFound();
        $tender->update(['status' => 'open']);
        $this->get("/api/market/tender-files/{$fileId}")->assertOk();
        $this->delete("/api/market/tender-files/{$fileId}", [], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(1, Tender::find($tender->id)->files()->count());
    }

    public function test_tender_documents_are_not_pruned_as_stray_uploads(): void
    {
        Storage::fake('local');
        ['poster' => $poster, 'tender' => $tender] = $this->draftTender();
        Tenders::addFile($poster, $tender, null, UploadedFile::fake()->create('terms.pdf', 10, 'application/pdf'));

        $this->travel(2)->days();
        Contents::pruneStale();

        $this->assertSame(1, Content::count());
    }
}
