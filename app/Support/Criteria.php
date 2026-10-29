<?php

namespace App\Support;

use App\Models\User;
use InvalidArgumentException;

/**
 * Checks a workflow transition's criteria, e.g. "totalAmount > 100000" or
 * "sessionUserId == createdUserId", against a record and the session.
 *
 * It reads the expression itself and never runs it as PHP or SQL. It understands numbers,
 * 'text' or "text", true/false/null, names, ( ), the comparisons == != <> > < >= <= (a single
 * = means ==), && || ! (also and/or/not), and + - * /. A name is looked up as written, then
 * as snake_case ("totalAmount" finds total_amount), then ignoring case. A name can hold a
 * list (roleId: every role the user has); == then means "is in the list" and != "is not".
 * Mistakes throw InvalidArgumentException with a message a person can act on.
 */
class Criteria
{
    private array $tokens = [];

    private int $pos = 0;

    /** @param array<string, mixed> $vars */
    private function __construct(private array $vars, private bool $lenient) {}

    /** Whether the expression holds. An empty expression always does. */
    public static function passes(?string $expression, array $vars): bool
    {
        if (trim((string) $expression) === '') {
            return true;
        }

        return (bool) (new self($vars, false))->run((string) $expression);
    }

    /**
     * What every criteria can use about the person: sessionUserId/Name/Email, roleId (the
     * list of their role ids), today, now, and their session values.
     *
     * @return array<string, mixed>
     */
    public static function userVars(?User $user): array
    {
        return [
            'sessionUserId' => $user?->id,
            'sessionUserName' => $user?->name,
            'sessionUserEmail' => $user?->email,
            'roleId' => $user?->roleIds() ?? [],
            'today' => now()->toDateString(),
            'now' => now()->toDateTimeString(),
        ] + SessionValues::for($user);
    }

    /** Throws if the expression can't be read; unknown names are fine here (they depend on the record). */
    public static function check(?string $expression): void
    {
        if (trim((string) $expression) !== '') {
            (new self([], true))->run((string) $expression);
        }
    }

    private function run(string $expression): mixed
    {
        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;
        $value = $this->orExpr();
        if ($this->peek() !== null) {
            throw new InvalidArgumentException("Unexpected \"{$this->peek()[1]}\" in the criteria.");
        }

        return $value;
    }

    /** @return array<int, array{0: string, 1: mixed}> [kind, value] pairs: num, str, name, op */
    private function tokenize(string $s): array
    {
        $tokens = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                preg_match('/\d*\.?\d+/A', $s, $m, 0, $i);
                $tokens[] = ['num', $m[0] + 0];
                $i += strlen($m[0]);
            } elseif ($c === "'" || $c === '"') {
                $end = strpos($s, $c, $i + 1);
                if ($end === false) {
                    throw new InvalidArgumentException('A text value in the criteria is missing its closing quote.');
                }
                $tokens[] = ['str', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
            } elseif (ctype_alpha($c) || $c === '_') {
                preg_match('/[A-Za-z_]\w*/A', $s, $m, 0, $i);
                $word = strtolower($m[0]);
                $tokens[] = match ($word) {
                    'and' => ['op', '&&'],
                    'or' => ['op', '||'],
                    'not' => ['op', '!'],
                    'true' => ['lit', true],
                    'false' => ['lit', false],
                    'null' => ['lit', null],
                    default => ['name', $m[0]],
                };
                $i += strlen($m[0]);
            } elseif (preg_match('/==|!=|<>|>=|<=|&&|\|\||[=<>!()+\-*\/]/A', $s, $m, 0, $i)) {
                $tokens[] = ['op', $m[0] === '=' ? '==' : ($m[0] === '<>' ? '!=' : $m[0])];
                $i += strlen($m[0]);
            } else {
                throw new InvalidArgumentException("The criteria can't use \"{$c}\".");
            }
        }

        return $tokens;
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function accept(string ...$ops): ?string
    {
        $t = $this->peek();
        if ($t && $t[0] === 'op' && in_array($t[1], $ops, true)) {
            $this->pos++;

            return $t[1];
        }

        return null;
    }

    private function orExpr(): mixed
    {
        $left = $this->andExpr();
        while ($this->accept('||')) {
            $right = $this->andExpr();
            $left = (bool) $left || (bool) $right;
        }

        return $left;
    }

