<?php

namespace mini\Database;

use Closure;
use IteratorAggregate;
use Countable;
use Traversable;
use mini\Table\Predicate;

/**
 * User-facing query class for reading data
 *
 * Wraps PartialQuery with a clean, read-focused API. Mutations go through
 * DatabaseInterface methods (update, delete) rather than the query itself.
 *
 * ```php
 * $query = db()->query('SELECT * FROM users WHERE active = true');
 *
 * // Filtering
 * $admins = $query->eq('role', 'admin');
 *
 * // Shaping
 * $recent = $query->order('created_at DESC')->limit(10);
 *
 * // Fetching
 * foreach ($recent as $user) { ... }
 * $user = $query->eq('id', 5)->one();
 *
 * // Mutations go through database
 * db()->update($query, ['status' => 'verified']);
 * db()->delete($query->eq('spam', true));
 * ```
 */
final class Query implements IteratorAggregate, Countable
{
    /**
     * @param PartialQuery $pq The underlying query
     * @param Closure(PartialQuery): Query $wrap Factory to wrap derived queries
     */
    public function __construct(
        private PartialQuery $pq,
        private Closure $wrap
    ) {}

    // =========================================================================
    // Filtering
    // =========================================================================

    public function eq(string $column, int|float|string|null $value): static
    {
        return ($this->wrap)($this->pq->eq($column, $value));
    }

    public function lt(string $column, int|float|string $value): static
    {
        return ($this->wrap)($this->pq->lt($column, $value));
    }

    public function lte(string $column, int|float|string $value): static
    {
        return ($this->wrap)($this->pq->lte($column, $value));
    }

    public function gt(string $column, int|float|string $value): static
    {
        return ($this->wrap)($this->pq->gt($column, $value));
    }

    public function gte(string $column, int|float|string $value): static
    {
        return ($this->wrap)($this->pq->gte($column, $value));
    }

    public function like(string $column, string $pattern): static
    {
        return ($this->wrap)($this->pq->like($column, $pattern));
    }

    public function in(string $column, array|Query $values): static
    {
        if ($values instanceof Query) {
            return ($this->wrap)($this->pq->in($column, $values->pq));
        }
        return ($this->wrap)($this->pq->in($column, $values));
    }

    public function or(Predicate $a, Predicate $b, Predicate ...$more): static
    {
        return ($this->wrap)($this->pq->or($a, $b, ...$more));
    }

    public function where(string $sql, array $params = []): static
    {
        return ($this->wrap)($this->pq->where($sql, $params));
    }

    // =========================================================================
    // Shaping
    // =========================================================================

    public function columns(string ...$columns): static
    {
        return ($this->wrap)($this->pq->columns(...$columns));
    }

    public function order(?string $spec): static
    {
        return ($this->wrap)($this->pq->order($spec));
    }

    public function limit(int $n): static
    {
        return ($this->wrap)($this->pq->limit($n));
    }

    public function offset(int $n): static
    {
        return ($this->wrap)($this->pq->offset($n));
    }

    public function distinct(): static
    {
        return ($this->wrap)($this->pq->distinct());
    }

    // =========================================================================
    // Fetching
    // =========================================================================

    /**
     * Get first row or null
     */
    public function one(): mixed
    {
        return $this->pq->one();
    }

    /**
     * Get first row or throw
     *
     * @throws \RuntimeException if no rows found
     */
    public function first(): object
    {
        $result = $this->pq->one();
        if ($result === null) {
            throw new \RuntimeException('No rows found');
        }
        return $result;
    }

    /**
     * Get all rows as array
     */
    public function all(): array
    {
        return iterator_to_array($this->pq);
    }

    /**
     * Check if any rows exist
     */
    public function exists(): bool
    {
        return $this->pq->exists();
    }

    /**
     * Load a single row by primary key
     */
    public function load(int|string $id): ?object
    {
        return $this->pq->load($id);
    }

    // =========================================================================
    // Iteration & Counting
    // =========================================================================

    public function getIterator(): Traversable
    {
        return $this->pq->getIterator();
    }

    public function count(): int
    {
        return $this->pq->count();
    }

    // =========================================================================
    // Hydration
    // =========================================================================

    /**
     * Hydrate results into entity instances
     */
    public function asEntity(string $class, array $constructorArgs = []): static
    {
        return ($this->wrap)($this->pq->withEntityClass($class, $constructorArgs ?: false));
    }

    /**
     * Transform each row with a custom hydrator
     */
    public function withHydrator(Closure $hydrator): static
    {
        return ($this->wrap)($this->pq->withHydrator($hydrator));
    }

    // =========================================================================
    // Debugging
    // =========================================================================

    public function __toString(): string
    {
        return (string) $this->pq;
    }
}
