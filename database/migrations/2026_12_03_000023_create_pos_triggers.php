<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The rules of the point of sale app (tables: 2026_12_03_000022), in triggers so that no way
 * of saving can skip them: forms, stage queries, imports and the API all pass through here.
 *
 * - A sale's and a return's lines work out their own amounts from the catalogue (name, list
 *   price and tax rate are copied at the time, so old receipts keep what they said) and keep
 *   the totals on their header. Amounts include tax.
 * - A sale completes only from held, on an open shift, fully paid, with change no more than the
 *   cash given, and with no line discounted past its register's limit (a lowered price counts).
 *   Completing posts a wh_documents row of type sales with its lines into wh_stock_moves at the
 *   warehouse's average cost, the same ledger the warehouse app writes, so its triggers keep
 *   wh_stocks and refuse a sale of more than there is (unless the warehouse allows negative).
 *   Services (category is_service) move no stock. A finished sale is locked.
 * - Voiding a completed sale posts a return_in document at the original cost, only while its
 *   shift is open and it has no returns; after that, a return is the way.
 * - A return completes only against a completed sale, on an open shift, with no more of an item
 *   than is left of what was sold. It refunds what the sale charged for those units, and goods
 *   marked restock come back at what they cost.
 * - A shift takes one cashier on one register. Closing it works out the expected cash (float +
 *   cash taken - change given - cash refunded) and the difference from the counted cash.
 *
 * Messages are written for the cashier; they reach the screen through the stage queries that
 * complete things. Direct form saves only say the database refused, so the forms check first.
 *
 * MySQL/MariaDB only; SQLite tests keep the tables without the rules.
 */
