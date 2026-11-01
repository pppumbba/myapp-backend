<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A column's place on a record's widget, kept in meta_params.widget_position. */
class GridWidgetTest extends TestCase
{
    use RefreshDatabase;

    private MetaParam $column;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        $grid = Meta::where('code', 'proc_suppliers_grid')->firstOrFail();
        $this->column = MetaParam::where('main_meta_id', $grid->id)->where('is_show', true)->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_a_column_keeps_its_place_on_the_widget_and_can_go_back_to_automatic(): void
    {
        $this->putJson("/api/params/{$this->column->id}", ['widget_position' => 'subtitle'])
            ->assertOk()->assertJsonPath('widget_position', 'subtitle');
        $this->getJson("/api/metas/{$this->column->main_meta_id}/params")->assertOk()
            ->assertJsonFragment(['id' => $this->column->id, 'widget_position' => 'subtitle']);

        $this->putJson("/api/params/{$this->column->id}", ['widget_position' => null])
            ->assertOk()->assertJsonPath('widget_position', null);
    }

    public function test_a_place_must_be_a_known_one(): void
    {
        $this->putJson("/api/params/{$this->column->id}", ['widget_position' => 'middle'])
            ->assertUnprocessable()->assertJsonValidationErrors(['widget_position']);
    }
}
