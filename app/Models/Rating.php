<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side's rating of the other after a completed contract: 1–5 stars and a comment. */
class Rating extends Model
{
    protected $fillable = ['side', 'rater_user_id', 'rated_company_id', 'rated_user_id', 'stars', 'comment'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }
}
