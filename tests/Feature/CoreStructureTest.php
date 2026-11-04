<?php

namespace Tests\Feature;

use App\Support\CoreStructure;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoreStructureTest extends TestCase
{
    public function test_core_tables_leave_out_app_and_main_tables(): void
    {
        $this->assertTrue(CoreStructure::isCoreTable('metas'));
        $this->assertTrue(CoreStructure::isCoreTable('meta_params'));
        $this->assertTrue(CoreStructure::isCoreTable('roles'));
        foreach (['eam_assets', 'market_bids', 'users', 'personal_access_tokens', 'tenders', 'xl_asset_adjustment', 'company_members', 'contract_steps', 'customers'] as $table) {
            $this->assertFalse(CoreStructure::isCoreTable($table), $table);
        }
    }

    public function test_the_dump_has_no_app_tables(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! str_ends_with((string) DB::getDatabaseName(), '_customertest')) {
            $this->markTestSkipped('Needs a MySQL or MariaDB database named *_customertest.');
        }

        $sql = CoreStructure::dump();

        $this->assertStringContainsString('CREATE TABLE `metas`', $sql);
        $this->assertStringContainsString('INSERT INTO `meta_types`', $sql);
        $this->assertStringContainsString("INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_04_000002_drop_foreign_keys_to_users', 1)", $sql);
        $this->assertStringNotContainsString('CREATE TABLE `eam_', $sql);
        $this->assertStringNotContainsString('CREATE TABLE `users`', $sql);
        $this->assertDoesNotMatchRegularExpression('/AUTO_INCREMENT=\d/', $sql);
        $this->assertStringNotContainsString('DEFINER', $sql);
        $this->assertStringNotContainsString('REFERENCES `users`', $sql);
        // A widget made from a template meta is that customer's own, not built in.
        $this->assertSame(DB::table('widgets')->whereNull('template_meta_id')->count(), substr_count($sql, 'INSERT INTO `widgets`'));
    }
}
