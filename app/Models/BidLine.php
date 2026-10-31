<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bid's price, quantity and lead time for one tender item. */
class BidLine extends Model
{
    protected $table = 'market_bid_lines';

    protected $fillable = ['bid_id', 'tender_item_id', 'price', 'quantity', 'lead_time_days', 'amount'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
            'lead_time_days' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenderItem::class, 'tender_item_id');
    }
}
