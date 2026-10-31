<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who acts for a company: any member browses; bid, sign and manage are rights. */
class CompanyMember extends Model
{
    protected $fillable = ['company_id', 'user_id', 'can_bid', 'can_sign', 'can_manage'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['can_bid' => 'boolean', 'can_sign' => 'boolean', 'can_manage' => 'boolean'];
    }
}
