<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tender/listing category; its specs are the template new items start from. */
class MarketCategory extends Model
{
    protected $fillable = ['name', 'parent_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function specs(): HasMany
    {
        return $this->hasMany(MarketCategorySpec::class, 'category_id')->orderBy('position')->orderBy('id');
    }
}
