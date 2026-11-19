<?php

namespace App\Support;

use App\Models\MetaParam;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Calculated fields: a param's expression_string holds a formula, e.g. "quantity * unit_price",
 * "round(sum(lines.amount) * 1.1, 2)" or "if(status = 'paid', 0, total - paid)". Forms work it
 * out as people type (render/formula.ts, the same rules) and every save works it out again here,
 * so the stored value is always the formula's, whatever was sent.
 *
 * Like Criteria it reads the formula and never runs it as PHP or SQL. It knows numbers, 'text'
 * or "text", true/false/null, field names (as written, as snake_case, or ignoring case),
 * ( ), + - * / %, the comparisons = == != <> > < >= <=, && || ! (and/or/not), and functions:
 *
 *   if(test, then, else)  round(x, digits)  floor(x)  ceil(x)  abs(x)  min(...)  max(...)
 *   coalesce(...)  concat(...)  today()  days(from, to)  adddays(date, n)
 *   sum(lines.field)  avg(lines.field)  min(lines.field)  max(lines.field)  count(lines)
 *
 * where "lines" is a detail's field (its lines) and parent.field is the record a line is on.
 * Blank counts as 0 in arithmetic; + joins text when either side is text; dividing by zero
 * gives blank. Mistakes throw InvalidArgumentException with a message a person can act on.
 */
class Formula
{
    private const FUNCTIONS = ['if', 'round', 'floor', 'ceil', 'abs', 'min', 'max', 'coalesce', 'concat', 'today', 'days', 'adddays', 'sum', 'avg', 'count'];

    private const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count'];

    /** @var array<int, array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    /** A param's formula, or null when it has none. */
    public static function of(MetaParam $param): ?string
    {
        $formula = trim((string) $param->expression_string);

        return $formula === '' || in_array($param->data_type, ['detail', 'subgrid', 'singlefile', 'multifile', 'document', 'signature'], true) ? null : $formula;
    }

    /**
     * Throws if the formula can't be read, or uses a name that isn't among $names (the form's
     * fields) or a detail that isn't among $details (with its lines' fields).
     *
     * @param  list<string>  $names
     * @param  array<string, list<string>>  $details
     */
    public static function check(string $formula, array $names = [], array $details = [], bool $line = false): void
    {
        $ast = (new self)->parse($formula);
        if ($names === [] && $details === []) {
            return;
        }
        self::walk($ast, function (array $node, bool $inAggregate) use ($names, $details, $line) {
            if ($node[0] !== 'name') {
                return;
            }
            [$head, $tail] = array_pad(explode('.', $node[1], 2), 2, null);
            if ($tail === null) {
                if ($inAggregate && self::find($details, $head) !== null) {
                    return;
                }
                if (self::find(array_flip($names), $head) === null) {
                    throw new InvalidArgumentException(__('The formula uses ":name", which this form doesn\'t have.', ['name' => $head]));
                }
            } elseif (strtolower($head) === 'parent') {
                if (! $line) {
                    throw new InvalidArgumentException(__('Only a line\'s formula can use parent.:field.', ['field' => $tail]));
                }
            } else {
                $fields = self::find($details, $head);
                if ($fields === null) {
                    throw new InvalidArgumentException(__('The formula uses ":name", which this form doesn\'t have.', ['name' => $head]));
                }
                if (! $inAggregate) {
                    throw new InvalidArgumentException(__('Lines add up with a function, e.g. sum(:name).', ['name' => $node[1]]));
                }
                if (self::find(array_flip($fields), $tail) === null) {
                    throw new InvalidArgumentException(__('The lines of ":detail" have no ":name".', ['detail' => $head, 'name' => $tail]));
                }
            }
        });
    }

    /**
     * Works out a value: $vars are the record's fields, $lines each detail's lines (rows as
     * arrays) by detail field, $parent the record a line is on.
     */
    public static function evaluate(string $formula, array $vars, array $lines = [], ?array $parent = null): mixed
    {
        $self = new self;

        return $self->run($self->parse($formula), $vars, $lines, $parent);
    }

