<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Items and services in one table (proc_items, shared by Procurement and the CRM), told apart
 * by their category. Categories become a table, proc_item_categories, whose flags decide how
 * its items behave (metas: 2026_12_03_000007_create_crm_item_category_metas):
 *
 *   is_service  work sold by time or visit, not goods: listed under Services
 *   is_asset    a fixed asset when bought: listed under Assets
 *   is_job      a job to carry out: a sales order with one makes a follow-up task for it
 *
 * default_unit is the unit a new item of the category starts with (hour for services).
 *
 * Data moves: each distinct proc_items.category text becomes a category (no flags), and the
 * items point at it. Services (crm_services) become items in a service category per their
 * old category, and lines and price list lines that named a service now name that item;
 * then the service_id columns and crm_services go. Three categories are added to start
 * with: Services, Jobs and Fixed assets.
 *
 * proc_items.category (text) stays, for Procurement's Excel import and older screens: on
 * MySQL triggers keep it equal to the category's name, and an item saved with only the text
 * gets the category of that name.
 */
return new class extends Migration
{
    /** Tables whose rows named an item or a service. */
    private const SERVICE_TABLES = ['crm_price_list_lines', 'crm_quotation_lines', 'crm_sales_order_lines', 'crm_invoice_lines'];

    public function up(): void
    {
        Schema::create('proc_item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name')->unique();
            $table->boolean('is_service')->default(false);
            $table->boolean('is_asset')->default(false);
            $table->boolean('is_job')->default(false);
            $table->string('default_unit', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('proc_items', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')->constrained('proc_item_categories')->nullOnDelete();
            $table->text('description')->nullable()->after('unit_price');
            $table->boolean('is_active')->default(true)->after('description');
        });

        DB::transaction(function () {
            $now = now();
            $category = function (string $code, string $name, array $flags = []) use ($now): int {
                $id = DB::table('proc_item_categories')->where('name', $name)->value('id');
                if ($id) {
                    return (int) $id;
                }

                return (int) DB::table('proc_item_categories')->insertGetId($flags + [
                    'code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now,
                ]);
            };

            // The categories items had as text.
            $n = 0;
            foreach (DB::table('proc_items')->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->pluck('category') as $name) {
                $id = $category(sprintf('CAT-%03d', ++$n), $name);
                DB::table('proc_items')->where('category', $name)->update(['category_id' => $id]);
            }

            $category('SERVICE', 'Services', ['is_service' => true, 'default_unit' => 'hour']);
            $category('JOB', 'Jobs', ['is_job' => true, 'is_service' => true, 'default_unit' => 'job']);
            $category('ASSET', 'Fixed assets', ['is_asset' => true, 'default_unit' => 'pcs']);

            // Services become items in a service category named after their old category.
            foreach (DB::table('crm_services')->orderBy('id')->get() as $service) {
                $categoryId = $service->category
                    ? $category('SRV-'.strtoupper(substr(preg_replace('/\W+/', '', $service->category), 0, 26)), ucfirst($service->category), ['is_service' => true, 'default_unit' => 'hour'])
                    : $category('SERVICE', 'Services');
                $code = DB::table('proc_items')->where('code', $service->code)->exists() ? 'SRV-'.$service->code : $service->code;
                $itemId = DB::table('proc_items')->insertGetId([
                    'code' => $code, 'name' => $service->name, 'category' => DB::table('proc_item_categories')->where('id', $categoryId)->value('name'),
                    'category_id' => $categoryId, 'unit' => $service->unit, 'unit_price' => $service->rate,
                    'description' => $service->description, 'is_active' => $service->is_active,
                    'created_at' => $service->created_at ?? $now, 'updated_at' => $now,
                ]);
                foreach (self::SERVICE_TABLES as $name) {
                    DB::table($name)->where('service_id', $service->id)->update(['item_id' => $itemId]);
                }
            }
        });

        foreach (self::SERVICE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('service_id');
            });
        }
        Schema::dropIfExists('crm_services');

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER proc_items_category_insert BEFORE INSERT ON proc_items FOR EACH ROW
                BEGIN
                  IF NEW.category_id IS NULL AND COALESCE(NEW.category, '') <> '' THEN
                    SET NEW.category_id = (SELECT c.id FROM proc_item_categories c WHERE c.name = NEW.category LIMIT 1);
                  END IF;
                  IF NEW.category_id IS NOT NULL THEN
                    SET NEW.category = (SELECT c.name FROM proc_item_categories c WHERE c.id = NEW.category_id);
                  END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER proc_items_category_update BEFORE UPDATE ON proc_items FOR EACH ROW
                BEGIN
                  IF NOT (NEW.category_id <=> OLD.category_id) THEN
                    SET NEW.category = (SELECT c.name FROM proc_item_categories c WHERE c.id = NEW.category_id);
                  ELSEIF NOT (NEW.category <=> OLD.category) THEN
                    SET NEW.category_id = (SELECT c.id FROM proc_item_categories c WHERE c.name = NEW.category LIMIT 1);
                  END IF;
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER proc_item_categories_rename AFTER UPDATE ON proc_item_categories FOR EACH ROW
                BEGIN
                  IF NOT (NEW.name <=> OLD.name) THEN
                    UPDATE proc_items SET category = NEW.name WHERE category_id = NEW.id;
                  END IF;
                END
                SQL);
        }
    }

    /** Brings back the (empty) services table and columns; services moved into items stay items. */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['proc_items_category_insert', 'proc_items_category_update', 'proc_item_categories_rename'] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        Schema::create('crm_services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit', 20)->default('hour');
            $table->decimal('rate', 14, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        foreach (self::SERVICE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('service_id')->nullable()->after('item_id')->constrained('crm_services')->restrictOnDelete();
            });
        }

        Schema::table('proc_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['description', 'is_active']);
        });
        Schema::dropIfExists('proc_item_categories');
    }
};
