<?php

use App\Models\Meta;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two refinements of the point of sale metas (2026_12_03_000024):
 *
 * - A new payment line on the sale form starts at what is still due, so paying the exact amount
 *   takes one click. Typing over it, or emptying it, is left alone.
 * - The receipt prints two decimals: with none, half a kilo of something printed as 1 and a
 *   discounted total lost its cents.
 */
return new class extends Migration
{
    private const OLD_EVENT = "if (detail === 'lines' || detail === 'payments') refresh();";

    private const NEW_EVENT = <<<'JS'
        if (detail === 'lines' || detail === 'payments') refresh();
        // A line added to the payments has no field: it starts at what is still due.
        if (detail === 'payments' && !field) {
          lines('payments').forEach((p, i) => {
            const due = round(num(get('total_amount')) - num(get('paid_amount')), 2);
            if (!p.amount && due > 0) {
              setLine('payments', i, 'amount', due);
              refresh();
            }
          });
        }
        JS;

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::transaction(function () {
            $this->setEvent(self::NEW_EVENT);
            $this->setDecimals(2);
        });
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::transaction(function () {
            $this->setEvent(self::OLD_EVENT);
            $this->setDecimals(0);
        });
    }

    private function setEvent(string $script): void
    {
        Meta::where('code', 'pos_sale_form')->first()?->update(['event_expression_string' => $script]);
    }

    private function setDecimals(int $decimals): void
    {
        $receipt = Meta::where('code', 'pos_receipt_report')->first();
        if (! $receipt) {
            return;
        }
        $config = $receipt->json_config;
        $config['layout']['page']['decimals'] = $decimals;
        $receipt->update(['json_config' => $config]);
    }
};
