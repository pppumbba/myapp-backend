<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of the warehouse management app (metas: 2026_12_03_000013_create_wh_metas).
 *
 * Warehouses hold locations (a tree: zone > aisle > rack > bin) and have store keepers.
 * Everything that moves stock is a document (wh_documents, doc_type receipt, purchase,
 * income, return_in, sales, outcome, return_out, dispose, movement, packaging, unpackaging)
 * with lines. Posting a document writes its lines into the stock ledger (wh_stock_moves,
 * signed quantities at average cost); triggers keep the balances (wh_stocks) and refuse
 * stock going below zero unless the warehouse allows it. A posted document is locked until
 * it is unposted. Orders (inbound, outbound, transfer) and requests (internal requisitions)
 * are fulfilled by documents; works are the jobs of store keepers (pick, put away, pack...).
 * Items are the shared catalogue (proc_items); pack specs say what a package holds.
 *
 * Triggers are MySQL/MariaDB only; the tables work anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wh_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('kind', 20)->default('main');
            $table->string('address')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('allow_negative')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wh_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('wh_locations')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('kind', 20)->default('bin');
            $table->decimal('capacity', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('wh_store_keepers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('keeper');
            $table->string('phone', 50)->nullable();
            $table->string('shift', 20)->nullable();
            $table->date('since')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'user_id']);
        });

        Schema::create('wh_pack_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_item_id')->constrained('proc_items')->cascadeOnDelete();
            $table->foreignId('component_item_id')->constrained('proc_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['package_item_id', 'component_item_id']);
        });

        Schema::create('wh_stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('proc_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->cascadeOnDelete();
            $table->decimal('min_qty', 14, 4)->default(0);
            $table->decimal('max_qty', 14, 4)->nullable();
            $table->decimal('reorder_qty', 14, 4)->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id']);
        });

        Schema::create('wh_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->string('kind', 20)->default('inbound');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('proc_suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });

        Schema::create('wh_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('wh_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wh_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();
            $table->date('request_date');
            $table->date('needed_by')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('department')->nullable();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->restrictOnDelete();
            $table->string('purpose')->nullable();
            $table->unsignedBigInteger('status_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wh_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('wh_requests')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('approved_qty', 14, 4)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wh_documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 30)->unique();
            $table->string('doc_type', 20);
            $table->date('doc_date');
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('wh_orders')->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('wh_requests')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('proc_purchase_orders')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('crm_sales_orders')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('proc_suppliers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('crm_customers')->nullOnDelete();
            $table->string('reason', 30)->nullable();
            $table->foreignId('package_item_id')->nullable()->constrained('proc_items')->restrictOnDelete();
            $table->decimal('package_qty', 14, 4)->nullable();
            $table->string('counterparty')->nullable();
            $table->foreignId('keeper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->boolean('post_on_save')->default(true);
            $table->boolean('create_work')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['doc_type', 'status']);
            $table->index('doc_date');
        });

        Schema::create('wh_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('wh_documents')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->string('direction', 4)->nullable();
            $table->foreignId('location_id')->nullable()->constrained('wh_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('wh_locations')->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained('wh_order_lines')->nullOnDelete();
            $table->foreignId('request_line_id')->nullable()->constrained('wh_request_lines')->nullOnDelete();
            $table->foreignId('po_line_id')->nullable()->constrained('proc_purchase_order_lines')->nullOnDelete();
            $table->foreignId('so_line_id')->nullable()->constrained('crm_sales_order_lines')->nullOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('lot_no', 50)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wh_stock_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('wh_documents')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('wh_document_lines')->restrictOnDelete();
            $table->string('doc_type', 20);
            $table->date('moved_on');
            $table->foreignId('item_id')->constrained('proc_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('wh_locations')->restrictOnDelete();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'moved_on']);
        });

        // location_id 0 = not in a location (so the unique key holds).
        Schema::create('wh_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('proc_items')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->cascadeOnDelete();
            $table->unsignedBigInteger('location_id')->default(0);
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->date('last_move_on')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'warehouse_id', 'location_id']);
        });

        Schema::create('wh_works', function (Blueprint $table) {
            $table->id();
            $table->string('work_no', 30)->unique();
            $table->string('kind', 20)->nullable();
            $table->string('title')->nullable();
            $table->foreignId('warehouse_id')->constrained('wh_warehouses')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('wh_locations')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('wh_documents')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('wh_orders')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->date('planned_on')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 20)->default('todo');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'assigned_to']);
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->triggers();
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach ($this->triggerNames() as $name) {
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
            }
        }
        foreach (['wh_works', 'wh_stocks', 'wh_stock_moves', 'wh_document_lines', 'wh_documents', 'wh_request_lines', 'wh_requests',
            'wh_order_lines', 'wh_orders', 'wh_stock_levels', 'wh_pack_specs', 'wh_store_keepers', 'wh_locations', 'wh_warehouses'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'wh_stock_moves_before_insert', 'wh_stock_moves_after_insert', 'wh_stock_moves_after_delete',
            'wh_stocks_before_insert', 'wh_stocks_before_update',
            'wh_document_lines_lock_insert', 'wh_document_lines_lock_update', 'wh_document_lines_lock_delete',
            'wh_documents_before_update', 'wh_documents_before_delete',
            'wh_works_before_insert', 'wh_works_before_update',
        ];
    }

    private function triggers(): void
    {
        foreach ($this->triggerNames() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }

        // The ledger: amount from quantity and cost; a location must be in the move's warehouse.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_stock_moves_before_insert BEFORE INSERT ON wh_stock_moves FOR EACH ROW
            BEGIN
              DECLARE msg VARCHAR(128);
              SET NEW.amount = ROUND(NEW.quantity * NEW.unit_cost, 2);
              IF NEW.location_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM wh_locations l WHERE l.id = NEW.location_id AND l.warehouse_id = NEW.warehouse_id) THEN
                SET msg = LEFT(CONCAT('Location ', (SELECT code FROM wh_locations WHERE id = NEW.location_id), ' is not in warehouse ', (SELECT code FROM wh_warehouses WHERE id = NEW.warehouse_id)), 128);
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_stock_moves_after_insert AFTER INSERT ON wh_stock_moves FOR EACH ROW
              INSERT INTO wh_stocks (item_id, warehouse_id, location_id, quantity, amount, last_move_on, created_at, updated_at)
              VALUES (NEW.item_id, NEW.warehouse_id, COALESCE(NEW.location_id, 0), NEW.quantity, NEW.amount, NEW.moved_on, NOW(), NOW())
              ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), amount = amount + VALUES(amount),
                last_move_on = GREATEST(COALESCE(last_move_on, VALUES(last_move_on)), VALUES(last_move_on)), updated_at = NOW()
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_stock_moves_after_delete AFTER DELETE ON wh_stock_moves FOR EACH ROW
              UPDATE wh_stocks SET quantity = quantity - OLD.quantity, amount = amount - OLD.amount, updated_at = NOW()
              WHERE item_id = OLD.item_id AND warehouse_id = OLD.warehouse_id AND location_id = COALESCE(OLD.location_id, 0)
            SQL);

        // Balances: never below zero unless the warehouse allows it; nothing left is worth nothing.
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER wh_stocks_before_{$suffix} BEFORE {$event} ON wh_stocks FOR EACH ROW
                BEGIN
                  DECLARE msg VARCHAR(128);
                  IF NEW.quantity = 0 THEN
                    SET NEW.amount = 0;
                  END IF;
                  IF NEW.quantity < 0 AND COALESCE((SELECT allow_negative FROM wh_warehouses WHERE id = NEW.warehouse_id), 0) = 0 THEN
                    SET msg = LEFT(CONCAT('Not enough ', (SELECT CONCAT(code, ' ', name) FROM proc_items WHERE id = NEW.item_id),
                      ' in ', (SELECT code FROM wh_warehouses WHERE id = NEW.warehouse_id),
                      COALESCE(CONCAT(' / ', (SELECT code FROM wh_locations WHERE id = NEW.location_id)), ''),
                      ': it would go to ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM CAST(NEW.quantity AS CHAR)))), 128);
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
                  END IF;
                END
                SQL);
        }

        // A posted document's lines are locked.
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'OLD'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER wh_document_lines_lock_{$suffix} BEFORE {$event} ON wh_document_lines FOR EACH ROW
                BEGIN
                  IF (SELECT status FROM wh_documents WHERE id = {$row}.document_id) = 'posted' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The document is posted: unpost it to change its lines.';
                  END IF;
                END
                SQL);
        }

        // Posting needs lines; a posted document keeps its type, warehouses and date.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_documents_before_update BEFORE UPDATE ON wh_documents FOR EACH ROW
            BEGIN
              IF NEW.status = 'posted' AND OLD.status <> 'posted' AND NOT EXISTS (SELECT 1 FROM wh_document_lines WHERE document_id = NEW.id) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The document has no lines to post.';
              END IF;
              IF OLD.status = 'posted' AND NEW.status = 'posted' AND (NEW.doc_type <> OLD.doc_type OR NEW.warehouse_id <> OLD.warehouse_id
                  OR NOT (NEW.to_warehouse_id <=> OLD.to_warehouse_id) OR NEW.doc_date <> OLD.doc_date) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The document is posted: unpost it to change its type, warehouses or date.';
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_documents_before_delete BEFORE DELETE ON wh_documents FOR EACH ROW
            BEGIN
              IF OLD.status = 'posted' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The document is posted: unpost it before deleting it.';
              END IF;
            END
            SQL);

        // A work of a document: its kind, title and keeper (the warehouse's head keeper) from the document.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_works_before_insert BEFORE INSERT ON wh_works FOR EACH ROW
            BEGIN
              IF NEW.document_id IS NOT NULL THEN
                IF NEW.kind IS NULL OR NEW.kind = '' THEN
                  SET NEW.kind = (SELECT CASE
                      WHEN doc_type IN ('receipt', 'purchase', 'income', 'return_in') THEN 'putaway'
                      WHEN doc_type IN ('sales', 'outcome', 'return_out') THEN 'pick'
                      WHEN doc_type = 'movement' THEN 'move'
                      WHEN doc_type IN ('packaging', 'unpackaging') THEN 'pack'
                      WHEN doc_type = 'dispose' THEN 'dispose'
                      ELSE 'other' END FROM wh_documents WHERE id = NEW.document_id);
                END IF;
                IF NEW.title IS NULL OR NEW.title = '' THEN
                  SET NEW.title = (SELECT CONCAT(UPPER(LEFT(NEW.kind, 1)), SUBSTRING(NEW.kind, 2), ' for ', doc_no) FROM wh_documents WHERE id = NEW.document_id);
                END IF;
                IF NEW.assigned_to IS NULL THEN
                  SET NEW.assigned_to = (SELECT keeper_id FROM wh_documents WHERE id = NEW.document_id);
                END IF;
              END IF;
              IF NEW.assigned_to IS NULL THEN
                SET NEW.assigned_to = (SELECT k.user_id FROM wh_store_keepers k WHERE k.warehouse_id = NEW.warehouse_id AND k.is_active = 1
                  ORDER BY k.role = 'head' DESC, k.id LIMIT 1);
              END IF;
              IF NEW.title IS NULL OR NEW.title = '' THEN
                SET NEW.title = COALESCE(NEW.kind, 'Work');
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_works_before_update BEFORE UPDATE ON wh_works FOR EACH ROW
            BEGIN
              IF NEW.status = 'doing' AND NEW.started_at IS NULL THEN
                SET NEW.started_at = NOW();
              END IF;
              IF NEW.status = 'done' AND NEW.finished_at IS NULL THEN
                SET NEW.finished_at = NOW();
                SET NEW.started_at = COALESCE(NEW.started_at, NOW());
              END IF;
            END
            SQL);
    }
};
