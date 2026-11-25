<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ThemeSeeder;
use Database\Seeders\WebsiteDemoSeeder;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Website management app's pages across every site (SiteDeskController). */
class SiteDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ThemeSeeder::class, WidgetSeeder::class]);
        $this->admin = User::factory()->admin()->create();
        $this->seed(WebsiteDemoSeeder::class);
    }

    private function site(): Meta
    {
        return Meta::where('code', 'site_northwind')->firstOrFail();
    }

    private function entry(string $slug): MetaParam
    {
        return MetaParam::where('main_meta_id', $this->site()->id)->get()->first(fn ($p) => ($p->json_config['slug'] ?? '') === $slug);
    }

    public function test_only_admins_see_the_pages(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (['/api/sites/overview', '/api/site-releases', '/api/site-domains', '/api/site-messages', '/api/site-visits'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_the_overview_and_releases_show_what_went_out_and_what_is_scheduled(): void
    {
        Sanctum::actingAs($this->admin);
        $about = $this->entry('about');
        $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/publish", ['publish_at' => now()->addDay()->toIso8601String()])->assertCreated();

        $overview = $this->getJson('/api/sites/overview')->assertOk()
            ->assertJsonPath('sites', 1)->assertJsonPath('scheduled', 1)->assertJsonPath('domains', 1)
            ->assertJsonPath('upcoming.0.path', '/about')->assertJsonPath('upcoming.0.scheduled', true);
        $this->assertGreaterThan(0, $overview->json('live_pages'));
        $this->assertNotEmpty($overview->json('recent'));

        $this->getJson('/api/site-releases?status=scheduled')->assertJsonPath('total', 1)->assertJsonPath('data.0.site.name', $this->site()->name);
        $history = $this->getJson('/api/site-releases?status=history')->assertOk();
        $this->assertSame($history->json('counts.history'), $history->json('total'));
        $this->assertTrue(collect($history->json('data'))->contains('current', true));
        $this->getJson('/api/site-releases?q=about')->assertJsonPath('data.0.path', '/about');
    }

    public function test_domains_messages_and_visits_cover_every_site(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/site-domains')->assertOk()->assertJsonPath('domains.0.host', 'northwind.test')->assertJsonPath('domains.0.site.code', 'site_northwind');

        DB::table('web_contact_messages')->insert(['name' => 'Bold Visitor', 'email' => 'bold@example.test', 'message' => 'Do you deliver?', 'created_at' => now(), 'updated_at' => now()]);
        $messages = $this->getJson('/api/site-messages')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.sites.0', $this->site()->name);
        $this->assertContains('Bold Visitor', collect($messages->json('data.0.fields'))->pluck('value'));
        $this->assertNotContains('ID', collect($messages->json('data.0.fields'))->pluck('label'));
        // A form visitors can't send holds the app's own data, not messages.
        Meta::where('table_name', 'web_contact_messages')->where('type_id', DB::table('meta_types')->where('code', 'form')->value('id'))->update(['is_public' => false]);
        $this->getJson('/api/site-messages')->assertJsonPath('total', 0)->assertJsonPath('forms.0.is_public', false);

        $this->withHeader('User-Agent', 'Mozilla/5.0')->getJson('/api/site?host=northwind.test&path=/about')->assertOk();
        $this->getJson('/api/site-visits?days=7')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('sites.0.views', 1)->assertJsonPath('pages.0.path', '/about');
    }
}