    /**
     * A save's values with every calculated field worked out: each detail's lines first (their
     * own lines before them), then the record, which may add the lines up. $before is the
     * record as stored (an update): fields and lines not sent count as they are.
     *
     * @param  array<int, array>  $details  as MetaDataController::details() gives them
     * @return array{0: array, 1: array} [$data, $lines]
     */
    public static function apply(Collection $params, array $columns, array $details, array $data, array $lines, ?object $before = null, ?int $id = null): array
    {
        $vars = array_merge($before ? (array) $before : [], $data);
        $lines = self::applyLines($details, $lines, $vars);

        $formulas = $params->filter(fn (MetaParam $p) => self::of($p) !== null && in_array($p->field_path, $columns, true));
        if ($formulas->isEmpty()) {
            return [$data, $lines];
        }

        // The lines the record's formulas add up: those sent, else those stored.
        $all = [];
        foreach ($details as $detail) {
            $all[$detail['field']] = array_key_exists($detail['field'], $lines)
                ? $lines[$detail['field']]
                : ($id ? DB::table($detail['source']['table'])->where($detail['foreign_key'], $id)->get()->map(fn ($r) => (array) $r)->all() : []);
        }

        foreach (self::solve($formulas, $vars, $all, null) as $field => $value) {
            $data[$field] = $value;
        }

        return [$data, $lines];
    }

    /** Each sent line with its calculated fields, its own lines worked out first. */
    private static function applyLines(array $details, array $lines, array $parent): array
    {
        foreach ($details as $detail) {
            if (! array_key_exists($detail['field'], $lines)) {
                continue;
            }
            $formulas = $detail['source']['params']->filter(fn (MetaParam $p) => self::of($p) !== null && in_array($p->field_path, $detail['source']['columns'], true));
            foreach ($lines[$detail['field']] as $i => $line) {
                $line = self::applyLines($detail['details'], $line, $line);
                $sub = array_intersect_key($line, array_flip(array_column($detail['details'], 'field')));
                if ($formulas->isNotEmpty()) {
                    $line = array_merge($line, self::solve($formulas, $line, $sub, $parent));
                }
                $lines[$detail['field']][$i] = $line;
            }
        }

        return $lines;
    }

    /**
     * The calculated fields' values, in passes until they settle, so one formula can use
     * another whatever their order.
     *
     * @return array<string, mixed>
     */
    private static function solve(Collection $formulas, array $vars, array $lines, ?array $parent): array
    {
        $out = [];
        for ($pass = 0; $pass <= $formulas->count(); $pass++) {
            $changed = false;
            foreach ($formulas as $param) {
                try {
                    $value = self::cast($param, self::evaluate(self::of($param), $vars, $lines, $parent));
                } catch (InvalidArgumentException $e) {
                    abort(422, __('The formula of ":field": :error', ['field' => $param->label_name ?: $param->field_path, 'error' => $e->getMessage()]));
                }
                if (! array_key_exists($param->field_path, $out) || $out[$param->field_path] !== $value) {
                    $changed = true;
                }
                $out[$param->field_path] = $vars[$param->field_path] = $value;
            }
            if (! $changed) {
                break;
            }
        }

        return $out;
    }

    /** A formula's result as its field stores it. */
    public static function cast(MetaParam $param, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($param->data_type) {
            'integer' => is_numeric($value) || is_bool($value) ? (int) round((float) $value) : null,
            'decimal' => is_numeric($value) || is_bool($value) ? round((float) $value, 6) : null,
            'boolean' => (bool) $value,
            'date' => substr((string) $value, 0, 10),
            default => self::text($value),
        };
    }

    // Reading -----------------------------------------------------------------------------

