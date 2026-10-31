<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line in a contract's timeline: who did what, and the status it moved between. */
class ContractEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'from_status', 'to_status', 'note'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