return new class extends Migration
{
    /** @return list<string> */
    private function names(): array
    {
        return [
            'pos_shifts_before_insert', 'pos_shifts_before_update', 'pos_shifts_before_delete',
            'pos_sales_before_insert', 'pos_sales_before_update', 'pos_sales_before_void', 'pos_sales_before_delete',
            'pos_sale_lines_before_insert', 'pos_sale_lines_before_update', 'pos_sale_lines_before_delete',
            'pos_sale_lines_after_insert', 'pos_sale_lines_after_update', 'pos_sale_lines_after_delete',
            'pos_sale_payments_before_insert', 'pos_sale_payments_before_update', 'pos_sale_payments_before_delete',
            'pos_sale_payments_after_insert', 'pos_sale_payments_after_update', 'pos_sale_payments_after_delete',
            'pos_returns_before_insert', 'pos_returns_before_update', 'pos_returns_before_delete',
            'pos_return_lines_before_insert', 'pos_return_lines_before_update', 'pos_return_lines_before_delete',
            'pos_return_lines_after_insert', 'pos_return_lines_after_update', 'pos_return_lines_after_delete',
        ];
    }

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->names() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
        $this->shifts();
        $this->sales();
        $this->saleLines();
        $this->payments();
        $this->returns();
        $this->returnLines();
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->names() as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
        }
    }

    private function shifts(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_shifts_before_insert BEFORE INSERT ON pos_shifts FOR EACH ROW
            BEGIN
              SET NEW.status = 'open', NEW.opened_at = COALESCE(NEW.opened_at, NOW()), NEW.opening_float = COALESCE(NEW.opening_float, 0),
                  NEW.closed_at = NULL, NEW.counted_cash = NULL, NEW.expected_cash = NULL, NEW.cash_difference = NULL;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_shifts_before_update BEFORE UPDATE ON pos_shifts FOR EACH ROW
            BEGIN
              DECLARE v_cash DECIMAL(14,2) DEFAULT 0;
              DECLARE v_change DECIMAL(14,2) DEFAULT 0;
              DECLARE v_sales DECIMAL(14,2) DEFAULT 0;
              DECLARE v_refunds DECIMAL(14,2) DEFAULT 0;
              IF OLD.status = 'closed' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This shift is closed and cannot be changed.';
              END IF;
              SET NEW.opening_float = OLD.opening_float, NEW.register_id = OLD.register_id, NEW.opened_at = OLD.opened_at;
              IF OLD.cashier_id IS NOT NULL THEN
                SET NEW.cashier_id = OLD.cashier_id;
              ELSEIF NEW.cashier_id IS NOT NULL THEN
                IF EXISTS (SELECT 1 FROM pos_shifts s WHERE s.id != OLD.id AND s.register_id = OLD.register_id AND s.status = 'open' AND s.cashier_id IS NOT NULL) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This register already has an open shift.';
                END IF;
                IF EXISTS (SELECT 1 FROM pos_shifts s WHERE s.id != OLD.id AND s.cashier_id = NEW.cashier_id AND s.status = 'open') THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You already have an open shift: close it first.';
                END IF;
              END IF;
              IF NEW.status = 'closed' THEN
                IF NEW.counted_cash IS NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Count the cash before closing the shift.';
                END IF;
                SELECT COALESCE(SUM(p.amount), 0) INTO v_cash
                  FROM pos_sale_payments p JOIN pos_sales s ON s.id = p.sale_id JOIN pos_payment_methods m ON m.id = p.method_id
                  WHERE s.shift_id = OLD.id AND s.status = 'completed' AND m.is_cash = 1;
                SELECT COALESCE(SUM(s.change_due), 0), COALESCE(SUM(s.total_amount), 0) INTO v_change, v_sales
                  FROM pos_sales s WHERE s.shift_id = OLD.id AND s.status = 'completed';
                SELECT COALESCE(SUM(r.total_amount), 0) INTO v_refunds
                  FROM pos_returns r JOIN pos_payment_methods m ON m.id = r.refund_method_id
                  WHERE r.shift_id = OLD.id AND r.status = 'completed' AND m.is_cash = 1;
                SET NEW.closed_at = COALESCE(NEW.closed_at, NOW()), NEW.sales_total = v_sales, NEW.cash_sales = v_cash - v_change, NEW.cash_refunds = v_refunds,
                    NEW.expected_cash = OLD.opening_float + v_cash - v_change - v_refunds;
                SET NEW.cash_difference = NEW.counted_cash - NEW.expected_cash;
              ELSE
                SET NEW.status = 'open', NEW.counted_cash = NULL;
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_shifts_before_delete BEFORE DELETE ON pos_shifts FOR EACH ROW
            BEGIN
              IF OLD.status = 'closed' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A closed shift cannot be deleted.';
              END IF;
            END
            SQL);
    }

    private function sales(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_sales_before_insert BEFORE INSERT ON pos_sales FOR EACH ROW
            BEGIN
              SET NEW.status = 'held', NEW.subtotal = 0, NEW.discount_total = 0, NEW.tax_total = 0, NEW.total_amount = 0, NEW.paid_amount = 0, NEW.change_due = 0;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_sales_before_update BEFORE UPDATE ON pos_sales FOR EACH ROW
            BEGIN
              DECLARE v_register BIGINT UNSIGNED;
              DECLARE v_warehouse BIGINT UNSIGNED;
              DECLARE v_location BIGINT UNSIGNED;
              DECLARE v_doc BIGINT UNSIGNED;
              DECLARE v_max DECIMAL(5,2);
              DECLARE v_header VARCHAR(255);
              DECLARE v_footer VARCHAR(255);
              DECLARE v_cash DECIMAL(14,2) DEFAULT 0;
              DECLARE v_lines INT DEFAULT 0;
              DECLARE v_msg VARCHAR(128);
              IF OLD.status = 'held' AND NEW.status = 'completed' THEN
                IF NEW.shift_id IS NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Open a shift before selling.';
                END IF;
                IF COALESCE((SELECT s.status FROM pos_shifts s WHERE s.id = NEW.shift_id), '') != 'open' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That shift is closed: open a new one.';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pos_sale_lines l WHERE l.sale_id = NEW.id) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The sale has no lines.';
                END IF;
                IF EXISTS (SELECT 1 FROM pos_sale_lines l WHERE l.sale_id = NEW.id AND l.quantity <= 0) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every line needs a quantity above zero.';
                END IF;
                SET v_msg = (SELECT LEFT(CONCAT(l.item_name, ' is not on sale.'), 128) FROM pos_sale_lines l
                             LEFT JOIN pos_products p ON p.item_id = l.item_id
                             WHERE l.sale_id = NEW.id AND COALESCE(p.on_sale, 0) = 0 ORDER BY l.id LIMIT 1);
                IF v_msg IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                END IF;
                IF NEW.paid_amount < NEW.total_amount THEN
                  SET v_msg = LEFT(CONCAT('Not fully paid: ', CAST(NEW.total_amount - NEW.paid_amount AS CHAR), ' is still to pay.'), 128);
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                END IF;
                SELECT COALESCE(SUM(p.amount), 0) INTO v_cash
                  FROM pos_sale_payments p JOIN pos_payment_methods m ON m.id = p.method_id
                  WHERE p.sale_id = NEW.id AND m.is_cash = 1;
                IF v_cash < NEW.change_due THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Change can only be given from cash: the other payments are more than the total.';
                END IF;
                SELECT r.id, r.warehouse_id, r.location_id, COALESCE(r.max_discount_pct, 0), r.receipt_header, r.receipt_footer
                  INTO v_register, v_warehouse, v_location, v_max, v_header, v_footer
                  FROM pos_shifts s JOIN pos_registers r ON r.id = s.register_id WHERE s.id = NEW.shift_id;
                SET v_msg = (SELECT LEFT(CONCAT(l.item_name, ': the discount is above the limit of this register.'), 128) FROM pos_sale_lines l
                             WHERE l.sale_id = NEW.id AND 0 < l.list_price
                               AND v_max < ROUND((l.quantity * l.list_price - l.line_total) * 100 / (l.quantity * l.list_price), 2)
                             ORDER BY l.id LIMIT 1);
                IF v_msg IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                END IF;
                SET NEW.register_id = v_register, NEW.sold_at = COALESCE(NEW.sold_at, NOW()), NEW.receipt_header = v_header, NEW.receipt_footer = v_footer,
                    NEW.cashier_name = (SELECT u.name FROM users u WHERE u.id = NEW.cashier_id);
                INSERT INTO wh_documents (doc_no, doc_type, doc_date, warehouse_id, customer_id, counterparty, keeper_id, status, post_on_save, create_work, notes, created_at, updated_at)
                VALUES (CONCAT('POS-', NEW.receipt_no), 'sales', DATE(NEW.sold_at), v_warehouse, NEW.customer_id,
                        (SELECT c.name FROM crm_customers c WHERE c.id = NEW.customer_id), NEW.cashier_id, 'draft', 0, 0, CONCAT('Point of sale receipt ', NEW.receipt_no), NOW(), NOW());
                SET v_doc = LAST_INSERT_ID();
                INSERT INTO wh_document_lines (document_id, item_id, direction, location_id, quantity, unit_cost, amount, created_at, updated_at)
                SELECT v_doc, l.item_id, 'out', v_location, l.quantity, x.cost, ROUND(l.quantity * x.cost, 2), NOW(), NOW()
                FROM pos_sale_lines l
                JOIN (SELECT i.id, ROUND(GREATEST(COALESCE(a.avg_cost, i.unit_price), 0), 4) AS cost, COALESCE(c.is_service, 0) AS is_service
                      FROM proc_items i
                      LEFT JOIN proc_item_categories c ON c.id = i.category_id
                      LEFT JOIN (SELECT w.item_id, SUM(w.amount) / NULLIF(SUM(w.quantity), 0) AS avg_cost FROM wh_stocks w WHERE w.warehouse_id = v_warehouse GROUP BY w.item_id) a ON a.item_id = i.id
                     ) x ON x.id = l.item_id
                WHERE l.sale_id = NEW.id AND x.is_service = 0
                ORDER BY l.id;
                SET v_lines = ROW_COUNT();
                IF v_lines = 0 THEN
                  DELETE FROM wh_documents WHERE id = v_doc;
                ELSE
                  INSERT INTO wh_stock_moves (document_id, line_id, doc_type, moved_on, item_id, warehouse_id, location_id, quantity, unit_cost, amount, created_at, updated_at)
                  SELECT d.id, l.id, d.doc_type, d.doc_date, l.item_id, d.warehouse_id, l.location_id, -l.quantity, l.unit_cost, 0, NOW(), NOW()
                  FROM wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                  WHERE d.id = v_doc ORDER BY l.id;
                  UPDATE wh_documents d
                  SET d.status = 'posted', d.posted_at = NOW(), d.posted_by = NEW.cashier_id, d.updated_at = NOW(),
                      d.total_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM wh_document_lines l WHERE l.document_id = d.id)
                  WHERE d.id = v_doc;
                  SET NEW.stock_document_id = v_doc;
                END IF;
              ELSEIF NEW.status = OLD.status AND OLD.status != 'held' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sale is finished and cannot be changed.';
              ELSEIF NEW.status != OLD.status AND NEW.status != 'void' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sale goes from held to completed or void, and from completed to void.';
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_sales_before_void BEFORE UPDATE ON pos_sales FOR EACH ROW
            BEGIN
              DECLARE v_doc BIGINT UNSIGNED;
              IF OLD.status = 'void' AND NEW.status != 'void' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A sale goes from held to completed or void, and from completed to void.';
              ELSEIF OLD.status = 'held' AND NEW.status = 'void' THEN
                SET NEW.voided_at = NOW();
              ELSEIF OLD.status = 'completed' AND NEW.status = 'void' THEN
                IF COALESCE(NEW.void_reason, '') = '' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Say why the sale is voided.';
                END IF;
                IF COALESCE((SELECT s.status FROM pos_shifts s WHERE s.id = OLD.shift_id), '') != 'open' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The shift of this sale is closed: make a return instead.';
                END IF;
                IF EXISTS (SELECT 1 FROM pos_returns r WHERE r.sale_id = OLD.id AND r.status = 'completed') THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This sale has returns, so it cannot be voided.';
                END IF;
                SET NEW.voided_at = NOW();
                IF OLD.stock_document_id IS NOT NULL THEN
                  INSERT INTO wh_documents (doc_no, doc_type, doc_date, warehouse_id, customer_id, counterparty, keeper_id, reason, status, post_on_save, create_work, notes, created_at, updated_at)
                  SELECT CONCAT('POS-V-', OLD.receipt_no), 'return_in', CURDATE(), d.warehouse_id, d.customer_id, d.counterparty, NEW.voided_by, 'other', 'draft', 0, 0,
                         LEFT(CONCAT('Void of receipt ', OLD.receipt_no, ': ', NEW.void_reason), 250), NOW(), NOW()
                  FROM wh_documents d WHERE d.id = OLD.stock_document_id;
                  SET v_doc = LAST_INSERT_ID();
                  INSERT INTO wh_document_lines (document_id, item_id, direction, location_id, quantity, unit_cost, amount, created_at, updated_at)
                  SELECT v_doc, l.item_id, 'in', l.location_id, l.quantity, l.unit_cost, l.amount, NOW(), NOW()
                  FROM wh_document_lines l WHERE l.document_id = OLD.stock_document_id ORDER BY l.id;
                  INSERT INTO wh_stock_moves (document_id, line_id, doc_type, moved_on, item_id, warehouse_id, location_id, quantity, unit_cost, amount, created_at, updated_at)
                  SELECT d.id, l.id, d.doc_type, d.doc_date, l.item_id, d.warehouse_id, l.location_id, l.quantity, l.unit_cost, 0, NOW(), NOW()
                  FROM wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                  WHERE d.id = v_doc ORDER BY l.id;
                  UPDATE wh_documents d
                  SET d.status = 'posted', d.posted_at = NOW(), d.posted_by = NEW.voided_by, d.updated_at = NOW(),
                      d.total_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM wh_document_lines l WHERE l.document_id = d.id)
                  WHERE d.id = v_doc;
                  SET NEW.void_document_id = v_doc;
                END IF;
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_sales_before_delete BEFORE DELETE ON pos_sales FOR EACH ROW
            BEGIN
              IF OLD.status != 'held' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a held sale can be deleted.';
              END IF;
            END
            SQL);
    }

    private function saleLines(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_sale_lines_before_{$suffix} BEFORE {$event} ON pos_sale_lines FOR EACH ROW
                BEGIN
                  DECLARE v_name VARCHAR(255);
                  DECLARE v_list DECIMAL(14,2);
                  DECLARE v_tax DECIMAL(5,2);
                  IF (SELECT s.status FROM pos_sales s WHERE s.id = NEW.sale_id) != 'held' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a held sale can be changed.';
                  END IF;
                  SELECT i.name, COALESCE(p.sale_price, i.unit_price), COALESCE(t.rate, 0) INTO v_name, v_list, v_tax
                    FROM proc_items i
                    LEFT JOIN pos_products p ON p.item_id = i.id
                    LEFT JOIN pos_tax_rates t ON t.id = p.tax_rate_id
                    WHERE i.id = NEW.item_id;
                  SET NEW.item_name = v_name, NEW.list_price = v_list, NEW.tax_rate = v_tax, NEW.unit_price = COALESCE(NEW.unit_price, v_list),
                      NEW.discount_pct = LEAST(GREATEST(COALESCE(NEW.discount_pct, 0), 0), 100);
                  SET NEW.gross_amount = ROUND(NEW.quantity * NEW.unit_price, 2);
                  SET NEW.discount_amount = ROUND(NEW.gross_amount * NEW.discount_pct / 100, 2);
                  SET NEW.line_total = NEW.gross_amount - NEW.discount_amount;
                  SET NEW.tax_amount = ROUND(NEW.line_total * v_tax / (100 + v_tax), 2);
                END
                SQL);
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_sale_lines_before_delete BEFORE DELETE ON pos_sale_lines FOR EACH ROW
            BEGIN
              IF (SELECT s.status FROM pos_sales s WHERE s.id = OLD.sale_id) != 'held' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a held sale can be changed.';
              END IF;
            END
            SQL);
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'NEW'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_sale_lines_after_{$suffix} AFTER {$event} ON pos_sale_lines FOR EACH ROW
                  UPDATE pos_sales s
                  JOIN (SELECT COALESCE(SUM(l.gross_amount), 0) AS g, COALESCE(SUM(l.discount_amount), 0) AS d, COALESCE(SUM(l.tax_amount), 0) AS t, COALESCE(SUM(l.line_total), 0) AS n
                        FROM pos_sale_lines l WHERE l.sale_id = {$row}.sale_id) x
                  SET s.subtotal = x.g, s.discount_total = x.d, s.tax_total = x.t, s.total_amount = x.n, s.change_due = GREATEST(s.paid_amount - x.n, 0), s.updated_at = NOW()
                  WHERE s.id = {$row}.sale_id
                SQL);
        }
    }

    private function payments(): void
    {
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'NEW'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_sale_payments_before_{$suffix} BEFORE {$event} ON pos_sale_payments FOR EACH ROW
                BEGIN
                  IF (SELECT s.status FROM pos_sales s WHERE s.id = {$row}.sale_id) != 'held' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a held sale can be changed.';
                  END IF;
                END
                SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_sale_payments_after_{$suffix} AFTER {$event} ON pos_sale_payments FOR EACH ROW
                  UPDATE pos_sales s
                  JOIN (SELECT COALESCE(SUM(p.amount), 0) AS paid FROM pos_sale_payments p WHERE p.sale_id = {$row}.sale_id) x
                  SET s.paid_amount = x.paid, s.change_due = GREATEST(x.paid - s.total_amount, 0), s.updated_at = NOW()
                  WHERE s.id = {$row}.sale_id
                SQL);
        }
    }

    private function returns(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_returns_before_insert BEFORE INSERT ON pos_returns FOR EACH ROW
            BEGIN
              SET NEW.status = 'draft', NEW.total_amount = 0, NEW.tax_total = 0;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_returns_before_update BEFORE UPDATE ON pos_returns FOR EACH ROW
            BEGIN
              DECLARE v_register BIGINT UNSIGNED;
              DECLARE v_warehouse BIGINT UNSIGNED;
              DECLARE v_location BIGINT UNSIGNED;
              DECLARE v_customer BIGINT UNSIGNED;
              DECLARE v_sale_doc BIGINT UNSIGNED;
              DECLARE v_receipt VARCHAR(30);
              DECLARE v_status VARCHAR(20);
              DECLARE v_doc BIGINT UNSIGNED;
              DECLARE v_lines INT DEFAULT 0;
              DECLARE v_msg VARCHAR(128);
              IF OLD.status = 'draft' AND NEW.status = 'completed' THEN
                IF NEW.shift_id IS NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Open a shift before taking a return.';
                END IF;
                IF COALESCE((SELECT s.status FROM pos_shifts s WHERE s.id = NEW.shift_id), '') != 'open' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That shift is closed: open a new one.';
                END IF;
                SELECT s.status, s.stock_document_id, s.customer_id, s.receipt_no INTO v_status, v_sale_doc, v_customer, v_receipt
                  FROM pos_sales s WHERE s.id = NEW.sale_id;
                IF v_status != 'completed' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a completed sale can be returned.';
                END IF;
                IF NEW.refund_method_id IS NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pick how the money goes back.';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pos_return_lines l WHERE l.return_id = NEW.id) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The return has no lines.';
                END IF;
                IF EXISTS (SELECT 1 FROM pos_return_lines l WHERE l.return_id = NEW.id AND l.quantity <= 0) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every line needs a quantity above zero.';
                END IF;
                SET v_msg = (SELECT LEFT(CONCAT(q.item_name, ': only ', CAST(COALESCE(d.sold, 0) - COALESCE(x.done, 0) AS CHAR), ' can be returned.'), 128)
                             FROM (SELECT l.item_id, MIN(l.item_name) AS item_name, SUM(l.quantity) AS wanted FROM pos_return_lines l WHERE l.return_id = NEW.id GROUP BY l.item_id) q
                             LEFT JOIN (SELECT l.item_id, SUM(l.quantity) AS sold FROM pos_sale_lines l WHERE l.sale_id = NEW.sale_id GROUP BY l.item_id) d ON d.item_id = q.item_id
                             LEFT JOIN (SELECT l.item_id, SUM(l.quantity) AS done FROM pos_return_lines l JOIN pos_returns r ON r.id = l.return_id
                                        WHERE r.sale_id = NEW.sale_id AND r.status = 'completed' GROUP BY l.item_id) x ON x.item_id = q.item_id
                             WHERE COALESCE(d.sold, 0) - COALESCE(x.done, 0) < q.wanted
                             LIMIT 1);
                IF v_msg IS NOT NULL THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
                END IF;
                SELECT r.id, r.warehouse_id, r.location_id INTO v_register, v_warehouse, v_location
                  FROM pos_shifts s JOIN pos_registers r ON r.id = s.register_id WHERE s.id = NEW.shift_id;
                SET NEW.register_id = v_register, NEW.returned_at = COALESCE(NEW.returned_at, NOW()), NEW.cashier_name = (SELECT u.name FROM users u WHERE u.id = NEW.cashier_id);
                INSERT INTO wh_documents (doc_no, doc_type, doc_date, warehouse_id, customer_id, counterparty, keeper_id, reason, status, post_on_save, create_work, notes, created_at, updated_at)
                VALUES (CONCAT('POS-', NEW.return_no), 'return_in', DATE(NEW.returned_at), v_warehouse, v_customer,
                        (SELECT c.name FROM crm_customers c WHERE c.id = v_customer), NEW.cashier_id, 'other', 'draft', 0, 0,
                        LEFT(CONCAT('Return of receipt ', v_receipt, COALESCE(CONCAT(': ', NEW.reason), '')), 250), NOW(), NOW());
                SET v_doc = LAST_INSERT_ID();
                INSERT INTO wh_document_lines (document_id, item_id, direction, location_id, quantity, unit_cost, amount, created_at, updated_at)
                SELECT v_doc, l.item_id, 'in', v_location, l.quantity, x.cost, ROUND(l.quantity * x.cost, 2), NOW(), NOW()
                FROM pos_return_lines l
                JOIN (SELECT i.id, ROUND(GREATEST(COALESCE(a.avg_cost, i.unit_price), 0), 4) AS cost, COALESCE(c.is_service, 0) AS is_service
                      FROM proc_items i
                      LEFT JOIN proc_item_categories c ON c.id = i.category_id
                      LEFT JOIN (SELECT w.item_id, SUM(w.amount) / NULLIF(SUM(w.quantity), 0) AS avg_cost FROM wh_document_lines w WHERE w.document_id = v_sale_doc GROUP BY w.item_id) a ON a.item_id = i.id
                     ) x ON x.id = l.item_id
                WHERE l.return_id = NEW.id AND l.restock = 1 AND x.is_service = 0
                ORDER BY l.id;
                SET v_lines = ROW_COUNT();
                IF v_lines = 0 THEN
                  DELETE FROM wh_documents WHERE id = v_doc;
                ELSE
                  INSERT INTO wh_stock_moves (document_id, line_id, doc_type, moved_on, item_id, warehouse_id, location_id, quantity, unit_cost, amount, created_at, updated_at)
                  SELECT d.id, l.id, d.doc_type, d.doc_date, l.item_id, d.warehouse_id, l.location_id, l.quantity, l.unit_cost, 0, NOW(), NOW()
                  FROM wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
                  WHERE d.id = v_doc ORDER BY l.id;
                  UPDATE wh_documents d
                  SET d.status = 'posted', d.posted_at = NOW(), d.posted_by = NEW.cashier_id, d.updated_at = NOW(),
                      d.total_amount = (SELECT COALESCE(SUM(l.amount), 0) FROM wh_document_lines l WHERE l.document_id = d.id)
                  WHERE d.id = v_doc;
                  SET NEW.stock_document_id = v_doc;
                END IF;
              ELSEIF NEW.status != OLD.status THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A return goes from draft to completed.';
              ELSEIF OLD.status != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This return is completed and cannot be changed.';
              END IF;
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_returns_before_delete BEFORE DELETE ON pos_returns FOR EACH ROW
            BEGIN
              IF OLD.status != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft return can be deleted.';
              END IF;
            END
            SQL);
    }

    private function returnLines(): void
    {
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_return_lines_before_{$suffix} BEFORE {$event} ON pos_return_lines FOR EACH ROW
                BEGIN
                  DECLARE v_sale BIGINT UNSIGNED;
                  DECLARE v_status VARCHAR(20);
                  DECLARE v_qty DECIMAL(14,3);
                  DECLARE v_total DECIMAL(14,2);
                  DECLARE v_tax DECIMAL(14,2);
                  SELECT r.sale_id, r.status INTO v_sale, v_status FROM pos_returns r WHERE r.id = NEW.return_id;
                  IF v_status != 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft return can be changed.';
                  END IF;
                  SELECT SUM(l.quantity), SUM(l.line_total), SUM(l.tax_amount) INTO v_qty, v_total, v_tax
                    FROM pos_sale_lines l WHERE l.sale_id = v_sale AND l.item_id = NEW.item_id;
                  SET NEW.item_name = (SELECT i.name FROM proc_items i WHERE i.id = NEW.item_id),
                      NEW.unit_price = COALESCE(ROUND(v_total / NULLIF(v_qty, 0), 2), 0),
                      NEW.line_total = COALESCE(ROUND(v_total * NEW.quantity / NULLIF(v_qty, 0), 2), 0),
                      NEW.tax_amount = COALESCE(ROUND(v_tax * NEW.quantity / NULLIF(v_qty, 0), 2), 0);
                END
                SQL);
        }
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pos_return_lines_before_delete BEFORE DELETE ON pos_return_lines FOR EACH ROW
            BEGIN
              IF (SELECT r.status FROM pos_returns r WHERE r.id = OLD.return_id) != 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft return can be changed.';
              END IF;
            END
            SQL);
        foreach (['insert' => ['INSERT', 'NEW'], 'update' => ['UPDATE', 'NEW'], 'delete' => ['DELETE', 'OLD']] as $suffix => [$event, $row]) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER pos_return_lines_after_{$suffix} AFTER {$event} ON pos_return_lines FOR EACH ROW
                  UPDATE pos_returns r
                  JOIN (SELECT COALESCE(SUM(l.line_total), 0) AS n, COALESCE(SUM(l.tax_amount), 0) AS t FROM pos_return_lines l WHERE l.return_id = {$row}.return_id) x
                  SET r.total_amount = x.n, r.tax_total = x.t, r.updated_at = NOW()
                  WHERE r.id = {$row}.return_id
                SQL);
        }
    }
};
