<?php

namespace mini\Util;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use ArrayIterator;
use InvalidArgumentException;
use mini\Contracts\CollectionInterface;

/**
 * Generic instance store that mirrors WeakMap API but with type validation
 *
 * Provides a type-safe way to store singleton instances with interface validation.
 * Mirrors PHP's WeakMap API for familiar usage patterns.
 *
 * @template T of object
 * @implements CollectionInterface<string, T>
 */
class InstanceStore implements CollectionInterface
{
    /** @var array<string, T> */
    private array $instances = [];
    /** @var class-string<T> */
    private string $requiredType;

    /**
     * @param class-string<T> $requiredType Class name or interface name for type validation
     */
    public function __construct(string $requiredType)
    {
        $this->requiredType = $requiredType;
    }

    /**
     * Check if a key exists (mirrors WeakMap::offsetExists)
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->instances[$offset]);
    }

    /**
     * Get an instance by key (mirrors WeakMap::offsetGet)
     * @return T|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->instances[$offset] ?? null;
    }

    /**
     * Set an instance with type validation (mirrors WeakMap::offsetSet)
     * @param T|null $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($value !== null && !($value instanceof $this->requiredType)) {
            throw new InvalidArgumentException(
                sprintf('Value must be an instance of %s, %s given',
                    $this->requiredType,
                    get_debug_type($value)
                )
            );
        }

        if ($offset === null) {
            $this->instances[] = $value;
        } else {
            $this->instances[$offset] = $value;
        }
    }

    /**
     * Remove an instance (mirrors WeakMap::offsetUnset)
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->instances[$offset]);
    }

    /**
     * Get the number of stored instances
     */
    public function count(): int
    {
        return count($this->instances);
    }

    /**
     * Get iterator for stored instances
     * @return ArrayIterator<string, T>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->instances);
    }

    /**
     * Check if key exists (WeakMap-style method)
     */
    public function has(mixed $key): bool
    {
        return isset($this->instances[$key]);
    }

    /**
     * Get instance by key (WeakMap-style method)
     * @param string $key
     * @return T|null
     */
    public function get(mixed $key): mixed
    {
        return $this->instances[$key] ?? null;
    }

    /**
     * Get instance by member access
     * 
     * @param string $key
     * @return T
     */
    public function __get(mixed $key): mixed
    {
        if (!isset($this->instances[$key])) {
            throw new \RuntimeException("Key '$key' does not exist in instance store");
        }
        return $this->instances[$key];
    }

    /**
     * Set instance by member access
     * 
     * @param string $key 
     * @param T $value 
     * @throws InvalidArgumentException 
     */
    public function __set(mixed $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * Set instance with type validation (WeakMap-style method)
     * @param T|null $value
     */
    public function set(mixed $key, mixed $value): void
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Add instance with type validation (throws if key already exists)
     * @param T|null $value
     * @throws \RuntimeException If the key already exists
     */
    public function add(mixed $key, mixed $value): void
    {
        if ($this->has($key)) {
            throw new \RuntimeException("Key '$key' already exists in collection");
        }
        $this->set($key, $value);
    }

    /**
     * Delete instance (WeakMap-style method)
     */
    public function delete(mixed $key): bool
    {
        if (!isset($this->instances[$key])) {
            return false;
        }

        unset($this->instances[$key]);
        return true;
    }

    /**
     * Get all stored keys
     */
    public function keys(): array
    {
        return array_keys($this->instances);
    }

    /**
     * Get all stored values
     * @return array<T>
     */
    public function values(): array
    {
        return array_values($this->instances);
    }

    /**
     * Get the required type for this store
     * @return class-string<T>
     */
    public function getRequiredType(): string
    {
        return $this->requiredType;
    }
}
