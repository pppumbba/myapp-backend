<?php

namespace Tests\Unit;

use App\Support\Formula;
use InvalidArgumentException;
use Tests\TestCase;

class FormulaTest extends TestCase
{
    private array $vars = ['quantity' => '3', 'unit_price' => '1500.50', 'discount' => '', 'status' => 'paid', 'first_name' => 'Bat', 'due_date' => '2026-11-30'];

    public function test_arithmetic_reads_numbers_from_text_and_blank_as_zero(): void
    {
        $this->assertEqualsWithDelta(4501.5, Formula::evaluate('quantity * unitPrice - discount', $this->vars), 1e-9);
        $this->assertSame(1, Formula::evaluate('7 % 3', []));
        $this->assertNull(Formula::evaluate('quantity / discount', $this->vars));
    }

    public function test_plus_joins_text_and_functions_work(): void
    {
        $this->assertSame('Bat 3', Formula::evaluate("first_name + ' ' + quantity", $this->vars));
        $this->assertSame(0, Formula::evaluate("if(status = 'paid', 0, 10)", $this->vars));
        $this->assertSame(1500.5, Formula::evaluate('round(unit_price, 1)', $this->vars));
        $this->assertSame(2, Formula::evaluate('max(1, discount, 2)', $this->vars));
        $this->assertSame('n/a', Formula::evaluate("coalesce(discount, 'n/a')", $this->vars));
    }

    public function test_dates_count_and_add_days(): void
    {
        $this->assertSame(15, Formula::evaluate("days('2026-11-15', due_date)", $this->vars));
        $this->assertSame('2026-12-30', Formula::evaluate('adddays(due_date, 30)', $this->vars));
        $this->assertNull(Formula::evaluate('days(discount, due_date)', $this->vars));
    }

    public function test_lines_add_up_and_a_line_reads_its_parent(): void
    {
        $lines = ['lines' => [['amount' => '10'], ['amount' => '5.5'], ['amount' => null]]];

        $this->assertSame(15.5, Formula::evaluate('sum(lines.amount)', [], $lines));
        $this->assertSame(3, Formula::evaluate('count(lines)', [], $lines));
        $this->assertSame(2, Formula::evaluate('count(lines.amount)', [], $lines));
        $this->assertSame(10, Formula::evaluate('max(lines.amount)', [], $lines));
        $this->assertSame(30, Formula::evaluate('quantity * parent.rate', ['quantity' => 3], [], ['rate' => 10]));
    }

    public function test_check_names_what_is_wrong(): void
    {
        Formula::check('round(sum(lines.amount) * 1.1, 2)', ['total'], ['lines' => ['amount']]);
        $this->addToAssertionCount(1);

        foreach ([
            'quantity *' => 'ends too early',
            'price * 2' => '"price"',
            'lines.amount * 2' => 'sum(lines.amount)',
            'sum(lines.cost)' => 'no "cost"',
            'eval(1)' => 'no function "eval"',
            'round()' => 'takes 1–2',
            'parent.rate' => 'Only a line',
        ] as $formula => $message) {
            try {
                Formula::check($formula, ['quantity', 'total'], ['lines' => ['amount']]);
                $this->fail("{$formula} should be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $formula);
            }
        }
    }

    public function test_arithmetic_on_text_is_a_mistake(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Formula::evaluate('first_name * 2', $this->vars);
    }
}
