<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Meta;
use App\Models\User;
use App\Support\MetaVersions;
use App\Support\Xlsx;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\MetaTypeSeeder;
use Database\Seeders\ProcurementDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The language of each request, and translations of metas' names, labels, buttons and statuses. */
class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([MetaTypeSeeder::class, LanguageSeeder::class, ProcurementDemoSeeder::class]);
        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    private function meta(string $code): Meta
    {
        return Meta::where('code', $code)->firstOrFail();
    }

    /** The key of a meta's text whose "where" starts with this. */
    private function key(Meta $meta, string $where): string
    {
        $text = collect($this->getJson("/api/metas/{$meta->id}/translations")->json('texts'))->first(fn ($t) => str_starts_with($t['where'], $where));
        $this->assertNotNull($text, "No text \"{$where}\" in {$meta->code}.");

        return $text['key'];
    }

    private function translate(Meta $meta, array $values): void
    {
        $entries = [];
        foreach ($values as $where => $value) {
            $entries[] = ['key' => $this->key($meta, $where), 'locale' => 'mn', 'value' => $value];
        }
        $this->putJson("/api/metas/{$meta->id}/translations", ['entries' => $entries])->assertOk();
    }

    public function test_the_language_is_the_users_choice_else_the_browsers_else_the_default(): void
    {
        $this->putJson('/api/user', ['name' => '', 'email' => $this->admin->email], ['Accept-Language' => 'mn-MN,mn;q=0.9'])
            ->assertJsonValidationErrors(['name' => 'нэр талбарыг бөглөнө үү.'])
            ->assertHeader('Content-Language', 'mn');
        $this->putJson('/api/user', ['name' => ''], ['Accept-Language' => 'de-DE'])
            ->assertJsonValidationErrors(['name' => 'The name field is required.']);

        $this->putJson('/api/user/locale', ['locale' => 'mn'])->assertOk()->assertJsonPath('locale', 'mn');
        $this->getJson('/api/languages', ['Accept-Language' => 'en'])->assertJsonPath('current', 'mn');
        $this->putJson('/api/user/locale', ['locale' => 'xx'])->assertJsonValidationErrors('locale');
    }

    public function test_error_messages_come_in_the_users_language(): void
    {
        $grid = $this->meta('proc_orders_grid');
        Sanctum::actingAs(User::factory()->create(['locale' => 'mn']));

        $this->getJson("/api/metas/{$grid->id}/data")->assertForbidden()->assertJsonPath('message', 'Танд үүнийг нээх эрх алга.');
        $this->postJson('/api/languages', ['code' => 'ru', 'name' => 'Русский'])->assertJsonPath('message', 'Үүнийг зөвхөн админ хийж чадна.');

        Sanctum::actingAs(User::factory()->admin()->create(['locale' => 'mn']));
        $this->putJson("/api/metas/{$grid->id}/translations", ['entries' => [['key' => 'Nope', 'locale' => 'mn', 'value' => 'x']]])
            ->assertStatus(422)->assertJsonPath('message', "\"Nope\" нь {$grid->name}-ийн текст биш.");

        Sanctum::actingAs(User::factory()->create(['locale' => 'en']));
        $this->getJson("/api/metas/{$grid->id}/data")->assertJsonPath('message', "You don't have permission to open this.");
    }

    public function test_screens_get_their_texts_translated_and_the_builder_the_originals(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $param = DB::table('meta_params')->where('main_meta_id', $grid->id)->where('label_name', '!=', '')->orderBy('id')->first();
        $action = DB::table('meta_actions')->where('grid_meta_id', $grid->id)->where('label', '!=', '')->orderBy('id')->first();
        $this->translate($grid, ['Name' => 'Худалдан авалтын захиалга', "Field {$param->field_path}" => 'Орчуулсан талбар', "Button {$action->action}" => 'Товч']);
        $workflow = $this->meta('proc_order_workflow');
        $status = DB::table('wfm_status')->where('workflow_meta_id', $workflow->id)->orderBy('id')->first();
        $this->translate($workflow, ["Status {$status->code}" => 'Ноорог']);
        $this->putJson('/api/user/locale', ['locale' => 'mn']);

        $this->getJson("/api/metas/{$grid->id}?translated=1")->assertJsonPath('name', 'Худалдан авалтын захиалга');
        $this->assertSame('Орчуулсан талбар', collect($this->getJson("/api/metas/{$grid->id}/params?effective=1&translated=1")->json())->firstWhere('id', $param->id)['label_name']);
        $this->assertSame('Товч', collect($this->getJson("/api/metas/{$grid->id}/actions?effective=1&translated=1")->json())->firstWhere('id', $action->id)['label']);
        $this->assertSame('Ноорог', collect($this->getJson("/api/workflows/{$workflow->id}?translated=1")->json('statuses'))->firstWhere('id', $status->id)['name']);

        // The builder edits the originals; English speakers see them too.
        $this->getJson("/api/metas/{$grid->id}")->assertJsonPath('name', $grid->name);
        $this->assertSame($param->label_name, collect($this->getJson("/api/metas/{$grid->id}/params")->json())->firstWhere('id', $param->id)['label_name']);
        $this->putJson('/api/user/locale', ['locale' => 'en']);
        $this->getJson("/api/metas/{$grid->id}?translated=1")->assertJsonPath('name', $grid->name);
    }

    public function test_bad_translations_and_non_admins_are_refused(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $other = $this->meta('proc_items_grid');
        $name = $this->key($grid, 'Name');

        $this->putJson("/api/metas/{$other->id}/translations", ['entries' => [['key' => $name, 'locale' => 'mn', 'value' => 'x']]])->assertStatus(422);
        $this->putJson("/api/metas/{$grid->id}/translations", ['entries' => [['key' => $name, 'locale' => 'en', 'value' => 'x']]])->assertStatus(422);

        // An empty value removes the translation.
        $this->translate($grid, ['Name' => 'Захиалга']);
        $this->putJson("/api/metas/{$grid->id}/translations", ['entries' => [['key' => $name, 'locale' => 'mn', 'value' => '']]])->assertJsonPath('removed', 1);
        $this->assertSame(0, DB::table('translations')->count());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/metas/{$grid->id}/translations")->assertForbidden();
        $this->postJson('/api/languages', ['code' => 'ru', 'name' => 'Русский'])->assertForbidden();
    }

    public function test_translations_are_kept_in_versions_and_follow_copies(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $param = DB::table('meta_params')->where('main_meta_id', $grid->id)->orderBy('id')->first();
        $this->translate($grid, ['Name' => 'Захиалга', "Field {$param->field_path}" => 'Эхний']);
        $version = MetaVersions::save($grid->id, $this->admin, 'With the first label');
        $this->translate($grid, ["Field {$param->field_path}" => 'Хоёр дахь']);
        $this->putJson('/api/user/locale', ['locale' => 'mn']);
        $label = fn (int $metaId, int $paramId) => collect($this->getJson("/api/metas/{$metaId}/params?translated=1")->json())->firstWhere('id', $paramId)['label_name'];
        $this->assertSame('Хоёр дахь', $label($grid->id, $param->id));

        $this->postJson("/api/meta-versions/{$version->id}/restore")->assertOk();
        $this->assertSame('Эхний', $label($grid->id, $param->id));

        $copy = $this->postJson("/api/metas/{$grid->id}/copy")->assertSuccessful()->json('id');
        $copied = DB::table('meta_params')->where('main_meta_id', $copy)->where('field_path', $param->field_path)->value('id');
        $this->assertSame('Эхний', $label($copy, $copied));
        $this->assertFalse(DB::table('translations')->where('meta_id', $copy)->where('table_name', 'metas')->where('field', 'name')->exists());
    }

    public function test_translations_go_out_to_excel_and_come_back(): void
    {
        $grid = $this->meta('proc_orders_grid');
        $this->translate($grid, ['Name' => 'Хуучин']);
        $this->get("/api/metas/{$grid->id}/translations/export")->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'tr').'.xlsx';
        file_put_contents($path, Xlsx::write('Translations', ['Key', 'Where', 'English (en)', 'Монгол (mn)'], [
            [$this->key($grid, 'Name'), 'Name', $grid->name, 'Захиалгууд'],
            ['meta_params:999999:label_name', 'Gone', 'x', 'y'],
        ]));

        $this->post("/api/metas/{$grid->id}/translations/import", ['file' => new UploadedFile($path, 'tr.xlsx', null, null, true)])
            ->assertOk()->assertJsonPath('saved', 1)->assertJsonPath('skipped', 1);
        $this->putJson('/api/user/locale', ['locale' => 'mn']);
        $this->getJson("/api/metas/{$grid->id}?translated=1")->assertJsonPath('name', 'Захиалгууд');
    }

    public function test_admins_manage_languages(): void
    {
        $ru = $this->postJson('/api/languages', ['code' => 'ru', 'name' => 'Русский'])->assertCreated()->json('id');
        $this->postJson('/api/languages', ['code' => 'Russian', 'name' => 'x'])->assertJsonValidationErrors('code');

        $this->putJson("/api/languages/{$ru}", ['is_default' => true])->assertOk();
        $this->assertSame(['ru'], Language::where('is_default', true)->pluck('code')->all());
        $this->deleteJson("/api/languages/{$ru}")->assertStatus(422);
        $this->putJson("/api/languages/{$ru}", ['is_active' => false])->assertStatus(422);

        $mn = Language::where('code', 'mn')->value('id');
        $this->translate($this->meta('proc_orders_grid'), ['Name' => 'Захиалга']);
        $this->putJson('/api/user/locale', ['locale' => 'mn']);
        $this->deleteJson("/api/languages/{$mn}")->assertNoContent();
        $this->assertSame(0, DB::table('translations')->count());
        $this->assertNull($this->admin->fresh()->locale);
    }
}
