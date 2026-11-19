<?php

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use Database\Seeders\ConsolidateExampleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Service billing example app: service jobs merged into invoices by a consolidate button.
 * The tables, then their metas and demo jobs (ConsolidateExampleSeeder).
 */
return new class extends Migration
{
    private const CODES = ['cx_app', 'cx_jobs_grid', 'cx_job_form', 'cx_job_as_invoice', 'cx_invoices_grid', 'cx_invoice_form', 'cx_invoice_line_form'];

    public function up(): void
    {
        if (! Schema::hasTable('cx_service_jobs')) {
            Schema::create('cx_service_jobs', function (Blueprint $t) {
                $t->id();
                $t->string('job_no', 30)->unique();
                $t->date('job_date');
                $t->string('customer', 120);
                $t->string('technician', 120);
                $t->string('item', 120);
                $t->string('description', 255)->nullable();
                $t->decimal('qty', 12, 2)->default(1);
                $t->decimal('price', 14, 2)->default(0);
                $t->string('status', 20)->default('done');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cx_invoices')) {
            Schema::create('cx_invoices', function (Blueprint $t) {
                $t->id();
                $t->string('invoice_no', 30)->unique();
                $t->date('invoice_date');
                $t->string('customer', 120);
                $t->string('technicians', 255)->nullable();
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->integer('jobs')->nullable();
                $t->text('job_numbers')->nullable();
                $t->decimal('total', 14, 2)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cx_invoice_lines')) {
            Schema::create('cx_invoice_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('invoice_id')->constrained('cx_invoices')->cascadeOnDelete();
                $t->string('item', 120);
                $t->text('description')->nullable();
                $t->decimal('qty', 12, 2)->default(1);
                $t->decimal('price', 14, 2)->default(0);
                $t->decimal('amount', 14, 2)->nullable();
                $t->timestamps();
            });
        }

        (new ConsolidateExampleSeeder)->run();
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = Meta::whereIn('code', self::CODES)->pluck('id');
            DB::table('meta_permissions')->whereIn('meta_id', $ids)->delete();
            MetaAction::whereIn('grid_meta_id', $ids)->delete();
            MetaParam::whereIn('main_meta_id', $ids)->delete();
            Meta::whereIn('id', $ids)->delete();
            DB::table('autonumbers')->whereIn('table_name', ['cx_service_jobs', 'cx_invoices'])->delete();
        });
        Schema::dropIfExists('cx_invoice_lines');
        Schema::dropIfExists('cx_invoices');
        Schema::dropIfExists('cx_service_jobs');
    }
};
