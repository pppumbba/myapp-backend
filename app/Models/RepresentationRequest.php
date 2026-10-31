<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to act for a company: approved by its director through a link (director), or by
 * staff from a power of attorney document (attorney).
 */
class RepresentationRequest extends Model
{
    protected $fillable = ['company_id', 'user_id', 'method', 'status', 'token_hash', 'expires_at', 'content_id', 'reason', 'decided_by', 'decided_at'];

    protected $hidden = ['token_hash'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The power of attorney document. */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
