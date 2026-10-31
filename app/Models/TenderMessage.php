<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A negotiation message between the poster and one bidder. */
class TenderMessage extends Model
{
    protected $table = 'market_tender_messages';

    protected $fillable = ['tender_id', 'bid_id', 'user_id', 'side', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
