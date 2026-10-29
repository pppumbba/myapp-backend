<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\Role;
use App\Models\User;
use App\Support\Contents;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** singlefile and multifile params: uploads kept in contents, put on records through content_map. */
class ContentTest extends TestCase
{
    use RefreshDatabase;

    private Meta $form;

    private User $admin;

    private MetaParam $files;

    private MetaParam $scan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProcurementDemoSeeder::class);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
        $this->form = Meta::where('code', 'proc_order_form')->firstOrFail();
        $this->files = MetaParam::create(['main_meta_id' => $this->form->id, 'order_number' => 90, 'field_path' => 'attachments', 'param_name' => 'attachments', 'label_name' => 'Attachments', 'data_type' => 'multifile', 'is_show' => true, 'json_config' => ['max_files' => 3]]);
        $this->scan = MetaParam::create(['main_meta_id' => $this->form->id, 'order_number' => 91, 'field_path' => 'contract_scan', 'param_name' => 'contract_scan', 'label_name' => 'Contract scan', 'data_type' => 'singlefile', 'is_show' => true, 'json_config' => ['accept' => ['pdf']]]);
    }

    private function upload(MetaParam $param, string $name = 'quote.pdf', int $kb = 10): array
    {
        return $this->post("/api/metas/{$this->form->id}/files", ['param_id' => $param->id, 'file' => UploadedFile::fake()->create($name, $kb)], ['Accept' => 'application/json'])->assertCreated()->json();
    }

    private function order(array $extra = []): array
    {
        return [
            'supplier_id' => DB::table('proc_suppliers')->value('id'),
            'order_date' => '2026-10-26',
            'lines' => [['item_id' => DB::table('proc_items')->value('id'), 'quantity' => 1, 'unit_price' => 100]],
        ] + $extra;
    }

    public function test_files_upload_then_save_onto_the_record_with_their_descriptions(): void
    {
        $quote = $this->upload($this->files, 'quote.pdf');
        $photo = $this->upload($this->files, 'photo.jpg');
        $scan = $this->upload($this->scan, 'contract.pdf');

        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order([
            'attachments' => [['id' => $quote['id'], 'description' => 'Supplier quote'], $photo['id']],
            'contract_scan' => ['id' => $scan['id']],
        ]))->assertCreated()->json();

        $this->assertSame(['quote.pdf', 'photo.jpg'], array_column($order['attachments'], 'file_name'));
        $this->assertSame('Supplier quote', $order['attachments'][0]['description']);
        $this->assertSame('contract.pdf', $order['contract_scan']['file_name']);
        $this->assertDatabaseHas('content_map', ['content_id' => $scan['id'], 'record_id' => $order['id'], 'workflow_meta_id' => $this->form->id, 'param_id' => $this->scan->id]);

        // Read back through the record and through the list.
        $this->getJson("/api/metas/{$this->form->id}/data/{$order['id']}")->assertJsonPath('attachments.1.file_name', 'photo.jpg');
        $row = collect($this->getJson("/api/metas/{$this->form->id}/data?per_page=100")->json('data'))->firstWhere('id', $order['id']);
        $this->assertCount(2, $row['attachments']);
        $this->assertNull(collect($this->getJson("/api/metas/{$this->form->id}/data?per_page=100")->json('data'))->firstWhere('id', '!=', $order['id'])['contract_scan']);

        $this->get("/api/contents/{$quote['id']}?download=1")->assertOk()->assertDownload('quote.pdf');
    }

    public function test_a_params_files_outlive_it_so_restoring_the_param_brings_them_back(): void
    {
        $scan = $this->upload($this->scan, 'contract.pdf');
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['contract_scan' => $scan['id']]))->assertCreated()->json();
        $row = $this->scan->getAttributes();

        $this->scan->delete();
        $this->assertDatabaseHas('content_map', ['content_id' => $scan['id']]);

        DB::table('meta_params')->insert(array_merge($row, ['json_config' => json_encode(['accept' => ['pdf']])]));
        $this->getJson("/api/metas/{$this->form->id}/data/{$order['id']}")->assertJsonPath('contract_scan.file_name', 'contract.pdf');
    }

    public function test_files_left_out_are_deleted_and_so_are_a_deleted_records(): void
    {
        $quote = $this->upload($this->files);
        $photo = $this->upload($this->files, 'photo.jpg');
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['attachments' => [$quote['id'], $photo['id']]]))->assertCreated()->json();
        $quotePath = Content::find($quote['id'])->physical_path;
        Storage::disk('local')->assertExists($quotePath);

        // An update without the field keeps the files; with it, the list is what stays.
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order())->assertOk()->assertJsonCount(2, 'attachments');
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order(['attachments' => [$photo['id']]]))->assertOk()->assertJsonCount(1, 'attachments');
        $this->assertDatabaseMissing('contents', ['id' => $quote['id']]);
        Storage::disk('local')->assertMissing($quotePath);

        $this->deleteJson("/api/metas/{$this->form->id}/data/{$order['id']}")->assertNoContent();
        $this->assertSame(0, Content::count());
        $this->assertSame(0, DB::table('content_map')->count());
    }

    public function test_fields_check_their_files(): void
    {
        // Blocked kinds, the field's own list, size, and how many.
        $this->postJson("/api/metas/{$this->form->id}/files", ['param_id' => $this->files->id, 'file' => UploadedFile::fake()->create('run.php', 1)])->assertJsonValidationErrors('file');
        $this->postJson("/api/metas/{$this->form->id}/files", ['param_id' => $this->scan->id, 'file' => UploadedFile::fake()->create('scan.jpg', 1)])->assertJsonValidationErrors('file');
        $this->postJson("/api/metas/{$this->form->id}/files", ['param_id' => $this->files->id, 'file' => UploadedFile::fake()->create('big.pdf', 30000)])->assertJsonValidationErrors('file');
        $ids = array_map(fn ($i) => $this->upload($this->files, "f{$i}.pdf")['id'], range(1, 4));
        $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['attachments' => $ids]))->assertJsonValidationErrors('attachments');

        // A required field needs a file.
        $this->scan->update(['is_required' => true]);
        $this->postJson("/api/metas/{$this->form->id}/data", $this->order())->assertJsonValidationErrors('contract_scan');
    }

    public function test_only_your_own_uploads_attach_and_private_files_need_access(): void
    {
        $clerk = User::factory()->create();
        $clerk->roles()->attach(Role::firstOrCreate(['code' => 'clerk'], ['name' => 'Clerk'])->id);
        $outsider = User::factory()->create();
        $this->putJson("/api/metas/{$this->form->id}/permissions", ['rows' => [
            ['role_id' => Role::where('code', 'clerk')->value('id'), 'can_view' => true, 'can_create' => true, 'can_edit' => true],
        ]])->assertOk();

        $mine = $this->upload($this->files);
        Sanctum::actingAs($clerk);
        // Someone else's upload, not on any record, is theirs alone.
        $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['attachments' => [$mine['id']]]))->assertJsonValidationErrors('attachments');
        $this->getJson("/api/contents/{$mine['id']}")->assertNotFound();

        Sanctum::actingAs($this->admin);
        $order = $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['attachments' => [$mine['id']]]))->assertCreated()->json();

        // Once on a record, whoever may open the form may download it; others may not.
        Sanctum::actingAs($clerk);
        $this->get("/api/contents/{$mine['id']}")->assertOk();
        $this->putJson("/api/metas/{$this->form->id}/data/{$order['id']}", $this->order(['attachments' => [$mine['id']]]))->assertOk();
        Sanctum::actingAs($outsider);
        $this->getJson("/api/contents/{$mine['id']}")->assertNotFound();
    }

    public function test_public_files_open_without_signing_in_and_stale_uploads_are_pruned(): void
    {
        $this->files->update(['json_config' => ['public' => true, 'type_id' => 7]]);
        $public = $this->upload($this->files, 'brochure.pdf');
        $this->assertDatabaseHas('contents', ['id' => $public['id'], 'is_public' => true, 'type_id' => 7]);

        $this->app['auth']->forgetGuards();
        $this->get("/api/contents/{$public['id']}")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        Content::whereKey($public['id'])->update(['created_at' => now()->subDays(2)]);
        $this->assertSame(1, Contents::pruneStale());
        $this->assertSame(0, Content::count());
    }

    public function test_files_sent_through_a_public_form_stay_hidden_from_other_users(): void
    {
        $this->form->update(['is_public' => true]);
        $file = $this->upload($this->scan, 'contract.pdf');
        $this->postJson("/api/metas/{$this->form->id}/data", $this->order(['contract_scan' => ['id' => $file['id']]]))->assertCreated();

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/contents/{$file['id']}")->assertNotFound();

        Sanctum::actingAs($this->admin);
        $this->get("/api/contents/{$file['id']}")->assertOk();
    }
}
