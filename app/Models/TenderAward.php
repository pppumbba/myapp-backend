<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a winning bid was awarded: the whole tender, an item, or a quantity of an item. */
class TenderAward extends Model
{
    protected $table = 'market_tender_awards';

    protected $fillable = ['tender_id', 'bid_id', 'tender_item_id', 'quantity', 'amount', 'contract_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
        ];
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenderItem::class, 'tender_item_id');
    }
}
