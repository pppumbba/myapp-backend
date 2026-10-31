<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Money a user pays through the payment provider: a fee, or an escrow step held by the market.
 * Its subject is what it pays for (e.g. a contract).
 */
class Payment extends Model
{
    public const KINDS = ['posting', 'bidding', 'contract', 'middleman', 'escrow', 'security'];

    protected $fillable = [
        'user_id', 'company_id', 'kind', 'amount', 'lines', 'status', 'driver', 'invoice_id', 'qr_text', 'qr_image', 'pay_url',
        'subject_type', 'subject_id', 'contract_step_id', 'receipt_number', 'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'lines' => 'array', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ContractStep::class, 'contract_step_id');
    }
}
