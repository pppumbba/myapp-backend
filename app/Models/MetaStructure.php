<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The table a structure meta made, with its columns as last applied (name => spec, see Structure::spec). */
class MetaStructure extends Model
{
    protected $fillable = ['meta_id', 'table_name', 'columns', 'applied_at', 'applied_by'];

    protected $casts = ['columns' => 'array', 'applied_at' => 'datetime'];

    public function meta(): BelongsTo
    {
        return $this->belongsTo(Meta::class);
    }
}
