<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a contract: what, how many, at what price. */
class ContractItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'name', 'description', 'quantity', 'unit', 'unit_price', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
