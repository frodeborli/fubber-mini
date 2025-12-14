<?php

namespace mini\Table;

use Closure;
use Traversable;

/**
 * Base class for table wrappers that delegate to a source table
 *
 * By default, all methods delegate to the source. Subclasses override
 * specific methods to add behavior (filtering, projection, etc.).
 *
 * Filter methods (eq, lt, etc.) push down to source and wrap the result
 * in a clone of this wrapper, preserving the wrapper chain.
 */
abstract class AbstractTableWrapper extends AbstractTable
{

    protected AbstractTable $source;

    public function __construct(
         AbstractTable $source,
    ) {
        parent::__construct(...array_values($source->getColumns()));
        $this->source = $source;
    }

    /**
     * Get the source table this wrapper delegates to
     */
    public function getSource(): AbstractTable
    {
        return $this->source;
    }

    // -------------------------------------------------------------------------
    // Delegation - override in subclasses as needed
    // -------------------------------------------------------------------------

    protected function materialize(string ...$additionalColumns): Traversable
    {
        return $this->source->materialize(...$additionalColumns);
    }

    public function getColumns(): array
    {
        return $this->source->getColumns();
    }

    public function getAllColumns(): array
    {
        return $this->source->getAllColumns();
    }

    public function columns(string ...$columns): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->columns(...$columns);
        return $c;
    }

    public function count(): int
    {
        return $this->source->count();
    }

    protected function getCompareFn(): Closure
    {
        return $this->source->getCompareFn();
    }

    // -------------------------------------------------------------------------
    // Filter methods - push down to source unless overridden
    // -------------------------------------------------------------------------

    public function eq(string $column, int|float|string|null $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->eq($column, $value);
        return $c;
    }

    public function lt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->lt($column, $value);
        return $c;
    }

    public function lte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->lte($column, $value);
        return $c;
    }

    public function gt(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->gt($column, $value);
        return $c;
    }

    public function gte(string $column, int|float|string $value): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->gte($column, $value);
        return $c;
    }

    public function in(string $column, SetInterface $values): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->in($column, $values);
        return $c;
    }

    public function like(string $column, string $pattern): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->like($column, $pattern);
        return $c;
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    public function order(?string $spec): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->order($spec);
        return $c;
    }

    public function or(TableInterface ...$predicates): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->or(...$predicates);
        return $c;
    }

    public function limit(?int $n): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->limit($n);
        return $c;
    }
}
