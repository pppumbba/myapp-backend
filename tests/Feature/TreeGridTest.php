<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A grid with an is_parent param lists its records a level at a time, each saying how many it has under it. */
class TreeGridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());

        Schema::create('test_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
        });
        DB::table('test_accounts')->insert([
            ['id' => 1, 'name' => 'Assets', 'parent_id' => null],
            ['id' => 2, 'name' => 'Cash', 'parent_id' => 1],
            ['id' => 3, 'name' => 'Bank', 'parent_id' => 1],
            ['id' => 4, 'name' => 'Main account', 'parent_id' => 3],
            ['id' => 5, 'name' => 'Liabilities', 'parent_id' => null],
        ]);
    }

    private function grid(array $meta = ['table_name' => 'test_accounts']): Meta
    {
        $grid = Meta::create(['code' => 'accounts_'.uniqid(), 'name' => 'Accounts', 'type_id' => MetaType::where('code', 'datagrid')->value('id')] + $meta);
        foreach (['id' => ['integer', true, false], 'name' => ['string', false, false], 'parent_id' => ['integer', false, true]] as $field => [$type, $primary, $parent]) {
            MetaParam::create([
                'main_meta_id' => $grid->id, 'order_number' => 1, 'field_path' => $field, 'param_name' => $field, 'label_name' => $field,
                'data_type' => $type, 'is_primary' => $primary, 'is_parent' => $parent, 'is_show' => ! $primary, 'is_criteria' => $field === 'name',
            ]);
        }

        return $grid;
    }

    /** @return array<string, int> name => _children */
    private function level(Meta $grid, string $parent): array
    {
        $rows = $this->getJson("/api/metas/{$grid->id}/data?parent={$parent}&sort=id")->assertOk()->json('data');

        return array_column($rows, '_children', 'name');
    }

    public function test_a_tree_grid_lists_one_level_at_a_time_with_child_counts(): void
    {
        $grid = $this->grid();

        $this->assertSame(['Assets' => 2, 'Liabilities' => 0], $this->level($grid, ''));
        $this->assertSame(['Cash' => 0, 'Bank' => 1], $this->level($grid, '1'));
        $this->assertSame(['Main account' => 0], $this->level($grid, '3'));

        // Without ?parent it is a flat list, as before: a search finds records at any level.
        $this->getJson("/api/metas/{$grid->id}/data")->assertOk()->assertJsonCount(5, 'data')->assertJsonMissingPath('data.0._children');
        $this->getJson("/api/metas/{$grid->id}/data?search=account")->assertOk()->assertJsonPath('data.0.name', 'Main account');
    }

    public function test_a_query_grid_is_a_tree_too_and_the_flag_is_saved_on_params(): void
    {
        $grid = $this->grid(['query_string' => 'SELECT id, name, parent_id FROM test_accounts']);
        $this->assertSame(['Assets' => 2, 'Liabilities' => 0], $this->level($grid, ''));

        $param = MetaParam::where('main_meta_id', $grid->id)->where('field_path', 'name')->firstOrFail();
        $this->putJson("/api/params/{$param->id}", ['is_parent' => true])->assertOk()->assertJsonPath('is_parent', true);
    }
}
