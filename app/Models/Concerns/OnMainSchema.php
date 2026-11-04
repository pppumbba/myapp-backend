<?php

namespace App\Models\Concerns;

/**
 * A model whose table lives in the main schema (users, logins, customers), whichever customer's
 * schema the request works in.
 */
trait OnMainSchema
{
    public function initializeOnMainSchema(): void
    {
        $this->setConnection('main');
    }
}
