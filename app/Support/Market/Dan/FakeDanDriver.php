<?php

namespace App\Support\Market\Dan;

use RuntimeException;

/**
 * DAN for development: a page of test people (config market.fake.citizens) to pick from;
 * the code is "fake-" and the picked registry number.
 */
class FakeDanDriver implements DanDriver
{
    public function authorizeUrl(string $state): string
    {
        return url('/auth/dan/fake').'?'.http_build_query(['state' => $state]);
    }

    public function citizen(string $code): array
    {
        $number = str_starts_with($code, 'fake-') ? substr($code, 5) : null;
        foreach (self::citizens() as $citizen) {
            if ($citizen['registry_number'] === $number) {
                return $citizen;
            }
        }

        throw new RuntimeException('DAN does not know this code.');
    }

    /** @return list<array{registry_number: string, name: string}> */
    public static function citizens(): array
    {
        return config('market.fake.citizens', []);
    }
}
