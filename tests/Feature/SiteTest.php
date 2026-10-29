<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\SiteRelease;
use App\Models\User;
use App\Models\Widget;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ThemeSeeder;
use Database\Seeders\WebsiteDemoSeeder;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Websites: pages with addresses and domains, checks before publishing, releases, visits. */
class SiteTest extends TestCase
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

    private function visit(string $path, string $host = 'northwind.test')
    {
        return $this->withHeader('User-Agent', 'Mozilla/5.0')->getJson('/api/site?'.http_build_query(['host' => $host, 'path' => $path]));
    }

    /** Puts a widget on one of the demo pages. */
    private function place(string $slug, string $widgetCode, array $config): void
    {
        $page = $this->entry($slug)->ref_meta_id;
        $widget = Widget::where('code', $widgetCode)->firstOrFail();
        $meta = Meta::create(['code' => uniqid('w_'), 'name' => 'Test widget', 'type_id' => DB::table('meta_types')->where('code', 'widget')->value('id'), 'widget_id' => $widget->id, 'ref_meta_id' => $page, 'json_config' => array_merge($widget->defaults(), $config), 'is_active' => true]);
        MetaParam::create(['main_meta_id' => $page, 'order_number' => 99, 'field_path' => $meta->code, 'param_name' => $meta->code, 'data_type' => 'widget', 'ref_meta_id' => $meta->id, 'json_config' => ['w' => 12]]);
    }

    public function test_visitors_get_published_pages_by_domain_or_code_with_the_menu_and_are_counted(): void
    {
        $home = $this->visit('/')->assertOk();
        $home->assertJsonPath('site.title', 'Northwind Supply')
            ->assertJsonPath('site.theme.brand', '#15803d')
            ->assertJsonPath('page.path', '/')
            ->assertJsonPath('page.items.0.widget.renderer', 'hero')
            ->assertJsonPath('nav.1.path', '/about');
        $this->assertCount(3, $home->json('nav'));

        $this->visit('/About/')->assertOk()->assertJsonPath('page.title', 'About Northwind Supply');
        $this->getJson('/api/site?code=site_northwind&path=/contact')->assertOk()->assertJsonPath('page.path', '/contact');
        $this->visit('/nowhere')->assertOk()->assertJsonPath('page', null);
        $this->visit('/', 'unknown.test')->assertNotFound();

        // Bots aren't counted.
        $this->withHeader('User-Agent', 'Googlebot/2.1')->getJson('/api/site?host=northwind.test&path=/');
        $this->assertSame(1, (int) DB::table('site_visits')->where('path', '/')->value('views'));
        $this->assertSame(1, (int) DB::table('site_visits')->where('path', '/about')->value('views'));

        Sanctum::actingAs($this->admin);
        $stats = $this->getJson("/api/sites/{$this->site()->id}/stats?days=7")->assertOk();
        $this->assertSame(3, $stats->json('total'));
        $this->assertCount(7, $stats->json('daily'));
    }

    public function test_visitors_see_what_was_published_not_the_draft_until_it_is_published_again(): void
    {
        Sanctum::actingAs($this->admin);
        $about = $this->entry('about');
        $this->place('about', 'callout', ['title' => 'New', 'text' => 'We now deliver on Saturdays.']);

        $this->visit('/about')->assertJsonCount(5, 'page.items');
        $this->getJson("/api/sites/{$this->site()->id}/preview?path=/about")->assertOk()->assertJsonCount(6, 'page.items');
        $pages = $this->getJson("/api/sites/{$this->site()->id}")->assertOk()->json('pages');
        $this->assertSame('changed', collect($pages)->firstWhere('param_id', $about->id)['status']);

        $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/publish")->assertCreated();
        $this->visit('/about')->assertJsonCount(6, 'page.items');

        // Rolling back publishes the first release again.
        $first = SiteRelease::where('param_id', $about->id)->orderBy('id')->first();
        $this->postJson("/api/site-releases/{$first->id}/restore")->assertCreated();
        $this->visit('/about')->assertJsonCount(5, 'page.items');

        $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/unpublish")->assertCreated();
        $this->visit('/about')->assertJsonPath('page', null);
        $this->assertCount(2, $this->visit('/')->json('nav'));
    }

    public function test_a_scheduled_release_goes_out_at_its_time_and_can_be_cancelled_before(): void
    {
        Sanctum::actingAs($this->admin);
        $about = $this->entry('about');
        $this->place('about', 'callout', ['title' => 'Holiday hours', 'text' => 'Closed for Tsagaan Sar.']);

        $release = $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/publish", ['publish_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $this->visit('/about')->assertJsonCount(5, 'page.items');

        $this->travel(2)->days();
        $this->visit('/about')->assertJsonCount(6, 'page.items');
        $this->deleteJson("/api/site-releases/{$release->json('id')}")->assertStatus(422);

        $later = $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/unpublish")->assertCreated();
        $this->assertNotNull($later->json('id'));
        $scheduled = $this->postJson("/api/sites/{$this->site()->id}/pages/{$about->id}/publish", ['publish_at' => now()->addHour()->toIso8601String()])->json('id');
        $this->deleteJson("/api/site-releases/{$scheduled}")->assertNoContent();
        $this->assertNull(SiteRelease::find($scheduled));
    }

    public function test_floor_problems_stop_publishing_and_reflex_ones_need_a_reason(): void
    {
        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $about = $this->entry('about');

        $this->place('about', 'image', ['src' => 'https://example.com/team.jpg']);
        $this->place('about', 'button', ['label' => 'Pricing', 'url' => '/pricing']);
        $issues = $this->postJson("/api/sites/{$site->id}/pages/{$about->id}/publish")->assertStatus(422)->json('issues');
        $codes = collect($issues)->where('level', 'floor')->pluck('code')->all();
        $this->assertContains('image_alt', $codes);
        $this->assertContains('broken_link', $codes);

        // The same page, fixed, still has a reflex problem: shouting.
        MetaParam::where('main_meta_id', $about->ref_meta_id)->where('order_number', 99)->get()->each(fn ($p) => Meta::find($p->ref_meta_id)->delete());
        MetaParam::where('main_meta_id', $about->ref_meta_id)->where('order_number', 99)->delete();
        $this->place('about', 'section', ['title' => 'OUR TEAM', 'background' => 'none']);
        $this->postJson("/api/sites/{$site->id}/pages/{$about->id}/publish")->assertStatus(422)->assertJsonPath('needs_reason', true);
        $this->postJson("/api/sites/{$site->id}/pages/{$about->id}/publish", ['note' => 'The brand guide spells it in capitals.'])->assertCreated()
            ->assertJsonPath('note', 'The brand guide spells it in capitals.');

        // A form visitors may not send is a floor problem.
        Meta::where('code', 'web_contact_form')->update(['is_public' => false]);
        $contact = $this->entry('contact');
        $codes = collect($this->getJson("/api/sites/{$site->id}/pages/{$contact->id}/check")->assertOk()->json('issues'))->pluck('code');
        $this->assertContains('not_public', $codes);
    }

    public function test_carousel_pictures_need_descriptions_and_a_quote_needs_who_said_it(): void
    {
        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $about = $this->entry('about');

        $this->place('about', 'carousel', ['slides' => [
            ['src' => 'https://example.com/van.jpg', 'alt' => 'Our first van'],
            ['src' => 'https://example.com/store.jpg'],
        ]]);
        $this->place('about', 'accordion', ['items' => []]);
        $this->place('about', 'quote', ['text' => 'They always arrive on time.']);

        $issues = collect($this->getJson("/api/sites/{$site->id}/pages/{$about->id}/check")->assertOk()->json('issues'));
        $alt = $issues->where('code', 'image_alt');
        $this->assertCount(1, $alt);
        $this->assertStringContainsString('Picture 2', $alt->first()['message']);
        $this->assertSame('floor', $issues->firstWhere('code', 'placeholder')['level']);
        $this->assertSame('reflex', $issues->firstWhere('code', 'quote_unnamed')['level']);
    }

    public function test_the_contact_page_draws_its_form_in_the_site_look(): void
    {
        $this->visit('/contact')->assertJsonPath('page.items.2.widget.renderer', 'webform');
    }

    public function test_white_text_on_a_light_brand_colour_is_a_floor_problem(): void
    {
        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $ocean = Meta::where('code', 'theme_sunset')->firstOrFail();
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'theme_meta_id' => $ocean->id]])->assertOk();

        $contact = $this->entry('contact');
        $codes = collect($this->getJson("/api/sites/{$site->id}/pages/{$contact->id}/check")->json('issues'))->where('level', 'floor')->pluck('code');
        $this->assertContains('contrast', $codes);
    }

    public function test_a_site_wears_one_of_the_looks_wayfind_until_another_is_picked(): void
    {
        $this->visit('/')->assertJsonPath('site.look', 'wayfind')->assertJsonPath('site.brand_accent', true);

        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'look' => 'no-such-look']])->assertStatus(422);
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'look' => 'riso', 'brand_accent' => false]])->assertOk();

        $this->visit('/')->assertJsonPath('site.look', 'riso')->assertJsonPath('site.brand_accent', false);
    }

    public function test_the_header_and_footer_styles_are_picked_from_their_lists(): void
    {
        $this->visit('/')->assertJsonPath('site.header', 'masthead')->assertJsonPath('site.footer_style', 'letter');

        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'header' => 'floating']])->assertStatus(422);
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'footer_style' => 'marquee']])->assertStatus(422);
        $this->putJson("/api/sites/{$site->id}", ['site' => ['title' => 'Northwind Supply', 'header' => 'banner', 'announcement' => '  Closed for Naadam, 11 to 15 July  ', 'footer_style' => 'columns']])->assertOk();

        $this->visit('/')->assertJsonPath('site.header', 'banner')
            ->assertJsonPath('site.announcement', 'Closed for Naadam, 11 to 15 July')
            ->assertJsonPath('site.footer_style', 'columns');
    }

    public function test_admins_manage_pages_and_domains_and_others_cannot(): void
    {
        Sanctum::actingAs($this->admin);
        $site = $this->site();
        $show = $this->getJson("/api/sites/{$site->id}")->assertOk();
        $pages = $show->json('pages');
        $pages[] = ['label' => 'Our Team', 'new_page' => true, 'in_menu' => false];

        $saved = $this->putJson("/api/sites/{$site->id}", [
            'domains' => [['host' => 'https://WWW.Northwind.test/home', 'is_primary' => true], ['host' => 'northwind.test']],
            'pages' => $pages,
        ])->assertOk();
        $this->assertSame(['www.northwind.test', 'northwind.test'], array_column($saved->json('domains'), 'host'));
        $team = collect($saved->json('pages'))->firstWhere('label', 'Our Team');
        $this->assertSame('/our-team', $team['path']);
        $this->assertSame('draft', $team['status']);
        $this->assertSame('page', Meta::with('type')->find($team['page_meta_id'])->type->code);

        $this->putJson("/api/sites/{$site->id}", ['domains' => ['bad host!']])->assertStatus(422);
        $other = $this->postJson('/api/sites', ['name' => 'Second site'])->assertCreated();
        $this->assertSame('/', $other->json('pages.0.path'));
        $this->putJson('/api/sites/'.$other->json('meta.id'), ['domains' => ['northwind.test']])->assertStatus(422);

        $forms = $this->getJson("/api/sites/{$site->id}/forms")->assertOk()->json();
        $this->assertSame('web_contact_form', $forms[0]['code']);
        $this->assertSame(['/contact'], $forms[0]['pages']);
        $this->assertSame(1, $forms[0]['notifications']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/sites')->assertForbidden();
        $this->putJson("/api/sites/{$site->id}", ['domains' => []])->assertForbidden();
    }
}
