<?php

namespace mini\Table\Utility;

use mini\Table\AbstractTable;
use mini\Table\ColumnDef;
use mini\Table\Contracts\SetInterface;
use mini\Table\Contracts\TableInterface;
use mini\Table\Predicate;
use Traversable;

/**
 * Empty table with schema
 *
 * Represents an empty result while preserving schema information.
 * All filter operations return $this since filtering an empty set
 * yields an empty set.
 *
 * ```php
 * new EmptyTable(new ColumnDef('id'), new ColumnDef('name'))
 * EmptyTable::from($table)  // Empty result with $table's schema
 * ```
 */
final class EmptyTable extends AbstractTable
{
    use TablePropertiesTrait;
    
    public function __construct(ColumnDef ...$columns)
    {
        parent::__construct(...$columns);
    }

    public static function from(TableInterface $source): self
    {
        return new self(...array_values($source->getColumns()));
    }

    protected function materialize(string ...$additionalColumns): Traversable
    {
        return new \EmptyIterator();
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

    // Filter operations return $this - filtering empty is still empty
    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        return $this;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        return $this;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        return $this;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        return $this;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        return $this;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        return $this;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        return $this;
    }

    public function order(?string $spec): TableInterface
    {
        return $this;
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        // OR on empty table is still empty
        return $this;
    }
}
