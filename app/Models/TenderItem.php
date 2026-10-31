<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An item or service a tender asks for, with its specs. */
class TenderItem extends Model
{
    protected $table = 'market_tender_items';

    protected $fillable = ['tender_id', 'position', 'name', 'quantity', 'unit', 'category_id', 'description', 'budget', 'show_budget'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'budget' => 'decimal:2',
            'show_budget' => 'boolean',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function specs(): HasMany
    {
        return $this->hasMany(TenderSpec::class)->orderBy('position')->orderBy('id');
    }
}
