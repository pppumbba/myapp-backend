<?php

namespace App\Models\Concerns;

/**
 * A model of a customer's schema that main-schema models (User) reach through relations. Without a
 * connection of its own it would take User's ("main") on those relations.
 */
trait OnCustomerSchema
{
    public function initializeOnCustomerSchema(): void
    {
        $this->setConnection('customer');
    }
}
