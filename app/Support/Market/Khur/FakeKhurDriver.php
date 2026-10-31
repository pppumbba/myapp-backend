<?php

namespace App\Support\Market\Khur;

/** ХУР for development: answers from the test companies in config market.fake.companies. */
class FakeKhurDriver implements KhurDriver
{
    public function company(string $registryNumber): ?array
    {
        foreach (config('market.fake.companies', []) as $company) {
            if ($company['registry_number'] === $registryNumber) {
                return $company;
            }
        }

        return null;
    }
}
