<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaStructureTest extends TestCase
{
    use RefreshDatabase;

    private Meta $structure;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->structure = Meta::create([
            'code' => 'st_books', 'name' => 'Books', 'type_id' => MetaType::where('code', 'structure')->value('id'),
            'table_name' => 'st_books', 'is_active' => true,
        ]);
        $this->param('title', 'Title', 'string', ['is_required' => true, 'json_config' => ['length' => 120]]);
        $this->param('isbn', 'ISBN', 'string', ['is_unique' => true]);
        $this->param('pages', 'Pages', 'integer');
    }

    private function param(string $field, string $label, string $type, array $extra = []): MetaParam
    {
        return MetaParam::create($extra + [
            'main_meta_id' => $this->structure->id, 'order_number' => MetaParam::where('main_meta_id', $this->structure->id)->count() + 1,
            'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => $type,
        ]);
    }

    public function test_applying_makes_the_table_and_a_grid_and_form_that_save_into_it(): void
    {
        $this->getJson("/api/metas/{$this->structure->id}/structure")->assertOk()
            ->assertJsonPath('creating', true)
            ->assertJsonPath('problems', [])
            ->assertJsonPath('changes.0.detail', 'string(120), required');
        $this->assertFalse(Schema::hasTable('st_books'));

        $done = $this->postJson("/api/metas/{$this->structure->id}/structure/apply", ['make_screens' => true])->assertOk()
            ->assertJsonPath('plan.creating', false)
            ->assertJsonPath('plan.changes', [])
            ->json();

        $this->assertTrue(Schema::hasColumns('st_books', ['id', 'title', 'isbn', 'pages', 'created_at', 'updated_at']));
        $this->assertSame($done['form_id'], Meta::find($done['grid_id'])->ref_meta_id);

        $this->postJson("/api/metas/{$done['form_id']}/data", ['title' => 'Dune', 'isbn' => '978-0', 'pages' => 412])->assertCreated();
        $this->assertSame(412, (int) DB::table('st_books')->where('title', 'Dune')->value('pages'));
        $this->postJson("/api/metas/{$done['form_id']}/data", ['pages' => 1])->assertUnprocessable();
    }

    public function test_later_applies_add_and_change_columns_but_never_drop_one(): void
    {
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertOk();
        DB::table('st_books')->insert(['title' => 'Emma', 'isbn' => '1', 'pages' => 300]);

        $this->param('author', 'Author', 'string');
        MetaParam::where('main_meta_id', $this->structure->id)->where('field_path', 'title')->update(['json_config' => json_encode(['length' => 200])]);
        MetaParam::where('main_meta_id', $this->structure->id)->where('field_path', 'pages')->delete();

        $plan = $this->getJson("/api/metas/{$this->structure->id}/structure")->assertOk()->assertJsonPath('problems', [])->json();
        $kinds = array_column($plan['changes'], 'kind', 'column');
        $this->assertSame(['title' => 'change', 'author' => 'add', 'pages' => 'keep'], $kinds);

        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertOk()->assertJsonPath('plan.changes.0.kind', 'keep');

        $this->assertTrue(Schema::hasColumns('st_books', ['author', 'pages']));
        $this->assertSame(300, (int) DB::table('st_books')->value('pages'));
        DB::table('st_books')->where('title', 'Emma')->update(['title' => str_repeat('x', 180)]);
        $this->assertSame(180, strlen(DB::table('st_books')->value('title')));
    }

    public function test_it_stops_on_changes_the_data_cannot_take(): void
    {
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertOk();
        DB::table('st_books')->insert([['title' => 'A', 'isbn' => '1', 'pages' => null], ['title' => 'B', 'isbn' => '2', 'pages' => 5]]);

        $this->param('author', 'Author', 'string', ['is_required' => true]);
        MetaParam::where('main_meta_id', $this->structure->id)->where('field_path', 'pages')->update(['is_required' => true]);

        $problems = $this->getJson("/api/metas/{$this->structure->id}/structure")->assertOk()->json('problems');
        $this->assertCount(2, $problems);
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertUnprocessable();
        $this->assertFalse(Schema::hasColumn('st_books', 'author'));
    }

    public function test_it_only_makes_new_tables_and_never_renames_its_own(): void
    {
        $this->structure->update(['table_name' => 'users']);
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertUnprocessable();

        Schema::create('st_taken', fn ($t) => $t->id());
        $this->structure->update(['table_name' => 'st_taken']);
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertUnprocessable();
        $this->assertSame(['id'], Schema::getColumnListing('st_taken'));

        $this->structure->update(['table_name' => 'st_books']);
        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertOk();
        $this->structure->update(['table_name' => 'st_novels']);
        $this->getJson("/api/metas/{$this->structure->id}/structure")->assertOk()->assertJsonCount(1, 'problems');
        $this->assertFalse(Schema::hasTable('st_novels'));
    }

    public function test_only_admins_apply(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/metas/{$this->structure->id}/structure/apply")->assertForbidden();
        $this->assertFalse(Schema::hasTable('st_books'));
    }
}
