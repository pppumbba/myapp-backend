<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-time link for a colleague to join a company with the rights it carries. */
class CompanyInvite extends Model
{
    protected $fillable = ['company_id', 'token_hash', 'can_bid', 'can_sign', 'can_manage', 'created_by', 'expires_at', 'used_by', 'used_at'];

    protected $hidden = ['token_hash'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['can_bid' => 'boolean', 'can_sign' => 'boolean', 'can_manage' => 'boolean', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
