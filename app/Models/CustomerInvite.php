<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;

/** A code a customer's admin hands out: whoever joins with it gets role_ids (roles of that customer). */
class CustomerInvite extends Model
{
    use OnMainSchema;

    protected $fillable = ['customer_id', 'code', 'role_ids', 'created_by', 'expires_at', 'max_uses', 'uses', 'revoked_at'];

    protected function casts(): array
    {
        return ['role_ids' => 'array', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'max_uses' => 'integer', 'uses' => 'integer'];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses);
    }
}
