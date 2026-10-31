<?php

namespace App\Models;

use App\Support\Market\Tenders;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tender: an organizer (a person, or a company through its representative) asks for items
 * under a rule, and bidders answer. The code is TND-{yymmdd}-{seq:3}, counted per day.
 */
class Tender extends Model
{
    use HasFactory;

    protected $table = 'market_tenders';

    public const TYPES = ['goods', 'services', 'works'];

    public const STATUSES = ['draft', 'open', 'closed', 'evaluating', 'negotiating', 'awarded', 'contracted', 'cancelled'];

    protected $fillable = [
        'title', 'type', 'description', 'organizer_company_id', 'organizer_user_id', 'created_by', 'category_id', 'incoterm',
        'starts_at', 'closes_at', 'status', 'rule', 'round', 'final_round_closes_at', 'published_at', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = ['status' => 'draft', 'round' => 1];

    protected static function booted(): void
    {
        static::creating(function (Tender $tender) {
            $prefix = 'TND-'.now()->format('ymd').'-';
            $last = (string) Tender::where('code', 'like', $prefix.'%')->max('code');
            $tender->code = $prefix.sprintf('%03d', $last ? ((int) substr($last, strlen($prefix))) + 1 : 1);
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'closes_at' => 'datetime',
            'final_round_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'round' => 'integer',
        ];
    }

    /** The rule with every setting filled in (see Tenders::rule). */
    public function getRuleAttribute(?string $value): array
    {
        return Tenders::rule($value ? (array) json_decode($value, true) : []);
    }

    public function setRuleAttribute(?array $value): void
    {
        $this->attributes['rule'] = json_encode(Tenders::rule($value ?? []));
    }

    public function items(): HasMany
    {
        return $this->hasMany(TenderItem::class)->orderBy('position')->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TenderFile::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(TenderApplication::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(TenderAward::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TenderEvent::class)->orderBy('id');
    }

    public function organizerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'organizer_company_id');
    }

    public function organizerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketCategory::class);
    }
}
