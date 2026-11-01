<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A small shop for the point of sale app, so it does not open empty: categories and products,
 * a store warehouse with opening stock, two registers, three customers, and four shifts (three
 * closed, one open today) with sales, a return, a void and a held sale.
 *
 * Everything goes through the tables' triggers the way the app's own saves do, so the demo
 * sales are real ones: they post to the stock ledger and the shifts count their own cash.
 * Cashiers are the first two users; no login is made. Receipts, shifts and customers start
 * DEMO, and products SKU, clear of the app's own numbering.
 *
 * Only runs once, on a database with users and no registers yet. MySQL/MariaDB only.
 */
return new class extends Migration
{
    private const OPENING_STOCK = 200;

    /** key => [barcode, code, name, category, unit, cost, price, tax] */
    private const PRODUCTS = [
        'cola' => ['8650000000011', 'SKU-1001', 'Cola 0.5 l', 'POS-DRINK', 'pcs', 1400, 2500, 'VAT10'],
        'water' => ['8650000000028', 'SKU-1002', 'Mineral water 0.5 l', 'POS-DRINK', 'pcs', 600, 1200, 'VAT10'],
        'juice' => ['8650000000035', 'SKU-1003', 'Apple juice 1 l', 'POS-DRINK', 'pcs', 3200, 5500, 'VAT10'],
        'coffee' => ['8650000000042', 'SKU-1004', 'Instant coffee 100 g', 'POS-DRINK', 'pcs', 7800, 12900, 'VAT10'],
        'chips' => ['8650000000059', 'SKU-2001', 'Potato chips 150 g', 'POS-SNACK', 'pcs', 2100, 3900, 'VAT10'],
        'choc' => ['8650000000066', 'SKU-2002', 'Milk chocolate 90 g', 'POS-SNACK', 'pcs', 2600, 4500, 'VAT10'],
        'cookies' => ['8650000000073', 'SKU-2003', 'Butter cookies 200 g', 'POS-SNACK', 'pcs', 3000, 5200, 'VAT10'],
        'bread' => ['8650000000080', 'SKU-3001', 'White loaf', 'POS-BAKERY', 'pcs', 1100, 1900, 'ZERO'],
        'croissant' => ['8650000000097', 'SKU-3002', 'Croissant', 'POS-BAKERY', 'pcs', 1300, 2800, 'VAT10'],
        'soap' => ['8650000000103', 'SKU-4001', 'Hand soap 250 ml', 'POS-HOME', 'pcs', 2900, 4900, 'VAT10'],
        'tissue' => ['8650000000110', 'SKU-4002', 'Paper tissues 6 pack', 'POS-HOME', 'pcs', 3600, 6200, 'VAT10'],
        'wrap' => ['8650000000127', 'SKU-9001', 'Gift wrapping', 'SERVICE', 'pcs', 0, 3000, 'VAT10'],
    ];

    /** @var array<string, int> item id by product key */
    private array $items = [];

    /** @var array<string, int> */
    private array $methods = [];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || DB::table('pos_registers')->exists()) {
            return;
        }
        $users = DB::table('users')->orderBy('id')->limit(2)->pluck('id')->all();
        if (! $users) {
            return;
        }

        DB::transaction(function () use ($users) {
            $now = now();
            $this->methods = DB::table('pos_payment_methods')->pluck('id', 'code')->all();
            $taxes = DB::table('pos_tax_rates')->pluck('id', 'code')->all();

            foreach ([['POS-DRINK', 'Drinks'], ['POS-SNACK', 'Snacks'], ['POS-BAKERY', 'Bakery'], ['POS-HOME', 'Household']] as [$code, $name]) {
                DB::table('proc_item_categories')->insertOrIgnore(['code' => $code, 'name' => $name, 'default_unit' => 'pcs', 'created_at' => $now, 'updated_at' => $now]);
            }
            $categories = DB::table('proc_item_categories')->pluck('id', 'code')->all();

            foreach (self::PRODUCTS as $key => [$barcode, $code, $name, $category, $unit, $cost, $price, $tax]) {
                $item = DB::table('proc_items')->insertGetId([
                    'code' => $code, 'name' => $name, 'category_id' => $categories[$category] ?? null, 'unit' => $unit, 'unit_price' => $cost,
                    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->items[$key] = $item;
                DB::table('pos_products')->insert([
                    'item_id' => $item, 'barcode' => $barcode, 'sale_price' => $price, 'tax_rate_id' => $taxes[$tax], 'on_sale' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $warehouse = DB::table('wh_warehouses')->insertGetId([
                'code' => 'POS-STORE', 'name' => 'Main store', 'kind' => 'main', 'allow_negative' => false, 'is_active' => true,
                'notes' => 'The shop floor the demo registers sell from.', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $today = DB::selectOne('SELECT CURDATE() AS d')->d;
            $this->openingStock($warehouse, $this->day($today, 10));

            $registers = [];
            foreach ([['REG-1', 'Front till'], ['REG-2', 'Second till']] as [$code, $name]) {
                $registers[$code] = DB::table('pos_registers')->insertGetId([
                    'code' => $code, 'name' => $name, 'warehouse_id' => $warehouse, 'max_discount_pct' => 10, 'is_active' => true,
                    'receipt_header' => 'Main store', 'receipt_footer' => 'Thank you for shopping with us', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $customers = [];
            foreach ([['DEMO-C1', 'Oyuna Bat', '99001122'], ['DEMO-C2', 'Temuulen Dorj', '88112233'], ['DEMO-C3', 'Sarnai Gantulga', '95223344']] as [$code, $name, $phone]) {
                $customers[] = DB::table('crm_customers')->insertGetId([
                    'code' => $code, 'kind' => 'person', 'name' => $name, 'phone' => $phone, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            [$first, $second] = [$users[0], $users[1] ?? $users[0]];

            $shift = $this->openShift('DEMO-SH-1', $registers['REG-1'], $first, 100000, $this->day($today, 5).' 09:00:00');
            $this->sell($shift, 'DEMO-0001', $this->day($today, 5).' 09:40:00', [['cola', 2], ['chips', 1]], ['CASH' => 'up']);
            $this->sell($shift, 'DEMO-0002', $this->day($today, 5).' 11:15:00', [['coffee', 1], ['bread', 2], ['croissant', 2]], ['CARD' => 'all'], $customers[0]);
            $this->sell($shift, 'DEMO-0003', $this->day($today, 5).' 14:05:00', [['tissue', 2], ['soap', 1, 5]], ['QPAY' => 'all']);
            $this->closeShift($shift, 0, $this->day($today, 5).' 18:00:00');

            $shift = $this->openShift('DEMO-SH-2', $registers['REG-2'], $second, 100000, $this->day($today, 3).' 09:00:00');
            $this->sell($shift, 'DEMO-0004', $this->day($today, 3).' 10:20:00', [['water', 6], ['choc', 3]], ['CASH' => 'up']);
            $this->sell($shift, 'DEMO-0005', $this->day($today, 3).' 12:45:00', [['juice', 2], ['cookies', 2], ['wrap', 1]], ['CARD' => 10000, 'CASH' => 'up'], $customers[1]);
            $this->sell($shift, 'DEMO-0006', $this->day($today, 3).' 16:30:00', [['cola', 4], ['bread', 1]], ['QPAY' => 'all']);
            $this->closeShift($shift, -500, $this->day($today, 3).' 18:00:00');

            $shift = $this->openShift('DEMO-SH-3', $registers['REG-1'], $first, 150000, $this->day($today, 1).' 09:00:00');
            $this->sell($shift, 'DEMO-0007', $this->day($today, 1).' 09:30:00', [['croissant', 4], ['coffee', 1]], ['CASH' => 'up']);
            $sale = $this->sell($shift, 'DEMO-0008', $this->day($today, 1).' 11:50:00', [['soap', 2], ['tissue', 1], ['choc', 2, 10]], ['CARD' => 'all'], $customers[2]);
            $this->sell($shift, 'DEMO-0009', $this->day($today, 1).' 15:10:00', [['chips', 3], ['cola', 3]], ['TRANSFER' => 'all']);
            $this->takeReturn($shift, 'DEMO-RT-1', $sale, 'soap', 1, 'faulty', $this->day($today, 1).' 16:20:00');
            $this->closeShift($shift, 0, $this->day($today, 1).' 18:00:00');

            $shift = $this->openShift('DEMO-SH-4', $registers['REG-1'], $first, 100000, null);
            $this->sell($shift, 'DEMO-0010', null, [['water', 2], ['chips', 2]], ['CASH' => 'up']);
            $this->sell($shift, 'DEMO-0011', null, [['juice', 1], ['choc', 2], ['bread', 1]], ['CARD' => 'all'], $customers[0]);
            $this->sell($shift, 'DEMO-0012', null, [['coffee', 2], ['cookies', 1]], ['QPAY' => 10000, 'CASH' => 'up']);
            $mistake = $this->sell($shift, 'DEMO-0013', null, [['cola', 1]], ['CASH' => 'all']);
            DB::table('pos_sales')->where('id', $mistake)->update(['status' => 'void', 'void_reason' => 'Rung up twice', 'voided_by' => $first]);

            $held = $this->basket('DEMO-0014', null, [['tissue', 1], ['soap', 1]], null);
            $this->pay($held, ['CASH' => 5000]);
        });
    }

    public function down(): void
    {
        // The sales sit in the stock ledger behind triggers that keep them there on purpose.
    }

    private function day(string $today, int $daysAgo): string
    {
        return date('Y-m-d', strtotime("{$today} -{$daysAgo} days"));
    }

    private function openingStock(int $warehouse, string $date): void
    {
        $now = now();
        $document = DB::table('wh_documents')->insertGetId([
            'doc_no' => 'POS-OPENING', 'doc_type' => 'income', 'doc_date' => $date, 'warehouse_id' => $warehouse, 'reason' => 'opening', 'status' => 'draft',
            'post_on_save' => false, 'create_work' => false, 'notes' => 'Opening stock of the demo shop', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $total = 0;
        foreach (self::PRODUCTS as $key => $product) {
            if ($product[3] === 'SERVICE') {
                continue;
            }
            $cost = $product[5];
            $amount = self::OPENING_STOCK * $cost;
            $total += $amount;
            $line = DB::table('wh_document_lines')->insertGetId([
                'document_id' => $document, 'item_id' => $this->items[$key], 'direction' => 'in', 'quantity' => self::OPENING_STOCK, 'unit_cost' => $cost, 'amount' => $amount,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('wh_stock_moves')->insert([
                'document_id' => $document, 'line_id' => $line, 'doc_type' => 'income', 'moved_on' => $date, 'item_id' => $this->items[$key], 'warehouse_id' => $warehouse,
                'quantity' => self::OPENING_STOCK, 'unit_cost' => $cost, 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('wh_documents')->where('id', $document)->update(['status' => 'posted', 'posted_at' => $now, 'total_amount' => $total]);
    }

    private function openShift(string $number, int $register, int $cashier, int $float, ?string $at): int
    {
        $id = DB::table('pos_shifts')->insertGetId([
            'shift_no' => $number, 'register_id' => $register, 'opening_float' => $float, 'opened_at' => $at, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
        ]);
        DB::table('pos_shifts')->where('id', $id)->update(['cashier_id' => $cashier]);

        return $id;
    }

    /** $difference: how far the drawer turned out from what it should hold. */
    private function closeShift(int $shift, int $difference, string $at): void
    {
        $row = DB::table('pos_shifts')->where('id', $shift)->first();
        $taken = DB::table('pos_sale_payments as p')->join('pos_sales as s', 's.id', '=', 'p.sale_id')->join('pos_payment_methods as m', 'm.id', '=', 'p.method_id')
            ->where('s.shift_id', $shift)->where('s.status', 'completed')->where('m.is_cash', true)->sum('p.amount');
        $change = DB::table('pos_sales')->where('shift_id', $shift)->where('status', 'completed')->sum('change_due');
        $refunded = DB::table('pos_returns as r')->join('pos_payment_methods as m', 'm.id', '=', 'r.refund_method_id')
            ->where('r.shift_id', $shift)->where('r.status', 'completed')->where('m.is_cash', true)->sum('r.total_amount');

        DB::table('pos_shifts')->where('id', $shift)->update([
            'status' => 'closed', 'closed_at' => $at, 'counted_cash' => $row->opening_float + $taken - $change - $refunded + $difference, 'updated_at' => $at,
        ]);
    }

    /** A sale from start to finish. Payments: method => an amount, 'all' (what is left), or 'up' (cash, to the next 5,000, with change). */
    private function sell(int $shift, string $receipt, ?string $at, array $lines, array $payments, ?int $customer = null): int
    {
        $sale = $this->basket($receipt, $at, $lines, $customer);
        $this->pay($sale, $payments);
        $cashier = DB::table('pos_shifts')->where('id', $shift)->value('cashier_id');
        DB::table('pos_sales')->where('id', $sale)->update(['status' => 'completed', 'shift_id' => $shift, 'cashier_id' => $cashier, 'sold_at' => $at, 'updated_at' => $at ?? now()]);

        return $sale;
    }

    /** A held sale with its lines. Lines: [product key, quantity, discount %]. */
    private function basket(string $receipt, ?string $at, array $lines, ?int $customer): int
    {
        $sale = DB::table('pos_sales')->insertGetId(['receipt_no' => $receipt, 'customer_id' => $customer, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);
        foreach ($lines as $line) {
            DB::table('pos_sale_lines')->insert([
                'sale_id' => $sale, 'item_id' => $this->items[$line[0]], 'quantity' => $line[1], 'discount_pct' => $line[2] ?? 0, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now(),
            ]);
        }

        return $sale;
    }

    private function pay(int $sale, array $payments): void
    {
        foreach ($payments as $method => $amount) {
            $due = (float) DB::table('pos_sales')->where('id', $sale)->value('total_amount') - (float) DB::table('pos_sales')->where('id', $sale)->value('paid_amount');
            $value = match ($amount) {
                'all' => $due,
                'up' => ceil($due / 5000) * 5000,
                default => $amount,
            };
            DB::table('pos_sale_payments')->insert(['sale_id' => $sale, 'method_id' => $this->methods[$method], 'amount' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function takeReturn(int $shift, string $number, int $sale, string $product, int $quantity, string $reason, string $at): void
    {
        $id = DB::table('pos_returns')->insertGetId([
            'return_no' => $number, 'sale_id' => $sale, 'refund_method_id' => $this->methods['CASH'], 'reason' => $reason, 'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('pos_return_lines')->insert(['return_id' => $id, 'item_id' => $this->items[$product], 'quantity' => $quantity, 'restock' => true, 'created_at' => $at, 'updated_at' => $at]);
        $cashier = DB::table('pos_shifts')->where('id', $shift)->value('cashier_id');
        DB::table('pos_returns')->where('id', $id)->update(['status' => 'completed', 'shift_id' => $shift, 'cashier_id' => $cashier, 'returned_at' => $at, 'updated_at' => $at]);
    }
};
