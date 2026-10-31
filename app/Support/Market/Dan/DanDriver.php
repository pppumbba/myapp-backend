<?php

namespace App\Support\Market\Dan;

/** DAN, the national sign-in: where to send a person, and who came back. */
interface DanDriver
{
    /** The page that signs the person in and comes back to /auth/dan/callback with this state. */
    public function authorizeUrl(string $state): string;

    /**
     * The person DAN signed in, from the code it came back with.
     *
     * @return array{registry_number: string, name: string}
     *
     * @throws \RuntimeException when the code is not one DAN gave
     */
    public function citizen(string $code): array;
}
