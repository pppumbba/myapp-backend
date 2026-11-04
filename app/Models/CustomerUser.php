<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person in a customer. Their roles there live in that customer's user_roles. */
class CustomerUser extends Model
{
    use OnMainSchema;

    public $timestamps = false;

    protected $fillable = ['customer_id', 'user_id', 'invite_id', 'theme_meta_id', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
