<?php

namespace Tests\Feature\Market;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\MarketAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The market lives in a meta app: its pages are screen metas, open (is_open) to every signed-in
 * user, and the staff pages are screens with no permission rows, so admins only.
 */
class MarketAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketAppSeeder::class);
    }

    public function test_open_metas_are_viewable_by_every_signed_in_user_but_not_guests(): void
    {
        $app = Meta::where('code', 'market_app')->firstOrFail();

        $this->getJson("/api/metas/{$app->id}")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/metas/{$app->id}")->assertOk()->assertJsonPath('is_open', true);
        $this->getJson("/api/metas/{$app->id}/params")->assertOk();
        $this->getJson('/api/user/access')->assertOk()
            ->assertJsonPath("metas.{$app->id}", ['view' => true, 'create' => false, 'edit' => false, 'delete' => false]);
        $this->assertContains($app->id, collect($this->getJson('/api/metas')->assertOk()->json())->pluck('id')->all());
    }

    public function test_screens_list_paths_and_their_app_with_staff_screens_for_admins_only(): void
    {
        $app = Meta::where('code', 'market_app')->value('id');

        Sanctum::actingAs(User::factory()->create());
        $paths = collect($this->getJson('/api/screens')->assertOk()->json())->keyBy('path');
        $this->assertSame([$app], $paths['/market/contracts']['app_ids']);
        $this->assertTrue($paths->has('/market/account'));
        $this->assertTrue($paths->has('/market/payments'));
        $this->assertSame([$app], $paths['/market/tenders']['app_ids']);
        $this->assertTrue($paths->has('/market/tenders/mine'));
        $this->assertTrue($paths->has('/market/bids'));
        $this->assertFalse($paths->has('/admin/market-disputes'));

        Sanctum::actingAs(User::factory()->admin()->create());
        $paths = collect($this->getJson('/api/screens')->assertOk()->json())->keyBy('path');
        $this->assertTrue($paths->has('/admin/market-disputes'));
        $this->assertTrue($paths->has('/admin/market-fees'));
        $this->assertTrue($paths->has('/admin/market-reviews'));
    }

    public function test_the_seeder_is_safe_to_run_again(): void
    {
        $this->seed(MarketAppSeeder::class);

        $this->assertSame(1, Meta::where('code', 'market_app')->count());
        $this->assertSame(1, Meta::where('code', 'market_contracts')->count());
    }
}
