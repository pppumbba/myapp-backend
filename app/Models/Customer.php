<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An organisation using the platform. Its metas and data live in its own schema (schema_name);
 * code is used in public addresses. files_folder null keeps files in the root (the first customer).
 */
class Customer extends Model
{
    use OnMainSchema;

    protected $fillable = ['code', 'name', 'schema_name', 'files_folder', 'created_by'];

    /** A code for a new customer from its name: acme-llc, then acme-llc-2, … */
    public static function makeCode(string $name): string
    {
        $base = Str::limit(Str::slug($name), 30, '') ?: 'customer';
        $code = $base;
        for ($n = 2; self::where('code', $code)->exists(); $n++) {
            $code = "{$base}-{$n}";
        }

        return $code;
    }
}
