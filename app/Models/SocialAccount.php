<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's account at a sign-in provider (google): provider_id is that provider's id for
 * the person, email the address it last gave.
 */
class SocialAccount extends Model
{
    use OnMainSchema;

    protected $fillable = ['user_id', 'provider', 'provider_id', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
