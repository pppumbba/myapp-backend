<?php

namespace App\Support\Market\Khur;

/** ХУР, the government data exchange: what the legal entity register says about a company. */
interface KhurDriver
{
    /**
     * The company with this registry number, or null when ХУР has none.
     *
     * @return array{registry_number: string, name: string, status: string, director_registry_number: ?string, director_name: ?string}|null
     */
    public function company(string $registryNumber): ?array;
}
