<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a category's spec template. */
class MarketCategorySpec extends Model
{
    protected $fillable = ['category_id', 'position', 'label', 'kind', 'operator', 'value', 'unit', 'must'];

    protected function casts(): array
    {
        return [
            'must' => 'boolean',
        ];
    }
}
