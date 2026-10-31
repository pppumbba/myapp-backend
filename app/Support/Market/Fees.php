<?php

namespace App\Support\Market;

use App\Models\MarketFee;

/** What the market charges, as staff set it: a fixed amount in ₮ or a percent of the value. */
class Fees
{
    public const MODES = ['fixed', 'percent'];

    /** The fee for this kind on this value, in whole ₮ (0 when the kind has no setting). */
    public static function quote(string $kind, float $value = 0): int
    {
        $fee = MarketFee::where('kind', $kind)->first();
        if (! $fee) {
            return 0;
        }

        return (int) round($fee->mode === 'percent' ? $value * (float) $fee->amount / 100 : (float) $fee->amount);
    }

    /** @return list<array{kind: string, mode: string, amount: float}> */
    public static function all(): array
    {
        return MarketFee::orderBy('id')->get()
            ->map(fn (MarketFee $f) => ['kind' => $f->kind, 'mode' => $f->mode, 'amount' => (float) $f->amount])
            ->all();
    }

    /** @param  list<array{kind: string, mode: string, amount: float|int|string}>  $fees */
    public static function update(array $fees): void
    {
        foreach ($fees as $fee) {
            abort_if($fee['mode'] === 'percent' && $fee['amount'] > 100, 422, __('A percent fee is at most 100.'));
        }
        foreach ($fees as $fee) {
            MarketFee::updateOrCreate(['kind' => $fee['kind']], ['mode' => $fee['mode'], 'amount' => $fee['amount']]);
        }
    }
}
