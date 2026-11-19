<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\User;
use Database\Seeders\ConsolidateExampleSeeder;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The Service billing example: Blue Sky's five jobs become one invoice with a line per item. */
class ConsolidateExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_picked_jobs_become_an_invoice_that_saves_with_its_totals(): void
    {
        $this->seed([MetaTypeSeeder::class, ConsolidateExampleSeeder::class]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $grid = Meta::where('code', 'cx_jobs_grid')->value('id');
        $form = Meta::where('code', 'cx_invoice_form')->value('id');
        $button = MetaAction::where('grid_meta_id', $grid)->where('action', 'consolidate')->value('id');
        $ids = DB::table('cx_service_jobs')->where('customer', 'Blue Sky LLC')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $invoice = $this->postJson("/api/metas/{$grid}/consolidate/{$button}", ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('customer', 'Blue Sky LLC')
            ->assertJsonPath('technicians', 'Bat, Dorj')
            ->assertJsonPath('jobs', 5)
            ->assertJsonPath('period_from', '2026-11-02')
            ->assertJsonPath('period_to', '2026-11-06')
            ->assertJsonPath('job_numbers', 'JOB-0001, JOB-0002, JOB-0003, JOB-0004, JOB-0005')
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.0.item', 'Labour hour')
            ->assertJsonPath('lines.0.qty', fn ($v) => (float) $v === 6.5)
            ->assertJsonPath('lines.0.description', 'Router setup, Printer repair, Server check')
            ->assertJsonPath('lines.1.qty', fn ($v) => (float) $v === 42.0)
            ->json();

        $this->postJson("/api/metas/{$form}/data", $invoice + ['invoice_date' => '2026-11-15'])
            ->assertCreated()
            ->assertJsonPath('lines.1.amount', fn ($v) => (float) $v === 105000.0)
            // 6.5 × 46666.67 (the average price) + 42 × 2500
            ->assertJsonPath('total', fn ($v) => (float) $v === 408333.36);
    }
}
