<?php

namespace mini\Table;

use Countable;
use IteratorAggregate;
use mini\Table\Contracts\MutableTableInterface;
use mini\Table\Contracts\TableInterface;
use Traversable;

/**
 * User-facing table wrapper with parameter binding support
 *
 * Table wraps a TableInterface and provides a minimal, predictable API
 * for end users. All methods return Table for consistent chaining.
 *
 * ```php
 * $users = Table::from($usersTable);
 *
 * // Filtering and iteration
 * foreach ($users->eq('status', 'active')->order('name')->limit(10) as $user) {
 *     echo $user->name;
 * }
 *
 * // Bindable parameters for reusable queries
 * $byStatus = $users->eqBind('status', ':status');
 * $active = $byStatus->bind([':status' => 'active']);
 * $pending = $byStatus->bind([':status' => 'pending']);
 *
 * // Mutations (if table is mutable)
 * $users->insert(['name' => 'Alice', 'status' => 'active']);
 * $users->eq('status', 'inactive')->delete();
 * $users->eq('id', 123)->update(['status' => 'active']);
 * ```
 */
final class Table implements IteratorAggregate, Countable
{
    private TableInterface $source;

    /** @var MutableTableInterface|null Root table for mutations */
    private ?MutableTableInterface $mutableRoot = null;

    /** @var array<string|int, list<array{string, string}>> param => [[column, operator], ...] */
    private array $binds = [];

    /** @var string[]|null Deferred column projection (applied at iteration) */
    private ?array $deferredColumns = null;

    /** @var Predicate[]|null Deferred OR predicates (applied at iteration) */
    private ?array $deferredPredicates = null;

    private function __construct(TableInterface $source)
    {
        $this->source = $source;
        if ($source instanceof MutableTableInterface) {
            $this->mutableRoot = $source;
        }
    }

    /**
     * Create a Table wrapper from any TableInterface
     */
    public static function from(self|TableInterface $source): self
    {
        if ($source instanceof self) {
            return $source;
        }
        return new self($source);
    }

    // =========================================================================
    // Mutation methods
    // =========================================================================

    /**
     * Check if this table supports mutations (insert/update/delete)
     */
    public function isMutable(): bool
    {
        return $this->mutableRoot !== null;
    }

    /**
     * Insert a new row
     *
     * @throws \RuntimeException if table is not mutable
     */
    public function insert(array $row): int|string
    {
        if ($this->mutableRoot === null) {
            throw new \RuntimeException('Table does not support mutations');
        }
        return $this->mutableRoot->insert($row);
    }

    /**
     * Update rows matching current filters
     *
     * @throws \RuntimeException if table is not mutable or has unbound parameters
     */
    public function update(array $changes): int
    {
        if ($this->mutableRoot === null) {
            throw new \RuntimeException('Table does not support mutations');
        }
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot update: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->mutableRoot->update($this->getEffectiveSource(), $changes);
    }

    /**
     * Delete rows matching current filters
     *
     * @throws \RuntimeException if table is not mutable or has unbound parameters
     */
    public function delete(): int
    {
        if ($this->mutableRoot === null) {
            throw new \RuntimeException('Table does not support mutations');
        }
        if (!empty($this->binds)) {
            throw new \RuntimeException(
                "Cannot delete: unbound parameters: " . implode(', ', array_keys($this->binds))
            );
        }
        return $this->mutableRoot->delete($this->getEffectiveSource());
    }

    // =========================================================================
    // Bindable predicates
    // =========================================================================

