<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document of a tender, or of one of its items. */
class TenderFile extends Model
{
    protected $table = 'market_tender_files';

    protected $fillable = ['tender_id', 'tender_item_id', 'content_id'];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
