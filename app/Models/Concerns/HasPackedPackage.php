<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A patch file kept in the database, compressed: with its tables and rows it can outgrow what a
 * MySQL server takes in one query (max_allowed_packet, 1 MB on some). Files saved before this
 * are read as they are.
 */
trait HasPackedPackage
{
    private const PACKED = 'gz:';

    protected function package(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value !== null && str_starts_with($value, self::PACKED) ? gzdecode(base64_decode(substr($value, strlen(self::PACKED)))) : $value,
            set: fn (?string $value) => $value === null ? null : self::PACKED.base64_encode(gzencode($value, 9)),
        );
    }
}
