<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A prequalification request: a bidder asks the poster to be allowed to bid. */
class TenderApplication extends Model
{
    protected $table = 'market_tender_applications';

    protected $fillable = ['tender_id', 'company_id', 'user_id', 'applicant_user_id', 'note', 'content_ids', 'status', 'reason', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return [
            'content_ids' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
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
