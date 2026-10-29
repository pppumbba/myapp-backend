<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\User;
use App\Support\Documents;
use App\Support\NumberWords;
use Database\Seeders\ProcurementDemoSeeder;
use Database\Seeders\ProcurementReportsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/** Report metas print as PDF documents from a designed layout. */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private Meta $report;

    private User $admin;

    private array $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProcurementDemoSeeder::class, ProcurementReportsSeeder::class]);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
        $this->report = Meta::where('code', 'proc_order_report')->firstOrFail();
        $form = Meta::where('code', 'proc_order_form')->firstOrFail();
        $this->order = $this->postJson("/api/metas/{$form->id}/data", [
            'supplier_id' => DB::table('proc_suppliers')->value('id'),
            'order_date' => '2026-10-21',
            'lines' => [['item_id' => DB::table('proc_items')->where('code', 'IT-002')->value('id'), 'quantity' => 3, 'unit_price' => 1250]],
        ])->assertCreated()->json();
    }

    public function test_a_report_without_a_layout_gets_a_starter_one_from_its_params(): void
    {
        $response = $this->getJson("/api/metas/{$this->report->id}/layout")->assertOk()->assertJsonPath('saved', false);

        $sections = $response->json('layout.sections');
        $table = collect($sections)->firstWhere('kind', 'table');
        $this->assertSame('lines', $table['detail']);
        $this->assertTrue(collect($table['columns'])->firstWhere('field', 'amount')['total']);
        $types = collect($sections)->pluck('elements')->flatten(1)->pluck('type');
        $this->assertSame(2, $types->filter(fn ($t) => $t === 'signature')->count());
    }

    public function test_a_record_prints_with_its_lookup_names_lines_totals_and_amount_in_words(): void
    {
        $layout = Documents::normalize([
            'page' => ['currency_word' => 'төгрөг', 'subunit_word' => 'мөнгө'],
            'sections' => [
                ['kind' => 'free', 'height' => 20, 'elements' => [
                    ['type' => 'field', 'field' => 'supplier_id', 'x' => 0, 'y' => 0, 'w' => 80, 'h' => 6],
                    ['type' => 'text', 'text' => 'Total {lines.sum.amount|money}: {lines.sum.amount|words}', 'x' => 0, 'y' => 8, 'w' => 180, 'h' => 6],
                ]],
                ['kind' => 'table', 'detail' => 'lines', 'columns' => [
                    ['field' => 'item_id', 'label' => 'Item'],
                    ['field' => 'amount', 'label' => 'Amount', 'format' => 'number', 'total' => true, 'align' => 'right'],
                ]],
            ],
        ]);
        $record = Documents::record($this->report, $this->order['id'], $this->admin);
        $html = Documents::html($this->report, $layout, [$record]);

        $this->assertStringContainsString(e(DB::table('proc_suppliers')->value('name')), $html);
        $this->assertStringContainsString(e(DB::table('proc_items')->where('code', 'IT-002')->value('name')), $html);
        $this->assertStringContainsString('3,750.00 ₮', $html);
        $this->assertStringContainsString('Гурван мянга долоон зуун тавин төгрөг 00 мөнгө', $html);

        $this->get("/api/metas/{$this->report->id}/data/{$this->order['id']}/pdf?download=1")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="Purchase order '.$this->order['po_number'].'.pdf"; filename*=UTF-8\'\''.rawurlencode('Purchase order '.$this->order['po_number'].'.pdf'));
    }

    public function test_only_admins_save_layouts_and_saved_layouts_are_cleaned(): void
    {
        $layout = ['page' => ['size' => 'A5', 'margin' => ['top' => 500]], 'sections' => [
            ['kind' => 'free', 'height' => 30, 'elements' => [['type' => 'script', 'text' => 'x'], ['type' => 'image', 'src' => '../../.env']]],
            ['kind' => 'nope'],
        ]];

        $saved = $this->putJson("/api/metas/{$this->report->id}/layout", ['layout' => $layout])->assertOk()->json('layout');
        $this->assertSame('A5', $saved['page']['size']);
        $this->assertEquals(60, $saved['page']['margin']['top']);
        $this->assertCount(1, $saved['sections']);
        $this->assertSame([''], array_column($saved['sections'][0]['elements'], 'src'));
        $this->getJson("/api/metas/{$this->report->id}/layout")->assertJsonPath('saved', true)->assertJsonPath('layout.page.size', 'A5');

        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/metas/{$this->report->id}/layout", ['layout' => $layout])->assertForbidden();
    }

    public function test_several_records_print_in_one_pdf_and_only_report_metas_print(): void
    {
        $this->get("/api/metas/{$this->report->id}/pdf?ids={$this->order['id']},{$this->order['id']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/metas/{$this->report->id}/layout/preview", ['layout' => Documents::starter($this->report)])->assertOk();

        $form = Meta::where('code', 'proc_order_form')->firstOrFail();
        $this->getJson("/api/metas/{$form->id}/data/{$this->order['id']}/pdf")->assertStatus(422);
    }

    public function test_images_upload_for_admins_and_only_images(): void
    {
        Storage::fake('local');
        $src = $this->post("/api/metas/{$this->report->id}/document-assets", ['file' => UploadedFile::fake()->createWithContent('logo.png', $this->png(40, 20))])->assertCreated()->json('src');
        $this->get("/api/metas/{$this->report->id}/document-assets/{$src}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson("/api/metas/{$this->report->id}/document-assets", ['file' => UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;')])->assertUnprocessable();
        $this->get("/api/metas/{$this->report->id}/document-assets/..%2F..%2F.env")->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->post("/api/metas/{$this->report->id}/document-assets", ['file' => UploadedFile::fake()->createWithContent('logo.png', $this->png(4, 4))])->assertForbidden();
    }

    #[RequiresPhpExtension('gd')]
    public function test_uploaded_images_are_embedded_in_the_pdf(): void
    {
        Storage::fake('local');
        $src = $this->post("/api/metas/{$this->report->id}/document-assets", ['file' => UploadedFile::fake()->createWithContent('logo.png', $this->png(40, 20))])->assertCreated()->json('src');

        $layout = Documents::normalize(['sections' => [['kind' => 'free', 'height' => 20, 'elements' => [['type' => 'image', 'src' => $src, 'x' => 0, 'y' => 0, 'w' => 40, 'h' => 10]]]]]);
        $html = Documents::html($this->report, $layout, [Documents::sample($this->report)]);

        $this->assertStringContainsString('src="data:image/png;base64,', $html);
        $this->assertStringContainsString('width: 20mm; height: 10mm', $html);
        $this->assertStringStartsWith('%PDF', Documents::pdf($this->report, $layout, [Documents::sample($this->report)]));
    }

    /** An opaque PNG of this size, made without GD (which PDFs only need for transparency). */
    private function png(int $width, int $height): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat(chr(0).str_repeat(chr(0x33).chr(0x66).chr(0x99), $width), $height);

        return chr(0x89).'PNG'.chr(13).chr(10).chr(26).chr(10)
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($rows))
            .$chunk('IEND', '');
    }

    public function test_a_band_background_guides_the_designer_and_prints_only_when_asked(): void
    {
        $src = '0b9c2d3e-1111-4222-8333-944455556666.jpg';
        $band = fn (bool $print, string $file = '0b9c2d3e-1111-4222-8333-944455556666.jpg') => Documents::normalize([
            'page' => ['margin' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
            'sections' => [['kind' => 'free', 'height' => 290, 'background' => ['src' => $file, 'print' => $print], 'elements' => []]],
        ]);
        $behind = '<div class="el" style="left: 0; top: 0; width: 210mm; height: 290mm">';

        $this->assertSame(['src' => $src, 'print' => false], $band(false)['sections'][0]['background']);
        $this->assertNull($band(true, '../../.env')['sections'][0]['background']);
        $this->assertStringNotContainsString($behind, Documents::html($this->report, $band(false), [Documents::sample($this->report)]));
        $this->assertStringContainsString($behind, Documents::html($this->report, $band(true), [Documents::sample($this->report)]));
    }

    public function test_amounts_in_words_take_the_joined_forms(): void
    {
        $this->assertSame('Хорин гурав', NumberWords::amount(23));
        $this->assertSame('Хоёр мянга таван зуу', NumberWords::amount(2500));
        $this->assertSame('Хорин гурван төгрөг 50', NumberWords::amount(23.5, 'mn', 'төгрөг'));
        $this->assertSame('Нэг сая хоёр зуун тавин мянган төгрөг 00 мөнгө', NumberWords::amount(1250000, 'mn', 'төгрөг', 'мөнгө'));
        $this->assertSame('One thousand two hundred thirty-four dollars 05/100', NumberWords::amount(1234.05, 'en', 'dollars'));
    }
}
