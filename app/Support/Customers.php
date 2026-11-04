<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;

/** Customers a person is in, and what happens when one is made, joined or left. */
class Customers
{
    /**
     * The person's customers as the app lists them; is_admin is looked up in each one's schema.
     *
     * @return list<array{id: int, code: string, name: string, is_admin: bool}>
     */
    public static function listFor(User $user): array
    {
        return $user->customers()->get()->map(fn (Customer $customer) => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'is_admin' => CustomerSchema::run($customer, fn () => $user->isAdmin()),
        ])->all();
    }
}
