<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A patch file installed here: what it did to each meta, so it can be undone (see App\Support\Patches). */
class PatchInstall extends Model
{
    public $timestamps = false;

    protected $fillable = ['patch_code', 'patch_name', 'description', 'hash', 'source', 'released_at', 'existing', 'result', 'package', 'installed_by', 'installed_at', 'rolled_back_at', 'rolled_back_by'];

    protected $casts = [
        'result' => 'array',
        'released_at' => 'datetime',
        'installed_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    protected $hidden = ['package'];

    public function installer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function rollbacker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rolled_back_by');
    }
}
