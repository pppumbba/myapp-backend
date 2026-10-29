<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\GanttDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GanttDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GanttDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_timeline_lists_tasks_overlapping_the_window(): void
    {
        $timeline = Meta::where('code', 'pm_timeline')->value('id');
        $project = DB::table('pm_projects')->value('id');

        DB::table('pm_tasks')->delete();
        DB::table('pm_tasks')->insert([
            ['project_id' => $project, 'name' => 'Before', 'start_date' => '2026-09-01', 'end_date' => '2026-09-20'],
            ['project_id' => $project, 'name' => 'Runs into window', 'start_date' => '2026-09-15', 'end_date' => '2026-10-05'],
            ['project_id' => $project, 'name' => 'Inside', 'start_date' => '2026-10-10', 'end_date' => '2026-10-20'],
            ['project_id' => $project, 'name' => 'After', 'start_date' => '2027-01-01', 'end_date' => '2027-01-10'],
        ]);

        $names = $this->getJson("/api/metas/{$timeline}/data?from=2026-10-01&to=2026-12-24&sort=start_date")
            ->assertOk()
            ->json('data.*.name');

        $this->assertSame(['Runs into window', 'Inside'], $names);
    }

    public function test_task_form_checks_progress_range(): void
    {
        $form = Meta::where('code', 'pm_task_form')->value('id');
        $project = DB::table('pm_projects')->value('id');
        $task = ['name' => 'Test', 'project_id' => $project, 'status' => 'planned', 'start_date' => '2026-10-20', 'end_date' => '2026-10-24'];

        $this->postJson("/api/metas/{$form}/data", $task + ['progress' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['progress']);

        $this->postJson("/api/metas/{$form}/data", $task + ['progress' => 50])->assertCreated();
    }
}
