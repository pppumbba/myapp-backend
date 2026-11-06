<?php

namespace Tests\Feature\Market;

use App\Support\Market\Dan\DanDriver;
use RuntimeException;

/** DAN for tests: the code is "test-" and the registry number of one of these people. */
class TestDanDriver implements DanDriver
{
    /** @var list<array{registry_number: string, name: string}> */
    public const CITIZENS = [
        ['registry_number' => 'УБ90010112', 'name' => 'Бат-Эрдэнэ Ганбаатар'],
        ['registry_number' => 'УБ85051523', 'name' => 'Сарангэрэл Должин'],
        ['registry_number' => 'ДА92120334', 'name' => 'Тэмүүлэн Болд'],
    ];

    public function authorizeUrl(string $state): string
    {
        return 'https://dan.test/authorize?'.http_build_query(['state' => $state]);
    }

    public function citizen(string $code): array
    {
        $number = str_starts_with($code, 'test-') ? substr($code, 5) : null;
        foreach (self::CITIZENS as $citizen) {
            if ($citizen['registry_number'] === $number) {
                return $citizen;
            }
        }

        throw new RuntimeException('DAN does not know this code.');
    }
}
