<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use Database\Seeders\LmsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LmsDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LmsDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    public function test_every_screen_in_the_app_loads_its_data(): void
    {
        foreach (['lms_courses_grid', 'lms_course_timeline', 'lms_schedule', 'lms_enrollments_grid', 'lms_students_grid', 'lms_instructors_grid'] as $code) {
            $this->getJson("/api/metas/{$this->metaId($code)}/data")->assertOk()->assertJsonPath('total', fn ($n) => $n > 0);
        }
    }

    public function test_course_saves_with_its_lessons(): void
    {
        $form = $this->metaId('lms_course_form');

        $course = $this->postJson("/api/metas/{$form}/data", [
            'code' => 'TST-100', 'title' => 'Testing 101', 'level' => 'beginner', 'status' => 'draft', 'capacity' => 10,
            'lessons' => [
                ['title' => 'Why test', 'kind' => 'reading', 'duration_minutes' => 15],
                ['title' => 'First test', 'kind' => 'video', 'duration_minutes' => 30],
            ],
        ])->assertCreated()->assertJsonCount(2, 'lessons')->json();

        $this->assertSame(2, DB::table('lms_lessons')->where('course_id', $course['id'])->count());

        $this->postJson("/api/metas/{$form}/data", [
            'code' => 'TST-101', 'title' => 'Bad', 'level' => 'expert', 'status' => 'draft', 'capacity' => 10,
            'lessons' => [['title' => 'x', 'kind' => 'podcast', 'duration_minutes' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lessons.0.duration_minutes']);
    }

    public function test_enrollment_progress_is_limited_to_100(): void
    {
        $form = $this->metaId('lms_enrollment_form');

        $this->postJson("/api/metas/{$form}/data", [
            'student_id' => DB::table('lms_students')->value('id'),
            'course_id' => DB::table('lms_courses')->value('id'),
            'enrolled_on' => '2026-10-20', 'status' => 'enrolled', 'progress' => 120,
        ])->assertUnprocessable()->assertJsonValidationErrors(['progress']);
    }
}