    private function andExpr(): mixed
    {
        $left = $this->notExpr();
        while ($this->accept('&&')) {
            $right = $this->notExpr();
            $left = (bool) $left && (bool) $right;
        }

        return $left;
    }

    private function notExpr(): mixed
    {
        return $this->accept('!') ? ! $this->notExpr() : $this->comparison();
    }

    private function comparison(): mixed
    {
        $left = $this->sum();
        $op = $this->accept('==', '!=', '>', '<', '>=', '<=');
        if (! $op) {
            return $left;
        }
        $right = $this->sum();
        if ($this->lenient) {
            return true;
        }

        if ($op === '==' || $op === '!=') {
            $equal = match (true) {
                is_array($left) && is_array($right) => self::any($left, fn ($l) => self::any($right, fn ($r) => self::same($l, $r))),
                is_array($left) => self::any($left, fn ($l) => self::same($l, $right)),
                is_array($right) => self::any($right, fn ($r) => self::same($left, $r)),
                default => self::same($left, $right),
            };

            return $op === '==' ? $equal : ! $equal;
        }
        if (is_array($left) || is_array($right)) {
            throw new InvalidArgumentException('A list (like roleId) can only be compared with == or !=.');
        }

        $order = is_numeric($left) && is_numeric($right) ? (float) $left <=> (float) $right : strcmp((string) $left, (string) $right);

        return match ($op) {
            '>' => $order > 0,
            '<' => $order < 0,
            '>=' => $order >= 0,
            '<=' => $order <= 0,
        };
    }

    private static function any(array $list, callable $test): bool
    {
        foreach ($list as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }

    private static function same(mixed $left, mixed $right): bool
    {
        return match (true) {
            // null matches only null or empty text, never 0.
            $left === null || $right === null => ($left ?? '') === ($right ?? ''),
            is_numeric($left) && is_numeric($right) => (float) $left === (float) $right,
            is_bool($left) || is_bool($right) => (bool) $left === (bool) $right,
            default => (string) $left === (string) $right,
        };
    }

    private function sum(): mixed
    {
        $left = $this->product();
        while ($op = $this->accept('+', '-')) {
            $right = $this->product();
            $left = $this->lenient ? 0 : ($op === '+' ? $this->number($left) + $this->number($right) : $this->number($left) - $this->number($right));
        }

        return $left;
    }

    private function product(): mixed
    {
        $left = $this->unary();
        while ($op = $this->accept('*', '/')) {
            $right = $this->unary();
            if ($this->lenient) {
                $left = 0;
            } elseif ($op === '*') {
                $left = $this->number($left) * $this->number($right);
            } else {
                $divisor = $this->number($right);
                if ($divisor == 0) {
                    throw new InvalidArgumentException('The criteria divides by zero.');
                }
                $left = $this->number($left) / $divisor;
            }
        }

        return $left;
    }

    private function unary(): mixed
    {
        return $this->accept('-') ? ($this->lenient ? 0 : -$this->number($this->unary())) : $this->primary();
    }

    private function primary(): mixed
    {
        $t = $this->peek();
        if (! $t) {
            throw new InvalidArgumentException('The criteria ends too early.');
        }
        $this->pos++;

        if ($t[0] === 'op' && $t[1] === '(') {
            $value = $this->orExpr();
            if (! $this->accept(')')) {
                throw new InvalidArgumentException('The criteria is missing a closing ")".');
            }

            return $value;
        }

        return match ($t[0]) {
            'num', 'str', 'lit' => $t[1],
            'name' => $this->lookup($t[1]),
            default => throw new InvalidArgumentException("Unexpected \"{$t[1]}\" in the criteria."),
        };
    }

    private function lookup(string $name): mixed
    {
        if ($this->lenient) {
            return null;
        }
        if (array_key_exists($name, $this->vars)) {
            return $this->vars[$name];
        }
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if (array_key_exists($snake, $this->vars)) {
            return $this->vars[$snake];
        }
        foreach ($this->vars as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        throw new InvalidArgumentException("The criteria uses \"{$name}\", which this record doesn't have.");
    }

    private function number(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The criteria does arithmetic on something that is not a number.');
        }

        return (float) $value;
    }
}
