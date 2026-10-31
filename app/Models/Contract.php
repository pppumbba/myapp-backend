<?php

namespace App\Models;

use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agreement between a buyer and a vendor on the market. Each side is a company (when its
 * company id is set) or a verified person. Rules live in App\Support\Market\Contracts.
 */
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    public const STATUSES = ['draft', 'signing', 'awaiting_payment', 'active', 'in_progress', 'delivered', 'completed', 'cancelled'];

    public const MIDDLEMAN = ['off', 'on', 'escrow'];

    protected $fillable = [
        'source', 'source_id', 'title', 'buyer_company_id', 'buyer_user_id', 'vendor_company_id', 'vendor_user_id',
        'total', 'discount', 'delivery_date', 'payment_terms', 'pledge', 'pledge_amount', 'middleman', 'status',
        'version', 'pdf_path', 'created_by', 'signed_at', 'completed_at', 'cancelled_at',
    ];

    protected $hidden = ['pdf_path'];

    protected static function booted(): void
    {
        // The number follows the id, so it is set once the row exists.
        static::created(function (Contract $contract) {
            $contract->forceFill(['number' => sprintf('C-%s-%06d', $contract->created_at->format('Y'), $contract->id)])->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'discount' => 'decimal:2',
            'pledge_amount' => 'decimal:2',
            'delivery_date' => 'date:Y-m-d',
            'signed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    public function buyerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function vendorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function vendorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class)->orderBy('position');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ContractStep::class)->orderBy('position');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ContractEvent::class)->orderBy('id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(ContractDispute::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }
}
