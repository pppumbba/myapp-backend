<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/** A login token; customer_id is the customer it works in. */
class PersonalAccessToken extends SanctumToken
{
    use OnMainSchema;
}
