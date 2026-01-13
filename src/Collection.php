<?php

namespace mini;

use ArrayIterator;
use Closure;
use mini\Contracts\CollectionInterface;
use Traversable;

/**
 * Immutable collection with functional transformation methods
 *
 * All transformation methods return new Collection instances,
 * leaving the original unchanged.
 *
 * @template T
 * @implements CollectionInterface<T>
 */
final class Collection implements CollectionInterface
{
    /**
     * @param array<int, T> $items
     */
    private function __construct(
        private readonly array $items
    ) {}

    /**
     * Create a collection from an iterable
     *
     * @template U
     * @param iterable<U> $items
     * @return self<U>
     */
    public static function from(iterable $items): self
    {
        if ($items instanceof self) {
            return $items;
        }

        return new self(
            $items instanceof Traversable
                ? iterator_to_array($items, false)
                : array_values($items)
        );
    }

    /**
     * Create an empty collection
     *
     * @template U
     * @return self<U>
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Create a collection from variadic arguments
     *
     * @template U
     * @param U ...$items
     * @return self<U>
     */
    public static function of(mixed ...$items): self
    {
        return new self($items);
    }

    /**
     * @template U
     * @param Closure(T): U $fn
     * @return CollectionInterface<U>
     */
    public function map(Closure $fn): CollectionInterface
    {
        return new self(array_map($fn, $this->items));
    }

    /**
     * @param Closure(T): bool $fn
     * @return CollectionInterface<T>
     */
    public function filter(Closure $fn): CollectionInterface
    {
        return new self(array_values(array_filter($this->items, $fn)));
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return T|null
     */
    public function last(): mixed
    {
        if (empty($this->items)) {
            return null;
        }
        return $this->items[count($this->items) - 1];
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * @template U
     * @param Closure(U, T): U $fn
     * @param U $initial
     * @return U
     */
    public function reduce(Closure $fn, mixed $initial): mixed
    {
        return array_reduce($this->items, $fn, $initial);
    }

    /**
     * @return array<int, T>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @param Closure(T): bool $fn
     */
    public function any(Closure $fn): bool
    {
        foreach ($this->items as $item) {
            if ($fn($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param Closure(T): bool $fn
     */
    public function none(Closure $fn): bool
    {
        foreach ($this->items as $item) {
            if ($fn($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param Closure(T): bool $fn
     */
    public function all(Closure $fn): bool
    {
        foreach ($this->items as $item) {
            if (!$fn($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param Closure(T): bool $fn
     * @return T|null
     */
    public function find(Closure $fn): mixed
    {
        foreach ($this->items as $item) {
            if ($fn($item)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array<int, T>
     */
    public function jsonSerialize(): array
    {
        return $this->items;
    }
}
