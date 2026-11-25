<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaPermission;
use App\Models\MetaType;
use App\Models\MetaVersion;
use App\Models\Role;
use App\Models\User;
use App\Models\WfmStatus;
use App\Support\MetaVersions;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaVersionTest extends TestCase
{
    use RefreshDatabase;

    private Meta $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProcurementDemoSeeder::class);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->form = Meta::where('code', 'proc_request_form')->first();
    }

    private function param(string $field): ?MetaParam
    {
        return MetaParam::where('main_meta_id', $this->form->id)->where('field_path', $field)->first();
    }

    /** Version 1, then: a new name, a relabelled field, a new field and one removed. */
    private function versionThenChange(): int
    {
        $id = $this->postJson("/api/metas/{$this->form->id}/versions", ['note' => 'First'])->assertCreated()->assertJsonPath('version_no', 1)->json('id');

        $this->form->update(['name' => 'Renamed form']);
        $this->param('title')->update(['label_name' => 'Subject']);
        $this->param('notes')->delete();
        MetaParam::create(['main_meta_id' => $this->form->id, 'param_name' => 'extra', 'field_path' => 'extra', 'data_type' => 'string', 'order_number' => 99]);

        return $id;
    }

    public function test_a_version_is_saved_only_when_something_changed(): void
    {
        $this->postJson("/api/metas/{$this->form->id}/versions")->assertCreated();
        $this->postJson("/api/metas/{$this->form->id}/versions")->assertUnprocessable()->assertJsonPath('message', 'Nothing to save: it is the same as version 1.');
        $this->getJson("/api/metas/{$this->form->id}/versions")->assertJsonPath('changed', false);

        $this->param('title')->update(['label_name' => 'Subject']);
        $this->getJson("/api/metas/{$this->form->id}/versions")->assertJsonPath('changed', true)->assertJsonCount(1, 'versions');
    }

    public function test_the_diff_lists_what_changed_since_a_version(): void
    {
        $id = $this->versionThenChange();

        $diff = $this->getJson("/api/meta-versions/{$id}/diff")->assertOk()->json();
        $this->assertSame([['field' => 'name', 'from' => 'Purchase request form', 'to' => 'Renamed form']], $diff['meta']);
        $this->assertSame(['Field extra'], $diff['parts']['params']['added']);
        $this->assertSame(['Field notes'], $diff['parts']['params']['removed']);
        $this->assertSame('Field title', $diff['parts']['params']['changed'][0]['label']);
        $this->assertSame(['field' => 'label_name', 'from' => 'Title', 'to' => 'Subject'], $diff['parts']['params']['changed'][0]['fields'][0]);
    }

    public function test_restoring_puts_the_meta_back_and_keeps_what_it_replaced(): void
    {
        $notesId = $this->param('notes')->id;
        $id = $this->versionThenChange();

        $this->postJson("/api/meta-versions/{$id}/restore")->assertOk()->assertJsonPath('saved_first', 2)->assertJsonPath('warnings', []);

        $this->assertSame('Purchase request form', $this->form->fresh()->name);
        $this->assertSame('Title', $this->param('title')->label_name);
        $this->assertSame($notesId, $this->param('notes')?->id);
        $this->assertNull($this->param('extra'));
        $this->getJson("/api/metas/{$this->form->id}/versions")->assertJsonPath('changed', false)->assertJsonPath('current_version', 1);

        // Version 2 holds the changes it replaced, so they can come back too.
        $second = $this->getJson("/api/metas/{$this->form->id}/versions")->json('versions.0');
        $this->assertSame('Before restoring version 1', $second['note']);
        $this->postJson("/api/meta-versions/{$second['id']}/restore")->assertOk();
        $this->assertSame('Renamed form', $this->form->fresh()->name);
        $this->assertNotNull($this->param('extra'));
    }

    public function test_a_deleted_meta_can_be_restored_under_its_old_id(): void
    {
        $paramCount = MetaParam::where('main_meta_id', $this->form->id)->count();

        $this->deleteJson("/api/metas/{$this->form->id}")->assertNoContent();
        $deleted = $this->getJson('/api/meta-versions/deleted')->assertOk()->assertJsonPath('data.0.code', 'proc_request_form')->assertJsonPath('data.0.note', 'Before deleting')
            ->assertJsonPath('data.0.type_id', $this->form->type_id)->assertJsonPath('total', 1)->json('data.0');

        $this->postJson("/api/meta-versions/{$deleted['id']}/restore")->assertOk();

        $this->assertSame('proc_request_form', Meta::find($this->form->id)?->code);
        $this->assertSame($paramCount, MetaParam::where('main_meta_id', $this->form->id)->count());
        $this->getJson('/api/meta-versions/deleted')->assertJsonCount(0, 'data');
    }

    public function test_deleted_metas_come_20_at_a_time_and_filter(): void
    {
        $types = MetaType::pluck('id', 'code');
        foreach (range(1, 22) as $i) {
            $meta = Meta::create(['code' => "gone_{$i}", 'name' => "Gone {$i}", 'type_id' => $i <= 3 ? $types['datagrid'] : $types['form']]);
            $this->deleteJson("/api/metas/{$meta->id}")->assertNoContent();
        }

        $first = $this->getJson('/api/meta-versions/deleted')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 22)->assertJsonPath('has_more', true);
        $this->assertSame(3, $first->json('type_counts.'.$types['datagrid']));
        $this->getJson('/api/meta-versions/deleted?page=2')->assertJsonCount(2, 'data')->assertJsonPath('has_more', false);
        $this->getJson('/api/meta-versions/deleted?type_id='.$types['datagrid'])->assertJsonPath('total', 3);
        $this->getJson('/api/meta-versions/deleted?q=gone_17')->assertJsonPath('total', 1)->assertJsonPath('data.0.code', 'gone_17');
    }

    public function test_a_deleted_meta_can_be_deleted_permanently(): void
    {
        $this->deleteJson("/api/meta-versions/deleted/{$this->form->id}")->assertUnprocessable();

        $this->deleteJson("/api/metas/{$this->form->id}")->assertNoContent();
        $this->deleteJson("/api/meta-versions/deleted/{$this->form->id}")->assertOk();

        $this->assertSame(0, MetaVersion::where('meta_id', $this->form->id)->count());
        $this->getJson('/api/meta-versions/deleted')->assertJsonPath('total', 0);
        $this->deleteJson("/api/meta-versions/deleted/{$this->form->id}")->assertNotFound();
    }

    public function test_rows_pointing_at_something_gone_are_left_out_and_reported(): void
    {
        $role = Role::create(['code' => 'buyers', 'name' => 'Buyers']);
        MetaPermission::create(['meta_id' => $this->form->id, 'role_id' => $role->id, 'can_view' => true]);
        $id = $this->postJson("/api/metas/{$this->form->id}/versions")->json('id');
        $role->delete();

        $this->postJson("/api/meta-versions/{$id}/restore")
            ->assertOk()
            ->assertJsonPath('warnings.0', "Permissions of role #{$role->id} is left out: what it points at (role_id #{$role->id}) no longer exists.");
        $this->assertSame(0, MetaPermission::where('meta_id', $this->form->id)->count());
    }

    public function test_a_workflow_status_records_have_been_in_is_kept(): void
    {
        $workflow = Meta::whereHas('type', fn ($q) => $q->where('code', 'workflow'))->first();
        $id = $this->postJson("/api/metas/{$workflow->id}/versions")->json('id');
        $status = WfmStatus::create(['workflow_meta_id' => $workflow->id, 'code' => 'on_hold', 'name' => 'On hold']);
        DB::table('wfm_status_log')->insert(['workflow_meta_id' => $workflow->id, 'record_id' => 1, 'status_id' => $status->id, 'created_at' => now()]);

        $this->postJson("/api/meta-versions/{$id}/restore")->assertOk()->assertJsonPath('warnings', ['The status "On hold" stays: records have been in it.']);
        $this->assertNotNull($status->fresh());
    }

    public function test_only_admins_use_versions(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/metas/{$this->form->id}/versions")->assertForbidden();
        $this->postJson("/api/metas/{$this->form->id}/versions")->assertForbidden();
    }

    public function test_a_change_saved_over_another_persons_keeps_theirs_as_a_version(): void
    {
        $alice = User::factory()->admin()->create(['name' => 'Alice']);
        $bob = User::factory()->admin()->create(['name' => 'Bob']);

        Sanctum::actingAs($alice);
        $this->putJson("/api/metas/{$this->form->id}", ['name' => 'Alice’s name'])->assertOk();
        // Bob's screen still has the old name, and sends it back with his own change.
        Sanctum::actingAs($bob);
        $this->putJson("/api/metas/{$this->form->id}", ['name' => $this->form->name, 'description' => 'Bob’s description'])->assertOk();

        $versions = $this->getJson("/api/metas/{$this->form->id}/versions")->assertJsonPath('versions.0.note', 'Before a change by Bob')->json('versions');
        $this->assertTrue($versions[0]['is_auto']);
        $this->assertSame('Bob', $versions[0]['creator']['name']);

        $this->postJson("/api/meta-versions/{$versions[0]['id']}/restore")->assertOk();
        $this->assertSame('Alice’s name', $this->form->fresh()->name);
    }

    public function test_one_persons_changes_in_a_row_keep_one_version_every_ten_minutes(): void
    {
        $title = $this->param('title');
        $this->putJson("/api/params/{$title->id}", ['label_name' => 'Subject'])->assertOk();
        $this->putJson("/api/params/{$title->id}", ['label_name' => 'Topic'])->assertOk();
        $this->assertSame(1, MetaVersion::where('meta_id', $this->form->id)->count());

        $this->travel(MetaVersions::AUTO_EVERY + 1)->minutes();
        $this->putJson("/api/params/{$title->id}", ['label_name' => 'Heading'])->assertOk();

        $latest = MetaVersion::where('meta_id', $this->form->id)->orderByDesc('version_no')->first();
        $this->assertSame(2, $latest->version_no);
        $this->assertSame('Topic', collect($latest->snapshot['parts']['params'])->firstWhere('field_path', 'title')['label_name']);
    }

    public function test_a_refused_or_empty_change_keeps_no_version(): void
    {
        $this->putJson("/api/metas/{$this->form->id}", ['name' => $this->form->name])->assertOk();
        $this->putJson("/api/metas/{$this->form->id}", ['type_id' => 999999])->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create());
        $this->putJson("/api/metas/{$this->form->id}", ['name' => 'Not allowed'])->assertForbidden();

        $this->assertSame(0, MetaVersion::where('meta_id', $this->form->id)->count());
    }
}
