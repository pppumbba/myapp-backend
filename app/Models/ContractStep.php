<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment step of a contract (e.g. 30% advance). With escrow it moves pending → funded →
 * requested → released (or refunded, or frozen by a dispute); otherwise it is only a record.
 */
class ContractStep extends Model
{
    protected $fillable = ['position', 'label', 'percent', 'amount', 'status', 'requested_at', 'auto_release_at', 'released_at', 'note'];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'auto_release_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EscrowEntry::class);
    }
}
