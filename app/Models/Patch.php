<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of metas to ship to other installations (see App\Support\Patches). A draft's
 * metas can change; released, its file (package) stays as it was.
 */
class Patch extends Model
{
    protected $fillable = ['code', 'name', 'description', 'with_used', 'status', 'package', 'hash', 'released_at', 'released_by', 'created_by'];

    protected $casts = [
        'with_used' => 'boolean',
        'released_at' => 'datetime',
    ];

    protected $hidden = ['package'];

    public function metas(): BelongsToMany
    {
        return $this->belongsToMany(Meta::class, 'patch_metas');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }
}
