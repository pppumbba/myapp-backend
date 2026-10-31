<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A specification line of a tender item: text, or a number with ≥ ≤ = and a unit; must-have or nice-to-have. */
class TenderSpec extends Model
{
    protected $table = 'market_tender_specs';

    protected $fillable = ['tender_item_id', 'position', 'label', 'kind', 'operator', 'value', 'unit', 'must'];

    protected function casts(): array
    {
        return [
            'must' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(TenderItem::class, 'tender_item_id');
    }
}
