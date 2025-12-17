<?php

namespace mini\Table;

use Traversable;

/**
 * Final table wrapper with parameter binding support
 *
 * Provides a strict API around TableInterface with support for
 * bindable predicates, enabling reusable query templates:
 *
 * ```php
 * // Build reusable query with bindable params
 * $ordersByStatus = Table::from($orders)
 *     ->eqBind('status', ':status')
 *     ->columns('id', 'user_id');
 *
 * // Bind and execute
 * $shipped = $ordersByStatus->bind([':status' => 'shipped']);
 * $pending = $ordersByStatus->bind([':status' => 'pending']);
 *
 * // Use in subqueries (for correlated queries)
 * $userOrders = Table::from($orders)
 *     ->eqBind('user_id', ':uid')
 *     ->columns('id');
 *
 * foreach ($users as $user) {
 *     $bound = $userOrders->bind([':uid' => $user->id]);
 *     // $bound is now a SetInterface for this user's orders
 * }
 * ```
 *
 * Iterating a table with unbound parameters throws RuntimeException.
 */
final class Table implements TableInterface
{
    private TableInterface $source;

    /** @var array<string|int, list<array{string, string}>> param => [[column, operator], ...] */
    private array $binds = [];

    /** @var string[]|null Deferred column projection (applied at iteration) */
    private ?array $deferredColumns = null;

    private function __construct(TableInterface $source)
    {
        $this->source = $source;
    }

    /**
     * Create a Table wrapper from any TableInterface
     */
    public static function from(TableInterface $source): self
    {
        if ($source instanceof self) {
            return $source;
        }
        return new self($source);
    }

    // =========================================================================
    // Bindable predicates
    // =========================================================================

    /**
     * Add bindable equality predicate
     *
     * @param string $column Column to filter
     * @param string|int $param Parameter name (':name') or position (0, 1, ...)
     */
    public function eqBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'eq'];
        return $c;
    }

    /**
     * Add bindable less-than predicate
     */
    public function ltBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'lt'];
        return $c;
    }

    /**
     * Add bindable less-than-or-equal predicate
     */
    public function lteBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'lte'];
        return $c;
    }

    /**
     * Add bindable greater-than predicate
     */
    public function gtBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'gt'];
        return $c;
    }

    /**
     * Add bindable greater-than-or-equal predicate
     */
    public function gteBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'gte'];
        return $c;
    }

    /**
     * Add bindable LIKE predicate
     */
    public function likeBind(string $column, string|int $param): self
    {
        $c = clone $this;
        $c->binds[$param][] = [$column, 'like'];
        return $c;
    }

    /**
     * Bind parameter values
     *
     * Returns a new Table with the given parameters bound. The predicates
     * are applied to the underlying source immediately.
     *
     * The same parameter can be used in multiple predicates:
     * ```php
     * // WHERE price > :val AND cost < :val
     * $table->gtBind('price', ':val')->ltBind('cost', ':val')->bind([':val' => 100]);
     * ```
     *
     * @param array<string|int, mixed> $values Parameter values keyed by name or position
     * @throws \InvalidArgumentException If an unknown parameter is provided
     */
    public function bind(array $values): self
    {
        $c = clone $this;
        foreach ($values as $param => $value) {
            if (!isset($c->binds[$param])) {
                throw new \InvalidArgumentException("Unknown parameter: $param");
            }
            foreach ($c->binds[$param] as [$column, $op]) {
                $c->source = match ($op) {
                    'eq' => $c->source->eq($column, $value),
                    'lt' => $c->source->lt($column, $value),
                    'lte' => $c->source->lte($column, $value),
                    'gt' => $c->source->gt($column, $value),
                    'gte' => $c->source->gte($column, $value),
                    'like' => $c->source->like($column, $value),
                    default => throw new \LogicException("Unknown operator: $op"),
                };
            }
            unset($c->binds[$param]);
        }
        return $c;
    }

    /**
     * Check if all parameters are bound
     */
    public function isBound(): bool
    {
        return empty($this->binds);
    }

    /**
     * Get list of unbound parameter names
     *
     * @return array<string|int>
     */
    public function getUnboundParameters(): array
    {
        return array_keys($this->binds);
    }

    // =========================================================================
    // TableInterface delegation
    // =========================================================================

    public function getIterator(): Traversable
    {
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot iterate: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        yield from $this->getEffectiveSource();
    }

    public function count(): int
    {
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot count: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->getEffectiveSource()->count();
    }

    public function getColumns(): array
    {
        if ($this->deferredColumns !== null) {
            // Return only the deferred columns from source's columns
            $sourceCols = $this->source->getColumns();
            $result = [];
            foreach ($this->deferredColumns as $col) {
                if (isset($sourceCols[$col])) {
                    $result[$col] = $sourceCols[$col];
                }
            }
            return $result;
        }
        return $this->source->getColumns();
    }

    public function has(object $member): bool
    {
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot check membership: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->getEffectiveSource()->has($member);
    }

    /**
     * Get source with deferred column projection applied
     */
    private function getEffectiveSource(): TableInterface
    {
        $source = $this->source;
        if ($this->deferredColumns !== null) {
            $source = $source->columns(...$this->deferredColumns);
        }
        return $source;
    }

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

    public function union(TableInterface $other): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->union($other);
        return $c;
    }

    public function or(Predicate ...$predicates): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->or(...$predicates);
        return $c;
    }

    public function except(SetInterface $other): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->except($other);
        return $c;
    }

    public function distinct(): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->distinct();
        return $c;
    }

    public function columns(string ...$columns): TableInterface
    {
        $c = clone $this;
        if ($this->deferredColumns !== null) {
            // Can only narrow, not expand - intersect with existing projection
            $c->deferredColumns = array_values(
                array_intersect($columns, $this->deferredColumns)
            );
        } else {
            $c->deferredColumns = $columns;
        }
        return $c;
    }

    public function order(?string $spec): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->order($spec);
        return $c;
    }

    public function limit(int $n): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->limit($n);
        return $c;
    }

    public function offset(int $n): TableInterface
    {
        $c = clone $this;
        $c->source = $this->source->offset($n);
        return $c;
    }

    public function getLimit(): ?int
    {
        return $this->source->getLimit();
    }

    public function getOffset(): int
    {
        return $this->source->getOffset();
    }

    public function exists(): bool
    {
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot check exists: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->getEffectiveSource()->exists();
    }

    public function load(string|int $rowId): ?object
    {
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot load: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->getEffectiveSource()->load($rowId);
    }
}
