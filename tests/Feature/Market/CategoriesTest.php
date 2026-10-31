<?php

namespace Tests\Feature\Market;

use App\Models\CategoryFollow;
use App\Models\MarketCategory;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\MarketAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Categories and their spec templates: edited by staff through metas, read by everyone signed in. */
class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MarketAppSeeder::class);
    }

    public function test_categories_are_edited_through_metas_by_admins(): void
    {
        $grid = Meta::where('code', 'market_categories_grid')->firstOrFail();
        $form = Meta::where('code', 'market_category_form')->firstOrFail();
        $this->assertSame('market_categories', $grid->table_name);
        $this->assertSame('market_category_specs', Meta::where('code', 'market_category_spec_form')->value('table_name'));
        $this->assertTrue(MetaParam::where('main_meta_id', $form->id)->where('data_type', 'detail')->exists());

        $app = Meta::where('code', 'market_app')->value('id');
        $office = MetaParam::where('main_meta_id', $app)->where('field_path', 'back_office')->value('id');
        $this->assertTrue(MetaParam::where('parent_id', $office)->where('ref_meta_id', $grid->id)->exists());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/metas/{$grid->id}/data")->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson("/api/metas/{$grid->id}/data")->assertOk();
    }

    public function test_anyone_signed_in_reads_categories_with_templates_and_follows_one(): void
    {
        $laptops = MarketCategory::create(['name' => 'Laptops']);
        $laptops->specs()->create(['position' => 1, 'label' => 'Memory', 'kind' => 'number', 'operator' => 'gte', 'value' => '8', 'unit' => 'GB', 'must' => true]);
        MarketCategory::create(['name' => 'Old', 'is_active' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/market/categories')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Laptops')
            ->assertJsonPath('0.followed', false)
            ->assertJsonPath('0.specs.0.label', 'Memory')
            ->assertJsonPath('0.specs.0.must', true);

        $this->postJson("/api/market/categories/{$laptops->id}/follow")->assertOk();
        $this->postJson("/api/market/categories/{$laptops->id}/follow")->assertOk();
        $this->getJson('/api/market/categories')->assertJsonPath('0.followed', true);
        $this->assertSame(1, CategoryFollow::where('user_id', $user->id)->count());

        $this->deleteJson("/api/market/categories/{$laptops->id}/follow")->assertOk();
        $this->getJson('/api/market/categories')->assertJsonPath('0.followed', false);
    }
}
