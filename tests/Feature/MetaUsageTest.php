<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\User;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Where each meta is used: fields, buttons, settings and page widgets pointing at it, for admins only. */
class MetaUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetaTypeSeeder::class);
    }

    private function meta(string $code, string $type, array $extra = []): Meta
    {
        return Meta::create(['code' => $code, 'name' => ucfirst(str_replace('_', ' ', $code)), 'type_id' => MetaType::where('code', $type)->value('id')] + $extra);
    }

    public function test_each_place_pointing_at_a_meta_is_listed_with_the_meta_using_it(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $suppliers = $this->meta('suppliers', 'datagrid', ['table_name' => 'suppliers']);
        $form = $this->meta('order_form', 'form', ['table_name' => 'orders']);
        $grid = $this->meta('orders', 'datagrid', ['table_name' => 'orders', 'json_config' => ['print_meta_id' => $suppliers->id]]);
        MetaParam::create(['main_meta_id' => $form->id, 'order_number' => 1, 'field_path' => 'supplier_id', 'param_name' => 'supplier_id', 'label_name' => 'Supplier', 'data_type' => 'integer', 'ref_meta_id' => $suppliers->id]);
        DB::table('meta_actions')->insert(['grid_meta_id' => $grid->id, 'action' => 'add', 'label' => 'New order', 'form_meta_id' => $form->id]);
        $page = $this->meta('home', 'page');
        $this->meta('home_orders', 'widget', ['ref_meta_id' => $page->id, 'json_config' => ['meta_id' => $grid->id]]);
        $unused = $this->meta('old_report', 'report');

        $rows = collect($this->getJson('/api/meta-usage')->assertOk()->json())->keyBy('code');

        $this->assertSame([['Order form', 'Field “Supplier”'], ['Orders', 'Link (print)']], $this->uses($rows['suppliers']['used_by']));
        $this->assertSame([['Orders', 'Button “New order” opens it']], $this->uses($rows['order_form']['used_by']));
        // A widget counts as its page using what it shows, and is not listed itself.
        $this->assertSame([['Home', 'Widget “Home orders”']], $this->uses($rows['orders']['used_by']));
        $this->assertArrayNotHasKey('home_orders', $rows->all());
        $this->assertSame([], $rows['home']['used_by']);
        $this->assertSame([], $rows['old_report']['used_by']);

        $this->assertSame([['Order form', 'Field “Supplier”'], ['Orders', 'Link (print)']], $this->uses($this->getJson("/api/metas/{$suppliers->id}/usage")->assertOk()->json()));
        $this->getJson("/api/metas/{$unused->id}/usage")->assertOk()->assertExactJson([]);
    }

    public function test_only_admins_see_where_metas_are_used(): void
    {
        $meta = $this->meta('orders', 'datagrid');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/meta-usage')->assertForbidden();
        $this->getJson("/api/metas/{$meta->id}/usage")->assertForbidden();
    }

    /** @return list<array{0: ?string, 1: string}> */
    private function uses(array $usedBy): array
    {
        return array_map(fn (array $use) => [$use['meta']['name'] ?? null, $use['where']], $usedBy);
    }
}
