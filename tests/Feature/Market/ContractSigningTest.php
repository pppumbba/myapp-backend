<?php

namespace Tests\Feature\Market;

use App\Models\CompanyMember;
use App\Models\Contract;
use App\Models\MarketFee;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractSigningTest extends TestCase
{
    use MarketTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['app.frontend_url' => 'http://front.test']);
    }

    /** The user asks to sign; returns the DAN state to come back with. */
    private function startSigning(User $user, Contract $contract): string
    {
        Sanctum::actingAs($user);
        $url = $this->postJson("/api/market/contracts/{$contract->id}/sign")->assertOk()->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    /** Back from DAN as this person. */
    private function backFromDan(string $state, string $registryNumber): TestResponse
    {
        return $this->get('/auth/dan/callback?'.http_build_query(['state' => $state, 'code' => "fake-{$registryNumber}"]));
    }

    public function test_both_sides_sign_with_dan_then_the_buyer_pays_the_fees_and_it_is_in_force(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->draftContract(['middleman' => 'on']);

        $this->backFromDan($this->startSigning($buyer, $contract), 'УБ90010112')
            ->assertRedirect("http://front.test/market/contracts/{$contract->id}?signed=1");
        $this->assertSame('signing', $contract->fresh()->status);

        $this->backFromDan($this->startSigning($vendor, $contract), 'УБ85051523')->assertRedirect();
        $contract->refresh();
        $this->assertSame('awaiting_payment', $contract->status);
        Storage::disk('local')->assertExists($contract->pdf_path);

        // 1% contract fee + 2% middleman fee on 2 900 000, in one invoice to the buyer.
        Sanctum::actingAs($buyer);
        $payment = $this->getJson("/api/market/contracts/{$contract->id}")->assertOk()
            ->assertJsonCount(2, 'signatures')
            ->assertJsonPath('fees.total', 87000)
            ->assertJsonPath('payment.amount', 87000)
            ->json('payment');
        $this->get("/api/market/contracts/{$contract->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->get("/market/pay/fake/{$payment['id']}/pay");

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame([['kind' => 'contract', 'amount' => 29000], ['kind' => 'middleman', 'amount' => 58000]], Payment::find($payment['id'])->lines);
        $this->assertTrue($vendor->userNotifications()->where('link', "/market/contracts/{$contract->id}")->where('title', 'like', '%in force%')->exists());
    }

    public function test_with_no_fees_it_is_in_force_once_both_have_signed(): void
    {
        MarketFee::where('kind', 'contract')->update(['amount' => 0]);
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->draftContract();

        $this->backFromDan($this->startSigning($buyer, $contract), 'УБ90010112');
        $this->backFromDan($this->startSigning($vendor, $contract), 'УБ85051523');

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(0, Payment::where('status', 'pending')->count());
    }

    public function test_a_signature_on_terms_that_changed_meanwhile_does_not_count(): void
    {
        ['buyer' => $buyer, 'vendor' => $vendor, 'contract' => $contract] = $this->draftContract();
        $state = $this->startSigning($buyer, $contract);
        Sanctum::actingAs($vendor);
        $this->putJson("/api/market/contracts/{$contract->id}", ['discount' => 0])->assertOk();

        $this->backFromDan($state, 'УБ90010112')->assertRedirectContains('sign_error=');

        $this->assertSame(0, $contract->signatures()->count());
    }

    public function test_signing_in_to_dan_as_someone_else_is_refused(): void
    {
        ['buyer' => $buyer, 'contract' => $contract] = $this->draftContract();

        $this->backFromDan($this->startSigning($buyer, $contract), 'ДА92120334')->assertRedirectContains('sign_error=');

        $this->assertSame(0, $contract->signatures()->count());
    }

    public function test_only_members_with_the_sign_right_sign_and_only_once(): void
    {
        ['buyer' => $buyer, 'contract' => $contract] = $this->draftContract();
        $clerk = $this->verifiedUser('ДА92120334');
        CompanyMember::create(['company_id' => $contract->buyer_company_id, 'user_id' => $clerk->id, 'can_bid' => true, 'can_sign' => false, 'can_manage' => false]);

        Sanctum::actingAs($clerk);
        $this->postJson("/api/market/contracts/{$contract->id}/sign")->assertForbidden();
        $this->getJson("/api/market/contracts/{$contract->id}")->assertOk()->assertJsonPath('can.sign', false);

        $this->backFromDan($this->startSigning($buyer, $contract), 'УБ90010112');
        $this->postJson("/api/market/contracts/{$contract->id}/sign")->assertStatus(422);
    }

    public function test_outsiders_cannot_get_the_pdf(): void
    {
        ['contract' => $contract] = $this->draftContract();

        Sanctum::actingAs($this->verifiedUser('ДА92120334'));
        $this->get("/api/market/contracts/{$contract->id}/pdf")->assertForbidden();
    }
}