    private function parse(string $formula): array
    {
        $this->tokens = $this->tokenize($formula);
        $this->pos = 0;
        if ($this->tokens === []) {
            throw new InvalidArgumentException(__('The formula is empty.'));
        }
        $ast = $this->orExpr();
        if ($this->peek() !== null) {
            throw new InvalidArgumentException(__('Unexpected ":token" in the formula.', ['token' => $this->peek()[1]]));
        }

        return $ast;
    }

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
                    throw new InvalidArgumentException(__('A text in the formula is missing its closing quote.'));
                }
                $tokens[] = ['str', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
            } elseif (ctype_alpha($c) || $c === '_') {
                preg_match('/[A-Za-z_]\w*(\.[A-Za-z_]\w*)?/A', $s, $m, 0, $i);
                $tokens[] = match (strtolower($m[0])) {
                    'and' => ['op', '&&'],
                    'or' => ['op', '||'],
                    'not' => ['op', '!'],
                    'true' => ['lit', true],
                    'false' => ['lit', false],
                    'null' => ['lit', null],
                    default => ['name', $m[0]],
                };
                $i += strlen($m[0]);
            } elseif (preg_match('/==|!=|<>|>=|<=|&&|\|\||[=<>!()+\-*\/%,]/A', $s, $m, 0, $i)) {
                $tokens[] = ['op', $m[0] === '=' ? '==' : ($m[0] === '<>' ? '!=' : $m[0])];
                $i += strlen($m[0]);
            } else {
                throw new InvalidArgumentException(__('The formula can\'t use ":char".', ['char' => $c]));
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

    private function orExpr(): array
    {
        $left = $this->andExpr();
        while ($op = $this->accept('||')) {
            $left = ['bin', $op, $left, $this->andExpr()];
        }

        return $left;
    }

    private function andExpr(): array
    {
        $left = $this->notExpr();
        while ($op = $this->accept('&&')) {
            $left = ['bin', $op, $left, $this->notExpr()];
        }

        return $left;
    }

    private function notExpr(): array
    {
        return $this->accept('!') ? ['un', '!', $this->notExpr()] : $this->comparison();
    }

    private function comparison(): array
    {
        $left = $this->sum();
        $op = $this->accept('==', '!=', '>', '<', '>=', '<=');

        return $op ? ['bin', $op, $left, $this->sum()] : $left;
    }

    private function sum(): array
    {
        $left = $this->product();
        while ($op = $this->accept('+', '-')) {
            $left = ['bin', $op, $left, $this->product()];
        }

        return $left;
    }

    private function product(): array
    {
        $left = $this->unary();
        while ($op = $this->accept('*', '/', '%')) {
            $left = ['bin', $op, $left, $this->unary()];
        }

        return $left;
    }

    private function unary(): array
    {
        return $this->accept('-') ? ['un', '-', $this->unary()] : $this->primary();
    }

    private function primary(): array
    {
        $t = $this->peek();
        if (! $t) {
            throw new InvalidArgumentException(__('The formula ends too early.'));
        }
        $this->pos++;

        if ($t[0] === 'op' && $t[1] === '(') {
            $inner = $this->orExpr();
            if (! $this->accept(')')) {
                throw new InvalidArgumentException(__('The formula is missing a closing ")".'));
            }

            return $inner;
        }
        if ($t[0] === 'name' && $this->accept('(')) {
            $fn = strtolower($t[1]);
            if (! in_array($fn, self::FUNCTIONS, true)) {
                throw new InvalidArgumentException(__('The formula has no function ":name".', ['name' => $t[1]]));
            }
            $args = [];
            if (! $this->accept(')')) {
                do {
                    $args[] = $this->orExpr();
                } while ($this->accept(','));
                if (! $this->accept(')')) {
                    throw new InvalidArgumentException(__('The formula is missing a closing ")".'));
                }
            }
            self::arity($fn, count($args));

            return ['call', $fn, $args];
        }

        return match ($t[0]) {
            'num', 'str', 'lit' => [$t[0], $t[1]],
            'name' => ['name', $t[1]],
            default => throw new InvalidArgumentException(__('Unexpected ":token" in the formula.', ['token' => $t[1]])),
        };
    }

    private static function arity(string $fn, int $n): void
    {
        [$min, $max] = match ($fn) {
            'if' => [3, 3],
            'round' => [1, 2],
            'floor', 'ceil', 'abs', 'sum', 'avg', 'count' => [1, 1],
            'today' => [0, 0],
            'days', 'adddays' => [2, 2],
            default => [1, PHP_INT_MAX],
        };
        if ($n < $min || $n > $max) {
            throw new InvalidArgumentException(__(':name() takes :count.', ['name' => $fn, 'count' => $min === $max ? $min : ($max === PHP_INT_MAX ? "{$min}+" : "{$min}–{$max}")]));
        }
    }

    /** Visits every node, telling whether it is the argument of an aggregate. */
    private static function walk(array $node, callable $visit, bool $inAggregate = false): void
    {
        $visit($node, $inAggregate);
        match ($node[0]) {
            'un' => self::walk($node[2], $visit),
            'bin' => [self::walk($node[2], $visit), self::walk($node[3], $visit)],
            'call' => array_map(fn ($a) => self::walk($a, $visit, in_array($node[1], self::AGGREGATES, true) && count($node[2]) === 1 && $a[0] === 'name'), $node[2]),
            default => null,
        };
    }

    // Working out ---------------------------------------------------------------------------

    private function run(array $node, array $vars, array $lines, ?array $parent): mixed
    {
        $run = fn (array $n) => $this->run($n, $vars, $lines, $parent);

        switch ($node[0]) {
            case 'num':
            case 'str':
            case 'lit':
                return $node[1];
            case 'name':
                [$head, $tail] = array_pad(explode('.', $node[1], 2), 2, null);
                if ($tail !== null && strtolower($head) === 'parent') {
                    return self::lookup($parent ?? [], $tail, true);
                }
                if ($tail !== null) {
                    throw new InvalidArgumentException(__('Lines add up with a function, e.g. sum(:name).', ['name' => $node[1]]));
                }

                return self::lookup($vars, $head);
            case 'un':
                $value = $run($node[2]);

                return $node[1] === '!' ? ! self::truthy($value) : (($n = self::number($value)) === null ? null : -$n);
            case 'bin':
                return $this->binary($node[1], $node[2], $node[3], $run);
            case 'call':
                return $this->call($node[1], $node[2], $run, $lines);
        }

        return null;
    }

    private function binary(string $op, array $l, array $r, callable $run): mixed
    {
        if ($op === '&&') {
            return self::truthy($run($l)) && self::truthy($run($r));
        }
        if ($op === '||') {
            return self::truthy($run($l)) || self::truthy($run($r));
        }
        $left = $run($l);
        $right = $run($r);

        switch ($op) {
            case '+':
                return self::numberish($left) && self::numberish($right)
                    ? (self::number($left) ?? 0) + (self::number($right) ?? 0)
                    : self::text($left).self::text($right);
            case '-':
                return (self::arith($left) ?? 0) - (self::arith($right) ?? 0);
            case '*':
                return (self::arith($left) ?? 0) * (self::arith($right) ?? 0);
            case '/':
            case '%':
                $d = self::arith($right) ?? 0;
                if ((float) $d === 0.0) {
                    return null;
                }

                $n = self::arith($left) ?? 0;
                if ($op === '/') {
                    return $n / $d;
                }

                return is_int($n) && is_int($d) ? $n % $d : fmod((float) $n, (float) $d);
            case '==':
            case '!=':
                $same = self::same($left, $right);

                return $op === '==' ? $same : ! $same;
            default:
                $order = self::numberish($left) && self::numberish($right) && ! self::blank($left) && ! self::blank($right)
                    ? self::number($left) <=> self::number($right)
                    : strcmp(self::text($left), self::text($right));

                return match ($op) {
                    '>' => $order > 0,
                    '<' => $order < 0,
                    '>=' => $order >= 0,
                    '<=' => $order <= 0,
                };
        }
    }

    private function call(string $fn, array $args, callable $run, array $lines): mixed
    {
        // sum(lines.amount), count(lines): over a detail's lines.
        if (in_array($fn, self::AGGREGATES, true) && count($args) === 1 && $args[0][0] === 'name') {
            [$head, $tail] = array_pad(explode('.', $args[0][1], 2), 2, null);
            $rows = self::find($lines, $head);
            if ($rows !== null && ($tail !== null || $fn === 'count')) {
                if ($fn === 'count' && $tail === null) {
                    return count($rows);
                }
                $values = array_values(array_filter(array_map(fn ($row) => self::number(self::lookup((array) $row, $tail, true)), $rows), fn ($v) => $v !== null));

                return match ($fn) {
                    'sum' => array_sum($values),
                    'count' => count($values),
                    'avg' => $values ? array_sum($values) / count($values) : null,
                    'min' => $values ? min($values) : null,
                    'max' => $values ? max($values) : null,
                };
            }
        }

        if ($fn === 'if') {
            return self::truthy($run($args[0])) ? $run($args[1]) : $run($args[2]);
        }
        $values = array_map($run, $args);

        switch ($fn) {
            case 'round':
                $x = self::arith($values[0]);
                if ($x === null) {
                    return null;
                }
                $digits = (int) (self::arith($values[1] ?? 0) ?? 0);

                return round($x, $digits);
            case 'floor':
            case 'ceil':
            case 'abs':
                $x = self::arith($values[0]);

                return $x === null ? null : $fn($x);
            case 'min':
            case 'max':
                $numbers = array_values(array_filter(array_map(fn ($v) => self::arith($v), $values), fn ($v) => $v !== null));

                return $numbers ? $fn($numbers) : null;
            case 'coalesce':
                foreach ($values as $v) {
                    if (! self::blank($v)) {
                        return $v;
                    }
                }

                return null;
            case 'concat':
                return implode('', array_map(fn ($v) => self::text($v), $values));
            case 'today':
                return now()->toDateString();
            case 'days':
                $from = self::date($values[0]);
                $to = self::date($values[1]);

                return $from && $to ? (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) : null;
            case 'adddays':
                $date = self::date($values[0]);
                $n = self::arith($values[1]);

                return $date && $n !== null ? $date->modify(((int) round($n) >= 0 ? '+' : '').((int) round($n)).' days')->format('Y-m-d') : null;
            case 'sum':
            case 'avg':
            case 'count':
                throw new InvalidArgumentException(__(':name() adds up lines, e.g. :name(lines.amount).', ['name' => $fn]));
        }

        return null;
    }

    // Values ------------------------------------------------------------------------------

    /** A name's value: as written, as snake_case, or ignoring case. */
    private static function lookup(array $vars, string $name, bool $lenient = false): mixed
    {
        $key = self::find(array_combine(array_keys($vars), array_keys($vars)), $name);
        if ($key === null) {
            if ($lenient) {
                return null;
            }
            throw new InvalidArgumentException(__('The formula uses ":name", which this form doesn\'t have.', ['name' => $name]));
        }

        return $vars[$key];
    }

    /** $map's entry for a name as written, as snake_case, or ignoring case. */
    private static function find(array $map, string $name): mixed
    {
        if (array_key_exists($name, $map)) {
            return $map[$name];
        }
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if (array_key_exists($snake, $map)) {
            return $map[$snake];
        }
        foreach ($map as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || $v === '';
    }

    private static function numberish(mixed $v): bool
    {
        return self::blank($v) || is_bool($v) || is_numeric($v);
    }

    private static function number(mixed $v): int|float|null
    {
        if (self::blank($v)) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }

        return is_numeric($v) ? $v + 0 : null;
    }

    /** A number for arithmetic: blank stays blank (0 to the caller), text is a mistake. */
    private static function arith(mixed $v): int|float|null
    {
        if (! self::numberish($v)) {
            throw new InvalidArgumentException(__('The formula does arithmetic on text (":value").', ['value' => self::text($v)]));
        }

        return self::number($v);
    }

    private static function truthy(mixed $v): bool
    {
        return ! self::blank($v) && $v !== false && $v !== 0 && $v !== 0.0 && $v !== '0' && $v !== 'false';
    }

    private static function same(mixed $l, mixed $r): bool
    {
        return match (true) {
            self::blank($l) || self::blank($r) => self::blank($l) && self::blank($r),
            is_bool($l) || is_bool($r) => self::truthy($l) === self::truthy($r),
            is_numeric($l) && is_numeric($r) => (float) $l === (float) $r,
            default => (string) $l === (string) $r,
        };
    }

    /** Text for joining: numbers without float noise, booleans as true/false. */
    private static function text(mixed $v): string
    {
        return match (true) {
            self::blank($v) => '',
            is_bool($v) => $v ? 'true' : 'false',
            is_float($v) => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.'),
            default => (string) $v,
        };
    }

    private static function date(mixed $v): ?DateTimeImmutable
    {
        $s = substr(self::text($v), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? (DateTimeImmutable::createFromFormat('!Y-m-d', $s, new DateTimeZone('UTC')) ?: null) : null;
    }
}
