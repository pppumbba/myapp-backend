<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\Role;
use App\Models\User;
use App\Support\Xlsx;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function metaId(string $code): int
    {
        return Meta::where('code', $code)->value('id');
    }

    /** Reads a downloaded workbook's first sheet back. */
    private function sheet(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'test').'.xlsx';
        file_put_contents($path, $bytes);

        return Xlsx::read($path);
    }

    private function upload(array $headers, array $rows, array $types = []): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rows.xlsx', Xlsx::write('Sheet1', $headers, $rows, $types));
    }

    public function test_export_writes_the_grids_columns_with_lookup_names(): void
    {
        $response = $this->get("/api/metas/{$this->metaId('proc_requests_grid')}/export")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $rows = $this->sheet($response->getContent());
        $this->assertSame(['Request', 'Title', 'Department', 'Requested by', 'Item', 'Qty', 'Needed by', 'Status'], $rows[0]);
        $this->assertCount(DB::table('proc_purchase_requests')->count() + 1, $rows);

        $items = DB::table('proc_items')->pluck('name')->all();
        foreach (array_slice($rows, 1) as $row) {
            $this->assertContains($row[4], $items);
        }
    }

    public function test_export_applies_the_grids_filters(): void
    {
        $response = $this->get("/api/metas/{$this->metaId('proc_suppliers_grid')}/export?search=Northwind")->assertOk();

        $this->assertCount(2, $this->sheet($response->getContent()));
    }

    public function test_template_has_the_imports_headings(): void
    {
        $response = $this->get("/api/metas/{$this->metaId('proc_items_import')}/import/template")->assertOk();

        $this->assertSame(['Item code', 'Name', 'Category', 'Unit', 'Unit price'], $this->sheet($response->getContent())[0]);
    }

    public function test_upsert_adds_new_codes_and_updates_known_ones(): void
    {
        $import = $this->metaId('proc_items_import');
        $file = $this->upload(['Item code', 'Name', 'unit price', 'Unit'], [
            ['IT-001', 'Laptop 15"', 2600000, 'PCS'],
            ['NEW-01', 'Stapler', '15,000', null],
        ], ['string', 'string', 'decimal', 'string']);

        $this->post("/api/metas/{$import}/import", ['file' => $file, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('saved', false);
        $this->assertDatabaseMissing('proc_items', ['code' => 'NEW-01']);

        $this->post("/api/metas/{$import}/import", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('error_count', 0);

        $this->assertDatabaseHas('proc_items', ['code' => 'IT-001', 'name' => 'Laptop 15"', 'unit_price' => 2600000, 'unit' => 'pcs', 'category' => 'IT equipment']);
        $this->assertDatabaseHas('proc_items', ['code' => 'NEW-01', 'name' => 'Stapler', 'unit_price' => 15000, 'unit' => 'pcs']);
    }

    public function test_requests_import_finds_items_by_name_and_fills_defaults(): void
    {
        $item = DB::table('proc_items')->where('code', 'OF-001')->first();
        $file = $this->upload(['Request number', 'Title', 'Item', 'Needed by', 'Department'], [
            ['PR-X-1', 'Paper for Q4', 'a4 copy paper', 46315, 'finance'],
        ]);

        $this->post("/api/metas/{$this->metaId('proc_requests_import')}/import", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $this->assertDatabaseHas('proc_purchase_requests', [
            'request_no' => 'PR-X-1', 'item_id' => $item->id, 'needed_by' => '2026-10-20',
            'department' => 'Finance', 'status' => 'draft', 'quantity' => 1,
        ]);
    }

    public function test_one_bad_row_saves_nothing_and_says_where(): void
    {
        $before = DB::table('proc_purchase_requests')->count();
        $file = $this->upload(['Request number', 'Title', 'Item', 'Quantity'], [
            ['PR-X-1', 'Fine', 'Office chair', 2],
            ['PR-X-2', 'Bad item', 'Spaceship', 1],
            ['PR-X-1', 'Twice', 'Office chair', 0],
        ]);

        $response = $this->post("/api/metas/{$this->metaId('proc_requests_import')}/import", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('saved', false);

        $errors = collect($response->json('errors'));
        $this->assertTrue($errors->contains(fn ($e) => $e['row'] === 3 && $e['column'] === 'Item'));
        $this->assertTrue($errors->contains(fn ($e) => $e['row'] === 4 && $e['column'] === 'Request number'));
        $this->assertTrue($errors->contains(fn ($e) => $e['row'] === 4 && $e['column'] === 'Quantity'));
        $this->assertSame($before, DB::table('proc_purchase_requests')->count());
    }

    public function test_a_missing_required_column_stops_the_import(): void
    {
        $file = $this->upload(['Request number', 'Item'], [['PR-X-1', 'Office chair']]);

        $this->post("/api/metas/{$this->metaId('proc_requests_import')}/import", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('errors.0.row', 1)
            ->assertJsonPath('errors.0.column', 'Title');
    }

    public function test_csv_files_import_too(): void
    {
        $file = UploadedFile::fake()->createWithContent('items.csv', "\u{FEFF}Item code;Name;Unit price\nCSV-1;Tape;4500\n");

        $this->post("/api/metas/{$this->metaId('proc_items_import')}/import", ['file' => $file])->assertOk()->assertJsonPath('inserted', 1);
        $this->assertDatabaseHas('proc_items', ['code' => 'CSV-1', 'unit_price' => 4500]);
    }

    public function test_importing_needs_create_permission_on_the_import(): void
    {
        $import = $this->metaId('proc_items_import');
        $role = Role::create(['code' => 'viewer', 'name' => 'Viewer']);
        MetaPermission::create(['meta_id' => $import, 'role_id' => $role->id, 'can_view' => true]);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        $this->post("/api/metas/{$import}/import", ['file' => $this->upload(['Item code', 'Name', 'Unit price'], [['Z-1', 'Z', 1]])])
            ->assertForbidden();
    }

    public function test_grid_toolbar_offers_the_import(): void
    {
        $this->getJson("/api/metas/{$this->metaId('proc_items_grid')}/actions")
            ->assertOk()
            ->assertJsonFragment(['action' => 'import', 'form_meta_id' => $this->metaId('proc_items_import')]);
    }
}
