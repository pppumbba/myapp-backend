<?php

namespace Tests\Unit;

use App\Support\Criteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CriteriaTest extends TestCase
{
    private array $vars = ['total_amount' => '7500000.00', 'created_user_id' => 5, 'sessionUserId' => 5, 'status' => 'draft', 'notes' => null];

    public function test_compares_record_fields_by_camel_or_snake_name(): void
    {
        $this->assertTrue(Criteria::passes('totalAmount > 100000', $this->vars));
        $this->assertFalse(Criteria::passes('total_amount <= 5000000', $this->vars));
        $this->assertTrue(Criteria::passes('sessionUserId == createdUserId', $this->vars));
        $this->assertTrue(Criteria::passes("status = 'draft' and not (totalAmount < 1000)", $this->vars));
        $this->assertTrue(Criteria::passes('totalAmount / 2 > 3000000 || false', $this->vars));
        $this->assertTrue(Criteria::passes('notes == null', $this->vars));
        $this->assertTrue(Criteria::passes('', $this->vars));
    }

    public function test_a_list_like_role_id_matches_when_it_holds_the_value(): void
    {
        $vars = ['roleId' => [1, 4], 'supplier_type_id' => '3'];

        $this->assertTrue(Criteria::passes('roleId == 4', $vars));
        $this->assertTrue(Criteria::passes('4 == roleId', $vars));
        $this->assertFalse(Criteria::passes('roleId == 2', $vars));
        $this->assertTrue(Criteria::passes('roleId != 2', $vars));
        $this->assertTrue(Criteria::passes('roleId == 2 || supplierTypeId == 3', $vars));
        $this->assertFalse(Criteria::passes('roleId == 1 && supplierTypeId == 2', ['roleId' => []] + $vars));

        $this->expectExceptionMessage('only be compared with == or !=');
        Criteria::passes('roleId > 1', $vars);
    }

    public function test_unknown_names_and_bad_syntax_say_what_is_wrong(): void
    {
        foreach (['amount > 5' => 'amount', 'totalAmount >' => 'ends too early', "status == 'draft" => 'closing quote', 'a ; b' => ';'] as $expr => $hint) {
            try {
                Criteria::passes($expr, $this->vars);
                $this->fail("{$expr} should not pass");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($hint, $e->getMessage());
            }
        }

        Criteria::check('anyName > 3 && other == "x"');
        $this->expectException(InvalidArgumentException::class);
        Criteria::check('(a > 1');
    }
}
