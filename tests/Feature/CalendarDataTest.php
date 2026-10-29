<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\CalendarDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarDataTest extends TestCase
{
    use RefreshDatabase;

    private int $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CalendarDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->calendar = Meta::where('code', 'cal_calendar')->value('id');

        DB::table('cal_events')->delete();
        DB::table('cal_events')->insert([
            ['title' => 'Before', 'category' => 'meeting', 'starts_at' => '2026-09-28 10:00:00', 'ends_at' => '2026-09-28 11:00:00'],
            ['title' => 'Spans in', 'category' => 'travel', 'starts_at' => '2026-09-29 08:00:00', 'ends_at' => '2026-10-02 18:00:00'],
            ['title' => 'Inside', 'category' => 'meeting', 'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00'],
            ['title' => 'No end', 'category' => 'deadline', 'starts_at' => '2026-10-20 17:00:00', 'ends_at' => null],
            ['title' => 'After', 'category' => 'meeting', 'starts_at' => '2026-11-01 09:00:00', 'ends_at' => null],
        ]);
    }

    public function test_calendar_lists_events_overlapping_the_range(): void
    {
        $titles = $this->getJson("/api/metas/{$this->calendar}/data?from=2026-10-01&to=2026-11-01&sort=starts_at")
            ->assertOk()
            ->json('data.*.title');

        $this->assertSame(['Spans in', 'Inside', 'No end'], $titles);
    }

    public function test_calendar_without_range_lists_everything(): void
    {
        $this->getJson("/api/metas/{$this->calendar}/data")->assertOk()->assertJsonPath('total', 5);
    }

    public function test_event_form_saves_datetimes(): void
    {
        $form = Meta::where('code', 'cal_event_form')->value('id');

        $this->postJson("/api/metas/{$form}/data", [
            'title' => 'Retro', 'category' => 'meeting', 'starts_at' => '2026-10-23 15:00:00', 'ends_at' => '2026-10-23 16:00:00',
        ])->assertCreated()->assertJsonPath('title', 'Retro');

        $this->postJson("/api/metas/{$form}/data", ['title' => 'Bad', 'category' => 'party', 'starts_at' => 'soon'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }
}
