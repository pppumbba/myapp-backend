<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bidder's offer on a tender, from a person or a company. Each round of a negotiation is a new
 * bid; the earlier one becomes superseded. Only the bidder reads it, and the poster after close.
 */
class Bid extends Model
{
    protected $table = 'market_bids';

    public const STATUSES = ['draft', 'pending_fee', 'submitted', 'withdrawn', 'superseded', 'ruled_out'];

    public const CLAIMS = ['meets', 'exceeds', 'fails'];

    protected $fillable = [
        'tender_id', 'company_id', 'user_id', 'submitted_by', 'round', 'status', 'valid_until', 'total', 'lead_time_days',
        'quality_score', 'ruled_out_reason', 'overturned', 'shortlisted', 'submitted_at',
    ];

    protected $attributes = ['status' => 'draft', 'round' => 1];

    protected function casts(): array
    {
        return [
            'valid_until' => 'date',
            'total' => 'decimal:2',
            'quality_score' => 'decimal:2',
            'lead_time_days' => 'integer',
            'round' => 'integer',
            'overturned' => 'boolean',
            'shortlisted' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BidLine::class)->orderBy('id');
    }

    public function specs(): HasMany
    {
        return $this->hasMany(BidSpec::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TenderMessage::class)->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
