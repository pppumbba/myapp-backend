<?php

namespace Tests\Feature\Market;

use App\Models\User;
use App\Support\Market\Fees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_fees_are_fixed_or_a_percent_rounded_to_whole_tugrik(): void
    {
        $this->assertSame(10000, Fees::quote('posting', 999999));
        $this->assertSame(12346, Fees::quote('contract', 1234567.89));
        $this->assertSame(0, Fees::quote('middleman', 0));
    }

    public function test_staff_change_the_fees_and_everyone_reads_them(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson('/api/market/fees', ['fees' => [['kind' => 'bidding', 'mode' => 'percent', 'amount' => 0.5]]])->assertOk();
        $this->assertSame(500, Fees::quote('bidding', 100000));

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/market/fees')->assertOk()->assertJsonFragment(['kind' => 'bidding', 'mode' => 'percent']);
    }

    public function test_only_staff_change_fees_and_values_are_checked(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->putJson('/api/market/fees', ['fees' => [['kind' => 'bidding', 'mode' => 'fixed', 'amount' => 0]]])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->putJson('/api/market/fees', ['fees' => [['kind' => 'bidding', 'mode' => 'weekly', 'amount' => 1]]])->assertStatus(422);
        $this->putJson('/api/market/fees', ['fees' => [['kind' => 'contract', 'mode' => 'percent', 'amount' => 150]]])->assertStatus(422);
        $this->putJson('/api/market/fees', ['fees' => [['kind' => 'lunch', 'mode' => 'fixed', 'amount' => 1]]])->assertStatus(422);
    }
}
