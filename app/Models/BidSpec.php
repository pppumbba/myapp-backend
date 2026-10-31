<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bid's answer to one spec: the offered value and meets/exceeds/fails, which the poster may override. */
class BidSpec extends Model
{
    protected $table = 'market_bid_specs';

    protected $fillable = ['bid_id', 'tender_spec_id', 'value', 'claim', 'content_id', 'override_claim', 'override_by', 'override_note'];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function spec(): BelongsTo
    {
        return $this->belongsTo(TenderSpec::class, 'tender_spec_id');
    }
}
