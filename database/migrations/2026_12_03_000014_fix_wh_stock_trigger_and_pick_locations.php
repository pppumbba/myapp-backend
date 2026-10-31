<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two fixes to the warehouse app (2026_12_03_000012 / 000013):
 *
 * - wh_stock_moves_after_insert used INSERT ... ON DUPLICATE KEY UPDATE, but MySQL fires the
 *   BEFORE INSERT trigger of wh_stocks even when the row exists, so the negative check saw the
 *   move (e.g. -3) instead of the new balance. It now updates the balance, inserting only when
 *   there is none.
 * - A line leaving stock without a location takes the location holding the most of it (when the
 *   unlocated stock is short); added to the fill and post stage queries.
 *
 * Idempotent. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const MARK = 'takes the location holding';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS `wh_stock_moves_after_insert`');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER wh_stock_moves_after_insert AFTER INSERT ON wh_stock_moves FOR EACH ROW
            BEGIN
              UPDATE wh_stocks SET quantity = quantity + NEW.quantity, amount = amount + NEW.amount,
                     last_move_on = GREATEST(COALESCE(last_move_on, NEW.moved_on), NEW.moved_on), updated_at = NOW()
              WHERE item_id = NEW.item_id AND warehouse_id = NEW.warehouse_id AND location_id = COALESCE(NEW.location_id, 0);
              IF ROW_COUNT() = 0 THEN
                INSERT INTO wh_stocks (item_id, warehouse_id, location_id, quantity, amount, last_move_on, created_at, updated_at)
                VALUES (NEW.item_id, NEW.warehouse_id, COALESCE(NEW.location_id, 0), NEW.quantity, NEW.amount, NEW.moved_on, NOW(), NOW());
              END IF;
            END
            SQL);

        $suggest = <<<'SQL'
            -- A line leaving without a location takes the location holding the most of it, when the unlocated stock is short.
            UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id
            SET l.location_id = (SELECT s.location_id FROM wh_stocks s
                                 WHERE s.item_id = l.item_id AND s.warehouse_id = d.warehouse_id AND s.location_id <> 0 AND s.quantity >= l.quantity
                                 ORDER BY s.quantity DESC, s.location_id LIMIT 1)
            WHERE d.id = :document_id AND d.status = 'draft' AND l.location_id IS NULL
              AND (l.direction IN ('out', 'move') OR (COALESCE(l.direction, '') = '' AND d.doc_type IN ('sales', 'outcome', 'return_out', 'dispose', 'movement')))
              AND COALESCE((SELECT u.quantity FROM wh_stocks u WHERE u.item_id = l.item_id AND u.warehouse_id = d.warehouse_id AND u.location_id = 0), 0) < l.quantity
              AND EXISTS (SELECT 1 FROM wh_stocks s WHERE s.item_id = l.item_id AND s.warehouse_id = d.warehouse_id AND s.location_id <> 0 AND s.quantity >= l.quantity)
            SQL;

        DB::transaction(function () use ($suggest) {
            $this->insertBefore('wh_post_document', "-- What leaves costs the warehouse's average.", $suggest);
            $this->insertBefore('wh_fill_document_lines', "UPDATE wh_document_lines l JOIN wh_documents d ON d.id = l.document_id\nSET l.amount", $suggest);
        });
    }

    public function down(): void
    {
        // The fixes stay: the old trigger refused valid moves.
    }

    private function insertBefore(string $code, string $anchor, string $statement): void
    {
        $sql = DB::table('metas')->where('code', $code)->value('query_string');
        if ($sql === null || str_contains($sql, self::MARK)) {
            return;
        }
        $at = strpos($sql, $anchor);
        if ($at === false) {
            return;
        }
        DB::table('metas')->where('code', $code)->update([
            'query_string' => substr($sql, 0, $at).$statement.";\n".substr($sql, $at),
            'updated_at' => now(),
        ]);
    }
};
