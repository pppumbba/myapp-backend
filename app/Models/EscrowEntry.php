<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of the escrow ledger: money held, released, refunded, frozen or unfrozen for a step. */
class EscrowEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['contract_id', 'contract_step_id', 'payment_id', 'type', 'amount', 'user_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ContractStep::class, 'contract_step_id');
    }
}
