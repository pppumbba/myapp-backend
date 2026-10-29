<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\ClimbingDemoSeeder;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ThemeSeeder;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Khad Climbing demo site: today's sessions with spaces left, booked by guests. */
class ClimbingSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, ThemeSeeder::class, WidgetSeeder::class]);
        User::factory()->admin()->create();
        $this->seed(ClimbingDemoSeeder::class);
    }

    public function test_the_site_is_published_in_refract_with_a_schedule_photos_and_tables(): void
    {
        $home = $this->getJson('/api/site?code=site_khad&path=/')->assertOk()
            ->assertJsonPath('site.look', 'refract')
            ->assertJsonPath('site.brand_accent', false);
        $renderers = array_column(array_column($home->json('page.items'), 'widget'), 'renderer');
        $this->assertSame(['hero', 'schedule', 'photo', 'list', 'chart'], $renderers);
        $this->assertCount(3, $home->json('nav'));

        $prices = $this->getJson('/api/site?code=site_khad&path=/prices')->assertOk();
        $this->assertSame(['heading', 'table', 'table'], array_column(array_column($prices->json('page.items'), 'widget'), 'renderer'));

        $visit = $this->getJson('/api/site?code=site_khad&path=/visit')->assertOk();
        $this->assertSame(['heading', 'list', 'list', 'tabs', 'accordion', 'collapse', 'photo'], array_column(array_column($visit->json('page.items'), 'widget'), 'renderer'));
    }

    public function test_guests_see_the_spaces_left_today_and_a_booking_takes_them(): void
    {
        $grid = Meta::where('code', 'climb_sessions_today')->value('id');
        $form = Meta::where('code', 'climb_booking_form')->value('id');

        $rows = collect($this->getJson("/api/metas/{$grid}/data?sort=starts_at&dir=asc")->assertOk()->json('data'));
        $evening = $rows->firstWhere('name', 'Evening wall');
        $this->assertSame(24, (int) $evening['spaces']);
        $this->assertSame('07:00', $rows->first()['starts_at']);

        $this->postJson("/api/metas/{$form}/data", [
            'session_id' => $evening['id'],
            'booking_date' => now()->toDateString(),
            'name' => 'Example Climber',
            'email' => 'climber@example.com',
            'people' => 3,
        ])->assertCreated();

        $after = collect($this->getJson("/api/metas/{$grid}/data")->json('data'))->firstWhere('name', 'Evening wall');
        $this->assertSame(21, (int) $after['spaces']);

        // Guests send bookings but never read them back through the form.
        $this->getJson("/api/metas/{$form}/data")->assertStatus(401);
    }
}
