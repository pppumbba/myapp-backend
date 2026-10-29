<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one page of a website shows from publish_at on: the page frozen as it was published
 * (snapshot: title, description, image, widgets and composite templates). The page shows its
 * latest release whose publish_at has come; one still to come is scheduled, and one with no
 * snapshot takes the page down. Restoring an old release publishes a copy of it.
 */
class SiteRelease extends Model
{
    protected $fillable = [
        'site_meta_id', 'param_id', 'page_meta_id', 'path', 'snapshot', 'hash', 'checks', 'note',
        'restored_from_id', 'publish_at', 'published_by',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'checks' => 'array',
        'publish_at' => 'datetime',
    ];

    protected $hidden = ['snapshot'];

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isLive(): bool
    {
        return $this->snapshot !== null;
    }
}
