<?php

namespace mini\Table;

use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Types\Operator;

/**
 * Immutable predicate for filtering conditions
 *
 * A standalone class representing filter conditions that can be used with or().
 * Supports both concrete values and bind parameters.
 *
 * ```php
 * use const mini\p;
 *
 * // Use the p root instance helper for concise syntax
 * $users->or(
 *     p->eq('status', 'active'),
 *     p->gte('age', 65)
 * );
 *
 * // Chain multiple conditions (AND)
 * p->eq('status', 'active')->lt('age', 30)
 *
 * // With bind parameters
 * p->eqBind('id', ':id')->bind([':id' => 123])
 * ```
 */
final class Predicate
{
    /**
     * @var list<array{column: string, operator: Operator, value: mixed, bound: bool}>
     */
    private array $conditions = [];

    /** @var bool If true, test() always returns false */
    private bool $matchesNothing = false;

    /**
     * Create an empty predicate (matches everything)
     */
    public function __construct() {}

    /**
     * Create a predicate that matches nothing
     *
     * Used for SQL `col = NULL` which per SQL standard always evaluates to UNKNOWN,
     * meaning no rows should match.
     */
    public static function never(): self
    {
        $p = new self();
        $p->matchesNothing = true;
        return $p;
    }

    /**
     * Create a predicate builder for a table
     *
     * @param TableInterface $table The table context (for future type validation)
     */
    public static function from(TableInterface $table): self
    {
        return new self();
    }

    // -------------------------------------------------------------------------
    // Condition methods - return new Predicate with condition added
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Eq, 'value' => $value, 'bound' => true];
        return $new;
    }

    public function eqBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Eq, 'value' => $param, 'bound' => false];
        return $new;
    }

    public function lt(string $column, int|float|string $value): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Lt, 'value' => $value, 'bound' => true];
        return $new;
    }

    public function ltBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Lt, 'value' => $param, 'bound' => false];
        return $new;
    }

    public function lte(string $column, int|float|string $value): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Lte, 'value' => $value, 'bound' => true];
        return $new;
    }

    public function lteBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Lte, 'value' => $param, 'bound' => false];
        return $new;
    }

    public function gt(string $column, int|float|string $value): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Gt, 'value' => $value, 'bound' => true];
        return $new;
    }

    public function gtBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Gt, 'value' => $param, 'bound' => false];
        return $new;
    }

    public function gte(string $column, int|float|string $value): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Gte, 'value' => $value, 'bound' => true];
        return $new;
    }

    public function gteBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Gte, 'value' => $param, 'bound' => false];
        return $new;
    }

    public function in(string $column, SetInterface $values): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::In, 'value' => $values, 'bound' => true];
        return $new;
    }

    public function like(string $column, string $pattern): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Like, 'value' => $pattern, 'bound' => true];
        return $new;
    }

    public function likeBind(string $column, string $param): self
    {
        $new = clone $this;
        $new->conditions[] = ['column' => $column, 'operator' => Operator::Like, 'value' => $param, 'bound' => false];
        return $new;
    }

    // -------------------------------------------------------------------------
    // Bind parameter support
    // -------------------------------------------------------------------------

    /**
     * Check if all parameters are bound
     */
    public function isBound(): bool
    {
        foreach ($this->conditions as $cond) {
            if (!$cond['bound']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get list of unbound parameter names
     *
     * @return list<string>
     */
    public function getUnboundParams(): array
    {
        $params = [];
        foreach ($this->conditions as $cond) {
            if (!$cond['bound']) {
                $params[] = $cond['value'];
            }
        }
        return $params;
    }

    /**
     * Resolve bind parameters with concrete values
     *
     * @param array<string, mixed> $values Parameter name => value
     */
    public function bind(array $values): self
    {
        $new = clone $this;
        foreach ($new->conditions as $i => $cond) {
            if (!$cond['bound']) {
                $param = $cond['value'];
                if (!array_key_exists($param, $values)) {
                    continue; // Leave unbound for partial binding
                }
                $new->conditions[$i]['value'] = $values[$param];
                $new->conditions[$i]['bound'] = true;
            }
        }
        return $new;
    }

    // -------------------------------------------------------------------------
    // Condition access
    // -------------------------------------------------------------------------

    /**
     * Get all conditions
     *
     * @return list<array{column: string, operator: Operator, value: mixed, bound: bool}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Check if predicate has no conditions (matches everything)
     */
    public function isEmpty(): bool
    {
        return empty($this->conditions);
    }

    /**
     * Create a new predicate with column names mapped through a callback
     *
     * Used by AliasTable to translate aliased column names to original names.
     *
     * @param callable(string): string $mapper Function that maps column names
     */
    public function mapColumns(callable $mapper): self
    {
        $new = clone $this;
        foreach ($new->conditions as $i => $cond) {
            $new->conditions[$i]['column'] = $mapper($cond['column']);
        }
        return $new;
    }

    // -------------------------------------------------------------------------
    // Row testing
    // -------------------------------------------------------------------------

    /**
     * Test if a row matches all conditions
     *
     * Empty predicate (no conditions) matches everything.
     * Predicate::never() always returns false.
     *
     * @throws \LogicException If there are unbound parameters
     */
    public function test(object $row): bool
    {
        if ($this->matchesNothing) {
            return false;
        }

        foreach ($this->conditions as $cond) {
            if (!$cond['bound']) {
                throw new \LogicException("Predicate has unbound parameter '{$cond['value']}'");
            }
            if (!$this->testCondition($row, $cond['column'], $cond['operator'], $cond['value'])) {
                return false;
            }
        }
        return true;
    }

    private function testCondition(object $row, string $column, Operator $operator, mixed $value): bool
    {
        if (!property_exists($row, $column)) {
            return true; // Open world assumption
        }

        $rowValue = $row->$column;

        return match ($operator) {
            Operator::Eq => $this->compareEq($rowValue, $value),
            Operator::Lt => $rowValue !== null && $rowValue < $value,
            Operator::Lte => $rowValue !== null && $rowValue <= $value,
            Operator::Gt => $rowValue !== null && $rowValue > $value,
            Operator::Gte => $rowValue !== null && $rowValue >= $value,
            Operator::In => $this->testIn($rowValue, $column, $value),
            Operator::Like => $this->testLike($rowValue, $value),
        };
    }

    private function compareEq(mixed $rowValue, mixed $value): bool
    {
        if ($value === null) {
            return $rowValue === null;
        }
        // Use == for numeric comparison (5 == 5.0)
        if (is_numeric($rowValue) && is_numeric($value)) {
            return $rowValue == $value;
        }
        return $rowValue === $value;
    }

    private function testIn(mixed $rowValue, string $column, SetInterface $set): bool
    {
        $member = (object)[$column => $rowValue];
        return $set->has($member);
    }

    private function testLike(mixed $rowValue, string $pattern): bool
    {
        if ($rowValue === null) {
            return false;
        }
        $regex = '/^' . str_replace(
            ['%', '_'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/i';
        return preg_match($regex, (string)$rowValue) === 1;
    }
}
