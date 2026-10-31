<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a tender's history: published, closed, an override, an award… */
class TenderEvent extends Model
{
    protected $table = 'market_tender_events';

    public $timestamps = false;

    protected $fillable = ['tender_id', 'user_id', 'type', 'note', 'data', 'created_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
