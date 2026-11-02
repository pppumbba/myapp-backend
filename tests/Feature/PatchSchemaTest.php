<?php

namespace Tests\Feature;

use App\Models\Meta;
use App\Models\MetaPermission;
use App\Models\MetaType;
use App\Models\Patch;
use App\Models\Role;
use App\Models\User;
use App\Support\Patches;
use App\Support\PatchSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A patch that brings its database. DDL and triggers need the real thing, and they commit, so
 * this needs its own MySQL/MariaDB database, migrated beforehand and named *_patchtest:
 *
 *   DB_CONNECTION=mysql DB_DATABASE=laravel_patchtest php artisan test tests/Feature/PatchSchemaTest.php
 *
 * It's skipped anywhere else, so it can never touch a working database.
 */
class PatchSchemaTest extends TestCase
{
    private Meta $grid;

    protected function setUp(): void
    {
        parent::setUp();
        if (! PatchSchema::supported() || ! str_ends_with((string) DB::getDatabaseName(), '_patchtest')) {
            $this->markTestSkipped('Needs a MySQL or MariaDB database named *_patchtest.');
        }
        $this->clean();
        Sanctum::actingAs(User::factory()->admin()->create());

        DB::statement('CREATE TABLE ps_kinds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL DEFAULT \'plain\')');
        DB::statement('CREATE TABLE ps_things (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kind_id BIGINT UNSIGNED NULL, name VARCHAR(50), price DECIMAL(10,2) NOT NULL DEFAULT 0, doubled DECIMAL(10,2) AS (price * 2) STORED, KEY ps_things_name (name), CONSTRAINT ps_things_kind FOREIGN KEY (kind_id) REFERENCES ps_kinds (id))');
        DB::statement('CREATE TABLE ps_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, what VARCHAR(50))');
        DB::unprepared('CREATE TRIGGER ps_things_logged AFTER INSERT ON ps_things FOR EACH ROW BEGIN INSERT INTO ps_log (what) VALUES (NEW.name); END');
        DB::table('ps_kinds')->insert(['id' => 7, 'name' => 'Lookup row']);

        $type = MetaType::firstOrCreate(['code' => 'datagrid'], ['name' => 'Data grid']);
        $this->grid = Meta::create(['code' => 'ps_grid', 'name' => 'Things', 'type_id' => $type->id, 'table_name' => 'ps_things', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        if (PatchSchema::supported() && str_ends_with((string) DB::getDatabaseName(), '_patchtest')) {
            $this->clean();
        }
        parent::tearDown();
    }

    private function clean(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ps_things_logged');
        foreach (['ps_log', 'ps_things', 'ps_kinds'] as $table) {
            Schema::dropIfExists($table);
        }
        Meta::where('code', 'ps_grid')->delete();
        Role::where('code', 'ps_role')->delete();
        Patch::query()->delete();
        DB::table('patch_installs')->delete();
        DB::table('migrations')->where('migration', 'like', '%ps_fake%')->delete();
    }

    /** The patch's file, with this database then losing what the file should bring back. */
    private function releaseAndDrop(array $dataTables = ['ps_kinds']): array
    {
        $patch = $this->postJson('/api/patches', ['code' => 'ps', 'name' => 'Things', 'meta_ids' => [$this->grid->id], 'data_tables' => $dataTables])->assertCreated()->json();
        $this->postJson("/api/patches/{$patch['id']}/release")->assertOk();
        $file = json_decode($this->get("/api/patches/{$patch['id']}/download")->streamedContent(), true);

        DB::unprepared('DROP TRIGGER ps_things_logged');
        Schema::drop('ps_things');
        Schema::drop('ps_log');
        Schema::drop('ps_kinds');

        return $file;
    }

    private function install(array $file, bool $dryRun = false): array
    {
        return $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace', 'dry_run' => $dryRun])->assertSuccessful()->json();
    }

    public function test_the_tables_a_meta_needs_are_listed_with_what_they_depend_on(): void
    {
        $tables = $this->postJson('/api/patches/tables', ['meta_ids' => [$this->grid->id]])->assertOk()->json();

        // ps_kinds through the foreign key, ps_log through the trigger.
        $this->assertSame(['ps_kinds', 'ps_log', 'ps_things'], array_column($tables, 'table'));
        $this->assertSame(1, $tables[2]['triggers']);
        $this->assertTrue($tables[0]['can_ship_rows']);
    }

    public function test_a_dry_run_changes_nothing_and_says_what_it_would_do(): void
    {
        $file = $this->releaseAndDrop();

        $tried = $this->install($file, true);

        $this->assertSame(['ps_kinds', 'ps_log', 'ps_things'], $tried['schema']['tables']);
        $this->assertSame(['ps_things_logged'], $tried['schema']['triggers']);
        $this->assertSame(['ps_kinds' => 1], $tried['schema']['rows']);
        $this->assertSame([], $tried['warnings']);
        $this->assertFalse(Schema::hasTable('ps_things'));
    }

    public function test_installing_brings_tables_rows_and_triggers_that_work(): void
    {
        $file = $this->releaseAndDrop();

        $this->install($file);

        $this->assertSame('Lookup row', DB::table('ps_kinds')->where('id', 7)->value('name'));
        DB::table('ps_things')->insert(['kind_id' => 7, 'name' => 'Lamp', 'price' => 5]);
        $this->assertEquals(10, DB::table('ps_things')->value('doubled'));
        $this->assertSame('Lamp', DB::table('ps_log')->value('what'));
        $this->assertTrue(collect(Schema::getIndexes('ps_things'))->contains('name', 'ps_things_name'));
        $this->assertSame('ps_things_kind', Schema::getForeignKeys('ps_things')[0]['name']);
    }

    public function test_installing_again_changes_nothing_and_keeps_records(): void
    {
        $file = $this->releaseAndDrop();
        $this->install($file);
        DB::table('ps_kinds')->insert(['name' => 'Added there']);

        $again = $this->install($file);

        $this->assertSame([], $again['schema']['tables']);
        $this->assertSame(['ps_kinds'], $again['schema']['rows_kept']);
        $this->assertSame(2, DB::table('ps_kinds')->count());
    }

    public function test_a_table_already_here_gets_only_what_it_lacks(): void
    {
        $file = $this->releaseAndDrop();
        DB::statement('CREATE TABLE ps_kinds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY)');
        DB::table('ps_kinds')->insert(['id' => 1]);

        $result = $this->install($file);

        $this->assertSame(['ps_kinds.name'], $result['schema']['columns']);
        $this->assertTrue(Schema::hasColumn('ps_kinds', 'name'));
        $this->assertSame(1, DB::table('ps_kinds')->count());
    }

    public function test_a_changed_trigger_is_replaced_and_rollback_puts_it_back(): void
    {
        $patch = $this->postJson('/api/patches', ['code' => 'ps', 'name' => 'Things', 'meta_ids' => [$this->grid->id]])->assertCreated()->json();
        $this->postJson("/api/patches/{$patch['id']}/release")->assertOk();
        $file = json_decode($this->get("/api/patches/{$patch['id']}/download")->streamedContent(), true);
        DB::unprepared('DROP TRIGGER ps_things_logged');
        DB::unprepared("CREATE TRIGGER ps_things_logged AFTER INSERT ON ps_things FOR EACH ROW BEGIN INSERT INTO ps_log (what) VALUES ('old'); END");

        $result = $this->install($file);
        $this->assertSame(['ps_things_logged'], $result['schema']['replaced_triggers']);
        DB::table('ps_things')->insert(['name' => 'New']);
        $this->assertSame('New', DB::table('ps_log')->value('what'));

        $this->postJson("/api/patch-installs/{$result['install']['id']}/rollback")->assertOk();
        DB::table('ps_things')->insert(['name' => 'Again']);
        $this->assertSame('old', DB::table('ps_log')->orderByDesc('id')->value('what'));
    }

    public function test_rolling_back_drops_what_it_made_unless_records_were_added(): void
    {
        $file = $this->releaseAndDrop();
        $installed = $this->install($file);
        DB::table('ps_log')->insert(['what' => 'Kept']);

        $warnings = $this->postJson("/api/patch-installs/{$installed['install']['id']}/rollback")->assertOk()->json('warnings');

        $this->assertFalse(Schema::hasTable('ps_things'));
        $this->assertFalse(Schema::hasTable('ps_kinds'));
        $this->assertTrue(Schema::hasTable('ps_log'));
        $this->assertContains('The table ps_log stays: records were added to it after the install.', $warnings);
    }

    public function test_a_migration_that_made_the_tables_is_marked_so_a_deploy_skips_it(): void
    {
        $file = $this->releaseAndDrop();
        $file['schema']['migrations'] = ['2026_01_01_ps_fake' => ['ps_things', 'ps_kinds']];

        $installed = $this->install($file);

        $this->assertTrue(DB::table('migrations')->where('migration', '2026_01_01_ps_fake')->exists());

        $this->postJson("/api/patch-installs/{$installed['install']['id']}/rollback")->assertOk();
        $this->assertFalse(DB::table('migrations')->where('migration', '2026_01_01_ps_fake')->exists());
    }

    public function test_a_failed_trigger_stops_the_install_before_the_metas(): void
    {
        $file = $this->releaseAndDrop();
        Meta::where('code', 'ps_grid')->delete();
        $file['schema']['triggers'][0]['create'] = 'CREATE TRIGGER ps_things_logged nonsense';

        $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace'])
            ->assertStatus(422)->assertSee('The trigger ps_things_logged could not be created');

        $this->assertFalse(Meta::where('code', 'ps_grid')->exists());
        $this->assertTrue(Schema::hasTable('ps_things'));
    }

    public function test_a_role_the_metas_name_comes_along_with_its_access(): void
    {
        $role = Role::create(['code' => 'ps_role', 'name' => 'Things role']);
        MetaPermission::create(['meta_id' => $this->grid->id, 'role_id' => $role->id, 'can_view' => true]);
        $file = $this->releaseAndDrop();
        $role->delete();

        $tried = $this->install($file, true);
        $this->assertSame(['ps_role'], $tried['schema']['roles']);
        $this->assertSame([], $tried['warnings']);

        $installed = $this->install($file);

        $here = Role::where('code', 'ps_role')->firstOrFail();
        $this->assertSame('Things role', $here->name);
        $this->assertTrue(MetaPermission::where('meta_id', $installed['metas'][0]['id'])->where('role_id', $here->id)->where('can_view', true)->exists());
    }

    public function test_a_column_added_where_metas_are_kept_comes_along(): void
    {
        $file = $this->releaseAndDrop();
        $this->assertArrayHasKey('meta_params', $file['schema']['tables']);
        Schema::table('meta_params', fn ($table) => $table->dropColumn('widget_position'));

        $this->assertContains('meta_params.widget_position', $this->install($file)['schema']['columns']);
        $this->assertTrue(Schema::hasColumn('meta_params', 'widget_position'));
    }

    public function test_private_tables_never_ship_rows(): void
    {
        $package = ['metas' => [['meta' => ['table_name' => 'users']]]];

        $this->assertArrayNotHasKey('rows', PatchSchema::capture($package, ['users'])['tables']['users']);
    }

    public function test_a_damaged_file_is_refused(): void
    {
        $file = $this->releaseAndDrop();
        $file['schema']['tables']['ps_things']['create'] = 'DROP TABLE users';

        $this->postJson('/api/patch-installs', ['package' => $file, 'existing' => 'replace', 'dry_run' => true])->assertStatus(422);
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_the_hash_covers_the_database(): void
    {
        $file = $this->releaseAndDrop();
        $changed = $file;
        $changed['schema']['triggers'] = [];

        $this->assertNotSame(Patches::hash($file), Patches::hash($changed));
    }
}
