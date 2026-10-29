<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A host name a website answers on, e.g. www.company.mn; the primary one is its canonical address. */
class SiteDomain extends Model
{
    protected $fillable = ['site_meta_id', 'host', 'is_primary'];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'site_meta_id');
    }
}