    /**
     * Add bindable equality predicate
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
     * @param array<string|int, mixed> $values Parameter values keyed by name or position
     * @throws \InvalidArgumentException If an unknown parameter is provided
     */
    public function bind(array $values): self
    {
        $c = clone $this;

        // Bind to deferred predicates
        if ($c->deferredPredicates !== null) {
            $c->deferredPredicates = array_map(
                fn(Predicate $p) => $p->bind($values),
                $c->deferredPredicates
            );
        }

        // Bind to direct filters
        foreach ($values as $param => $value) {
            if (!isset($c->binds[$param])) {
                throw new \InvalidArgumentException("Unknown parameter: $param");
            }
            foreach ($c->binds[$param] as $bind) {
                // Skip 'or' markers - those are handled via deferredPredicates
                if ($bind[0] === 'or') {
                    continue;
                }
                [$column, $op] = $bind;
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
        if (!empty($this->binds)) {
            return false;
        }
        if ($this->deferredPredicates !== null) {
            foreach ($this->deferredPredicates as $p) {
                if (!$p->isBound()) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Get list of unbound parameter names
     */
    public function getUnboundParameters(): array
    {
        $params = array_keys($this->binds);
        if ($this->deferredPredicates !== null) {
            foreach ($this->deferredPredicates as $p) {
                $params = array_merge($params, $p->getUnboundParams());
            }
        }
        return array_unique($params);
    }

    // =========================================================================
    // Filter methods
    // =========================================================================

    public function eq(string $column, int|float|string|null $value): self
    {
        $c = clone $this;
        $c->source = $c->source->eq($column, $value);
        return $c;
    }

    public function lt(string $column, int|float|string $value): self
    {
        $c = clone $this;
        $c->source = $c->source->lt($column, $value);
        return $c;
    }

    public function lte(string $column, int|float|string $value): self
    {
        $c = clone $this;
        $c->source = $c->source->lte($column, $value);
        return $c;
    }

    public function gt(string $column, int|float|string $value): self
    {
        $c = clone $this;
        $c->source = $c->source->gt($column, $value);
        return $c;
    }

    public function gte(string $column, int|float|string $value): self
    {
        $c = clone $this;
        $c->source = $c->source->gte($column, $value);
        return $c;
    }

    public function like(string $column, string $pattern): self
    {
        $c = clone $this;
        $c->source = $c->source->like($column, $pattern);
        return $c;
    }

    public function or(Predicate ...$predicates): self
    {
        $c = clone $this;

        // Check for unbound params in predicates
        $hasUnbound = false;
        foreach ($predicates as $p) {
            foreach ($p->getUnboundParams() as $param) {
                $c->binds[$param][] = ['or', count($predicates)]; // marker for or predicates
                $hasUnbound = true;
            }
        }

        if ($hasUnbound) {
            // Defer predicates until bound
            $c->deferredPredicates = $predicates;
        } else {
            // All bound, apply immediately
            $c->source = $c->source->or(...$predicates);
        }

        return $c;
    }

    public function columns(string ...$columns): self
    {
        $c = clone $this;
        if ($this->deferredColumns !== null) {
            $c->deferredColumns = array_values(
                array_intersect($columns, $this->deferredColumns)
            );
        } else {
            $c->deferredColumns = $columns;
        }
        return $c;
    }

    public function order(?string $spec): self
    {
        $c = clone $this;
        $c->source = $c->source->order($spec);
        return $c;
    }

    public function limit(int $n): self
    {
        $c = clone $this;
        $c->source = $c->source->limit($n);
        return $c;
    }

    public function offset(int $n): self
    {
        $c = clone $this;
        $c->source = $c->source->offset($n);
        return $c;
    }

    // =========================================================================
    // Iteration and data access
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

    /**
     * @return array<string, ColumnDef>
     */
    public function getColumns(): array
    {
        if ($this->deferredColumns !== null) {
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

    // =========================================================================
    // Internal
    // =========================================================================

    private function getEffectiveSource(): TableInterface
    {
        $source = $this->source;

        // Apply deferred predicates (must be fully bound at this point)
        if ($this->deferredPredicates !== null) {
            $source = $source->or(...$this->deferredPredicates);
        }

        if ($this->deferredColumns !== null) {
            $source = $source->columns(...$this->deferredColumns);
        }

        return $source;
    }
}
