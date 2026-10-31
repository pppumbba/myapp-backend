<?php

namespace Tests\Feature\Market;

use App\Models\UserNotification;
use App\Support\Market\Tenders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenderPrequalTest extends TestCase
{
    use MarketTestHelpers;
    use RefreshDatabase;

    public function test_bidders_apply_and_the_poster_decides(): void
    {
        Storage::fake('local');
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['prequalification' => true]]);
        $bidder = $this->verifiedUser('УБ85051523', 'Bidder Person');
        $company = $this->companyOf($bidder, '6234567');

        Sanctum::actingAs($bidder);
        $id = $this->post("/api/market/tenders/{$tender->id}/applications", [
            'company_id' => $company->id,
            'note' => 'We supply laptops to three ministries.',
            'files' => [UploadedFile::fake()->create('licence.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson("/api/market/tenders/{$tender->id}/applications", ['company_id' => $company->id])->assertStatus(422);
        $this->getJson("/api/market/tenders/{$tender->id}")->assertJsonPath('my_application.status', 'pending');
        $this->getJson("/api/market/tenders/{$tender->id}/applications")->assertForbidden();

        Sanctum::actingAs($poster);
        $this->postJson("/api/market/tenders/{$tender->id}/applications")->assertForbidden();
        $list = $this->getJson("/api/market/tenders/{$tender->id}/applications")->assertOk()
            ->assertJsonPath('0.party.name', $company->name)
            ->assertJsonPath('0.note', 'We supply laptops to three ministries.');
        $fileId = $list->json('0.files.0.id');
        $this->get("/api/market/tender-applications/{$id}/files/{$fileId}")->assertOk();
        $this->postJson("/api/market/tender-applications/{$id}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/market/tender-applications/{$id}/decide", ['approve' => false])->assertStatus(422);
        $this->assertTrue(UserNotification::where('user_id', $bidder->id)->where('link', "/market/tenders/{$tender->id}")->exists());

        // Someone else never reads the papers.
        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->get("/api/market/tender-applications/{$id}/files/{$fileId}", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_only_approved_parties_qualify_to_bid(): void
    {
        ['poster' => $poster, 'tender' => $tender] = $this->openTender(['rule' => ['prequalification' => true]]);
        $bidder = $this->verifiedUser('УБ85051523');
        $company = $this->companyOf($bidder, '6234567');
        $loner = $this->verifiedUser('ДА92120334');

        $this->assertFalse(Tenders::isQualified($tender, $bidder, $company));
        $application = Tenders::apply($bidder, $tender, $company, null, []);
        $this->assertFalse(Tenders::isQualified($tender, $bidder, $company));
        Tenders::decideApplication($poster, $application, false, 'No licence');
        $this->assertFalse(Tenders::isQualified($tender, $bidder, $company));

        $again = Tenders::apply($bidder, $tender, $company, 'Licence attached now', []);
        Tenders::decideApplication($poster, $again, true, null);
        $this->assertTrue(Tenders::isQualified($tender, $bidder, $company));
        // Approval is for the company, not for its director acting alone.
        $this->assertFalse(Tenders::isQualified($tender, $bidder, null));
        $this->assertFalse(Tenders::isQualified($tender, $loner, null));

        $tender->update(['rule' => ['prequalification' => false]]);
        $this->assertTrue(Tenders::isQualified($tender->fresh(), $loner, null));
    }
}
