<?php

namespace mini\Database;

use stdClass;

/**
 * Simple result set wrapper for raw SQL queries
 *
 * Wraps an iterable of rows and provides the ResultSetInterface API.
 * Supports hydration to entity classes or custom closures.
 *
 * Rows can be arrays or stdClass objects. When hydration is not configured,
 * rows are returned as-is (array or stdClass depending on source).
 *
 * @template T of array|object
 * @implements ResultSetInterface<T>
 */
class ResultSet implements ResultSetInterface
{
    /** @var iterable<array|stdClass> */
    private iterable $rows;

    /** @var array<array|stdClass>|null Materialized rows (lazy) */
    private ?array $materialized = null;

    /** @var \Closure|null */
    private ?\Closure $hydrator = null;

    /** @var class-string|null */
    private ?string $entityClass = null;

    /** @var array|false */
    private array|false $constructorArgs = false;

    /**
     * @param iterable<array|stdClass> $rows Raw database rows
     */
    public function __construct(iterable $rows)
    {
        $this->rows = $rows;
    }

    /**
     * @return \Traversable<int, T>
     */
    public function getIterator(): \Traversable
    {
        foreach ($this->rows as $row) {
            yield $this->hydrateRow($row);
        }
    }

    public function count(): int
    {
        return count($this->materialize());
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->materialize() as $row) {
            $result[] = $this->hydrateRow($row);
        }
        return $result;
    }

    public function one(): mixed
    {
        foreach ($this->rows as $row) {
            return $this->hydrateRow($row);
        }
        return null;
    }

    public function column(): array
    {
        $result = [];
        foreach ($this->rows as $row) {
            $result[] = $this->getFirstValue($row);
        }
        return $result;
    }

    public function field(): mixed
    {
        foreach ($this->rows as $row) {
            return $this->getFirstValue($row);
        }
        return null;
    }

    /**
     * Get first value from a row (works with both array and stdClass)
     */
    private function getFirstValue(array|stdClass $row): mixed
    {
        if ($row instanceof stdClass) {
            $vars = get_object_vars($row);
            return $vars ? reset($vars) : null;
        }
        return $row ? reset($row) : null;
    }

    public function withEntityClass(string $class, array|false $constructorArgs = false): self
    {
        $clone = clone $this;
        $clone->entityClass = $class;
        $clone->constructorArgs = $constructorArgs;
        $clone->hydrator = null;
        return $clone;
    }

    public function withHydrator(\Closure $hydrator): self
    {
        $clone = clone $this;
        $clone->hydrator = $hydrator;
        $clone->entityClass = null;
        return $clone;
    }

    /**
     * Materialize rows for operations that need the full set
     */
    private function materialize(): array
    {
        if ($this->materialized === null) {
            $this->materialized = $this->rows instanceof \Traversable
                ? iterator_to_array($this->rows)
                : (array) $this->rows;
        }
        return $this->materialized;
    }

    /**
     * Apply hydration to a single row
     *
     * @param array|stdClass $row
     * @return T
     */
    private function hydrateRow(array|stdClass $row): mixed
    {
        if ($this->hydrator !== null) {
            return ($this->hydrator)($row);
        }

        if ($this->entityClass !== null) {
            return $this->hydrateEntity($row);
        }

        return $row;
    }

    /**
     * Convert row to array if needed
     */
    private function rowToArray(array|stdClass $row): array
    {
        return $row instanceof stdClass ? get_object_vars($row) : $row;
    }

    /**
     * Hydrate row into entity class
     */
    private function hydrateEntity(array|stdClass $row): object
    {
        $class = $this->entityClass;
        $refClass = new \ReflectionClass($class);

        if ($this->constructorArgs === false) {
            // Skip constructor, assign properties directly
            $entity = $refClass->newInstanceWithoutConstructor();
        } else {
            // Use constructor with provided args
            $entity = $refClass->newInstanceArgs($this->constructorArgs);
        }

        // Map columns to properties by name
        foreach ($row as $key => $value) {
            if ($refClass->hasProperty($key)) {
                $prop = $refClass->getProperty($key);
                $prop->setAccessible(true);
                $prop->setValue($entity, $value);
            }
        }

        return $entity;
    }
}
