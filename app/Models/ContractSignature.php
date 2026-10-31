<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side's signature, made by a fresh DAN sign-in, on one version of the contract. */
class ContractSignature extends Model
{
    public $timestamps = false;

    protected $fillable = ['contract_id', 'side', 'user_id', 'registry_number', 'verified_name', 'version', 'signed_at'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
