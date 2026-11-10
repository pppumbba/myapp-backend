<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use Database\Seeders\EamAuditSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset audits in the Assets app: the tables, then their metas and menu entry
 * (EamAuditSeeder) where the Assets app already exists. On a fresh database EamDemoSeeder
 * makes the app and calls EamAuditSeeder itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eam_audits')) {
            Schema::create('eam_audits', function (Blueprint $t) {
                $t->id();
                $t->string('audit_no', 30)->unique();
                $t->date('audit_date');
                $t->unsignedBigInteger('location_id')->nullable()->index();
                $t->string('auditor', 120)->nullable();
                $t->string('status', 20)->default('open');
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('eam_audit_lines')) {
            Schema::create('eam_audit_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('audit_id')->constrained('eam_audits')->cascadeOnDelete();
                $t->unsignedBigInteger('asset_id')->index();
                $t->boolean('found')->default(true);
                $t->string('condition', 20)->nullable();
                $t->string('note', 255)->nullable();
                $t->timestamps();
            });
        }

        (new EamAuditSeeder)->run();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = Meta::whereIn('code', ['eam_audits_grid', 'eam_audit_form', 'eam_audit_line_form'])->pluck('id');
            MetaParam::where('field_path', 'asset_audits')->whereIn('ref_meta_id', $ids)->delete();
            DB::table('meta_permissions')->whereIn('meta_id', $ids)->delete();
            MetaAction::whereIn('grid_meta_id', $ids)->delete();
            MetaParam::whereIn('main_meta_id', $ids)->delete();
            Meta::whereIn('id', $ids)->delete();
            DB::table('autonumbers')->where('table_name', 'eam_audits')->delete();
        });
        Schema::dropIfExists('eam_audit_lines');
        Schema::dropIfExists('eam_audits');
    }
};
