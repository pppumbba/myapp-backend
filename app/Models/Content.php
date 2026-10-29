<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An uploaded file (see App\Support\Contents); content_map ties it to records. */
class Content extends Model
{
    protected $fillable = ['physical_path', 'file_name', 'file_extension', 'file_size', 'description', 'type_id', 'is_public', 'created_by'];

    protected $hidden = ['physical_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'type_id' => 'integer', 'is_public' => 'boolean', 'created_by' => 'integer'];
    }
}
