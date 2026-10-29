<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\User;
use App\Support\Xlsx;
use Database\Seeders\MetaTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A table and datagrid made from a spreadsheet: the first row left labels the columns, the rest are rows. */
class ExcelStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(MetaTypeSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** A sheet with a title row, the header, three rows, a totals row and a notes column; a second sheet. */
    private function upload(): array
    {
        $bytes = Xlsx::write('Stock', ['Stock report 2026', null, null, null, null], [
            [null, null, null, null, null],
            ['Item', 'Qty', 'Price', 'Received', 'Notes'],
            ['Tape', 12, 4500.5, '2026-03-01', 'ok'],
            ['Glue', 3, 1200, '2026-03-04', null],
            ['Paper', 40, 9800.25, '2026-03-09', 'check'],
            ['Total', 55, null, null, null],
        ], [], ['Other' => [['a', 'b']]]);

        return $this->post('/api/excel-structure', ['file' => UploadedFile::fake()->createWithContent('stock.xlsx', $bytes)])->assertCreated()->json();
    }

    public function test_it_shows_the_file_its_sheets_and_the_columns_the_rows_left_would_make(): void
    {
        $file = $this->upload();
        $this->assertSame(['Stock', 'Other'], $file['sheets']);
        $this->assertSame('Stock report 2026', $file['rows'][0][0]);
        $this->assertSame(7, $file['total']);
        $this->assertSame(5, $file['width']);

        $other = $this->getJson("/api/excel-structure/{$file['token']}?sheet=Other")->assertOk()->json();
        $this->assertSame([['a', 'b']], $other['rows']);

        // Title row and totals row removed (the empty row goes by itself), notes column too.
        $columns = $this->postJson("/api/excel-structure/{$file['token']}/columns", ['skip_rows' => [0, 6], 'skip_columns' => [4]])->assertOk()->json();
        $this->assertSame(['c1', 'c2', 'c3', 'c4'], array_column($columns, 'column'));
        $this->assertSame(['Item', 'Qty', 'Price', 'Received'], array_column($columns, 'label'));
        $this->assertSame(['string', 'integer', 'decimal', 'date'], array_column($columns, 'data_type'));
    }

    public function test_it_makes_the_table_puts_the_rows_in_and_a_datagrid_over_it(): void
    {
        $file = $this->upload();
        $meta = $this->postJson("/api/excel-structure/{$file['token']}/create", [
            'skip_rows' => [0, 6], 'skip_columns' => [4], 'name' => 'Stock report', 'labels' => [0 => 'Item name'],
        ])->assertCreated()->json();

        $this->assertSame('xl_stock_report', $meta['table_name']);
        $this->assertSame('stock_report_grid', $meta['code']);
        $this->assertSame(['id', 'c1', 'c2', 'c3', 'c4'], Schema::getColumnListing('xl_stock_report') === [] ? [] : array_values(array_diff(Schema::getColumnListing('xl_stock_report'), ['created_at', 'updated_at'])));
        $rows = DB::table('xl_stock_report')->orderBy('id')->get();
        $this->assertSame(['Tape', 'Glue', 'Paper'], $rows->pluck('c1')->all());
        $this->assertSame([12, 3, 40], $rows->pluck('c2')->map(fn ($v) => (int) $v)->all());
        $this->assertEqualsWithDelta(9800.25, (float) $rows[2]->c3, 0.001);
        $this->assertSame('2026-03-04', substr((string) $rows[1]->c4, 0, 10));

        $params = MetaParam::where('main_meta_id', $meta['id'])->orderBy('order_number')->get();
        $this->assertSame(['id', 'c1', 'c2', 'c3', 'c4'], $params->pluck('field_path')->all());
        $this->assertSame(['ID', 'Item name', 'Qty', 'Price', 'Received'], $params->pluck('label_name')->all());
        $this->assertTrue((bool) $params[0]->is_primary);

        // The grid reads its rows like any other.
        $data = $this->getJson("/api/metas/{$meta['id']}/data")->assertOk()->json('data');
        $this->assertCount(3, $data);

        // The file is gone once used.
        $this->getJson("/api/excel-structure/{$file['token']}")->assertNotFound();
    }

    public function test_a_value_that_doesnt_fit_its_type_stops_it_before_any_table_is_made(): void
    {
        $file = $this->upload();
        // Items aren't whole numbers; the message gives the row as Excel numbers it.
        $this->postJson("/api/excel-structure/{$file['token']}/create", [
            'skip_rows' => [0, 6], 'skip_columns' => [4], 'name' => 'Stock report', 'types' => [0 => 'integer'],
        ])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Row 4, "Item"'));
        $this->assertFalse(Schema::hasTable('xl_stock_report'));
        $this->assertSame(0, Meta::count());

        // Only xl_ tables, and not one that exists.
        $this->postJson("/api/excel-structure/{$file['token']}/create", ['skip_rows' => [0, 6], 'name' => 'Stock', 'table' => 'users'])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'must start with xl_'));
    }

    public function test_empty_rows_at_the_bottom_and_empty_columns_on_the_right_are_left_out(): void
    {
        $bytes = Xlsx::write('Sheet1', ['Name', 'Qty', '', ''], [
            ['Tape', 1, '', ''],
            ['Glue', 2, null, ''],
            ['', '', '', ''],
            [null, '', null, ''],
        ]);
        $file = $this->post('/api/excel-structure', ['file' => UploadedFile::fake()->createWithContent('pad.xlsx', $bytes)])->assertCreated()->json();

        $this->assertSame(2, $file['width']);
        $this->assertSame(3, $file['total']);
        $this->assertSame([['Name', 'Qty'], ['Tape', '1'], ['Glue', '2']], $file['rows']);
    }

    public function test_only_admins_may_use_it(): void
    {
        $file = $this->upload();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/excel-structure/{$file['token']}")->assertForbidden();
        $this->postJson("/api/excel-structure/{$file['token']}/create", ['name' => 'x'])->assertForbidden();
    }
}
