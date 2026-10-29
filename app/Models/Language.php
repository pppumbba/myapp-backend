<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A language the app speaks: one is the source (labels are written in it), one the default for users who pick none. */
class Language extends Model
{
    protected $fillable = ['code', 'name', 'is_source', 'is_default', 'is_active', 'order_number'];

    protected function casts(): array
    {
        return ['is_source' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean', 'order_number' => 'integer'];
    }
}
