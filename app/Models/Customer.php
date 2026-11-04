<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;

/**
 * An organisation using the platform. Its metas and data live in its own schema (schema_name);
 * code is used in public addresses. files_folder null keeps files in the root (the first customer).
 */
class Customer extends Model
{
    use OnMainSchema;

    protected $fillable = ['code', 'name', 'schema_name', 'files_folder', 'created_by'];
}
