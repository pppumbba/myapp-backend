<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user follows a category to hear of new tenders in it. */
class CategoryFollow extends Model
{
    protected $table = 'market_category_follows';

    protected $fillable = ['user_id', 'category_id'];
}
