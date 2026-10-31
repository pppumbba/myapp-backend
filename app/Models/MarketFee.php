<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What the market charges for one kind of thing: a fixed amount in ₮, or a percent of the value. */
class MarketFee extends Model
{
    public const KINDS = ['posting', 'bidding', 'contract', 'middleman'];

    protected $fillable = ['kind', 'mode', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
