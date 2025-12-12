<?php

namespace mini\Table;

use Traversable;

/**
 * Predicate table for building filter structures without data
 *
 * Captures filter operations by wrapping itself in FilteredTable/SortedTable.
 * Used to build query templates that can be combined with OR:
 *
 * ```php
 * $p = new Predicate(new ColumnDef('age'), new ColumnDef('status'));
 *
 * // Build predicate structures (no data, just filter chain)
 * $young = $p->lt('age', 30);
 * $senior = $p->gte('age', 65);
 *
 * // Apply to real table with OR
 * $table->or($young, $senior);
 *
 * // Complex: (status='active' AND age<30) OR (status='vip')
 * $table->or(
 *     $p->eq('status', 'active')->lt('age', 30),
 *     $p->eq('status', 'vip')
 * );
 * ```
 */
final class Predicate extends AbstractTable implements PredicateInterface
{
    public function __construct(ColumnDef ...$columns)
    {
        parent::__construct(...$columns);
    }

    /**
     * Create a Predicate with the same schema as another table
     */
    public static function from(TableInterface $source): self
    {
        return new self(...array_values($source->getColumns()));
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        // If the user tries to iterate a Predicate directly, something is wrong.
        throw new \LogicException(
            "A Predicate is a logic definition. It cannot be iterated directly. " .
            "Did you forget to apply it to a table using \$table->or(...)?"
        );    
    }

    public function count(): int
    {
        return 0;
    }

    public function exists(): bool
    {
        return false;
    }

    public function has(object $member): bool
    {
        return false;
    }

    public function test(object $row): bool
    {
        // Base predicate matches everything
        return true;
    }

    // -------------------------------------------------------------------------
    // Filter methods - wrap $this to capture structure
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Eq, $value);
    }

    public function lt(string $column, int|float|string $value): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Lt, $value);
    }

    public function lte(string $column, int|float|string $value): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Lte, $value);
    }

    public function gt(string $column, int|float|string $value): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Gt, $value);
    }

    public function gte(string $column, int|float|string $value): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Gte, $value);
    }

    public function in(string $column, SetInterface $values): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::In, $values);
    }

    public function like(string $column, string $pattern): PredicateInterface
    {
        return new FilteredTable($this, $column, Operator::Like, $pattern);
    }

    public function order(?string $spec): TableInterface
    {
        throw new \LogicException('Predicates cannot have ordering - use order() on the result table');
    }

    public function limit(?int $n): TableInterface
    {
        throw new \LogicException('Predicates cannot have limit - use limit() on the result table');
    }

    public function offset(int $n): TableInterface
    {
        throw new \LogicException('Predicates cannot have offset - use offset() on the result table');
    }

    public function or(TableInterface ...$predicates): TableInterface
    {
        throw new \LogicException('Predicates cannot have nested or() - use or() on the result table');
    }
}
