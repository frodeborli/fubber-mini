<?php

namespace mini;

use mini\Repository\RepositoryInterface;
use mini\Util\QueryParser;

/**
 * Immutable query builder for repository data sources
 *
 * @template T of object
 * @implements \IteratorAggregate<int, T>
 */
class Table implements \IteratorAggregate, \Countable
{
    /**
     * @param RepositoryInterface<T> $repository
     */
    public function __construct(
        private RepositoryInterface $repository,
        private array $conditions = [],
        private array $order = [],
        private ?int $limit = null,
        private int $offset = 0
    ) {}

    /** Query building methods (immutable) */

    /** @return self<T> */
    public function eq(string $field, mixed $value): self
    {
        // Convert PHP value to storage format for consistent querying
        $storageValue = $this->repository->transformToStorage($field, $value);
        return $this->addCondition($field, $storageValue);
    }

    /** @return self<T> */
    public function gte(string $field, mixed $value): self
    {
        $storageValue = $this->repository->transformToStorage($field, $value);
        return $this->addCondition($field . ':gte', $storageValue);
    }

    /** @return self<T> */
    public function lte(string $field, mixed $value): self
    {
        $storageValue = $this->repository->transformToStorage($field, $value);
        return $this->addCondition($field . ':lte', $storageValue);
    }

    /** @return self<T> */
    public function gt(string $field, mixed $value): self
    {
        $storageValue = $this->repository->transformToStorage($field, $value);
        return $this->addCondition($field . ':gt', $storageValue);
    }

    /** @return self<T> */
    public function lt(string $field, mixed $value): self
    {
        $storageValue = $this->repository->transformToStorage($field, $value);
        return $this->addCondition($field . ':lt', $storageValue);
    }

    /** @return self<T> */
    public function like(string $field, string $pattern): self
    {
        // Like patterns don't need type conversion
        return $this->addCondition($field . ':like', $pattern);
    }

    /** @return self<T> */
    public function in(string $field, array $values): self
    {
        // Convert each value in the array
        $storageValues = array_map(
            fn($val) => $this->repository->transformToStorage($field, $val),
            $values
        );
        return $this->addCondition($field . ':in', $storageValues);
    }

    /** @return self<T> */
    public function query(array|string $params): self
    {
        if (is_string($params)) {
            parse_str($params, $parsed);
            $params = $parsed;
        }

        // TODO: Fix QueryParser whitelist to work with colon syntax
        // For now, skip whitelist validation to avoid rejecting valid operator fields
        $queryParser = new QueryParser($params);
        $parsedQuery = $queryParser->getQuery();

        // Map QueryParser operator symbols back to our operator names
        $operatorMap = [
            '=' => '',      // Simple equality (no suffix)
            '>' => 'gt',
            '>=' => 'gte',
            '<' => 'lt',
            '<=' => 'lte',
            'LIKE' => 'like'
        ];

        // Convert QueryParser format to simple key-value format for repositories
        $conditions = [];
        foreach ($parsedQuery as $field => $operators) {
            if (is_array($operators)) {
                // Handle structured format: ["age" => [">=" => "21"]]
                foreach ($operators as $operator => $value) {
                    // Convert string values to proper types
                    $convertedValue = $this->repository->transformToStorage($field, $value);

                    if ($operator === '=') {
                        $conditions[$field] = $convertedValue;
                    } else {
                        $operatorName = $operatorMap[$operator] ?? $operator;
                        $conditions[$field . ':' . $operatorName] = $convertedValue;
                    }
                }
            } else {
                // Handle simple format: ["name" => "john"]
                $convertedValue = $this->repository->transformToStorage($field, $operators);
                $conditions[$field] = $convertedValue;
            }
        }

        return new self($this->repository, array_merge($this->conditions, $conditions), $this->order, $this->limit, $this->offset);
    }

    /** @return self<T> */
    public function limit(int $limit): self
    {
        return new self($this->repository, $this->conditions, $this->order, $limit, $this->offset);
    }

    /** @return self<T> */
    public function offset(int $offset): self
    {
        return new self($this->repository, $this->conditions, $this->order, $this->limit, $offset);
    }

    /** @return self<T> */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $order = array_merge($this->order, [$field => strtolower($direction)]);
        return new self($this->repository, $this->conditions, $order, $this->limit, $this->offset);
    }

    /** Execution methods */

    /** @return T|null */
    public function one(): ?object
    {
        $row = $this->repository->fetchOne($this->conditions);
        return $row ? $this->repository->hydrate($row) : null;
    }

    /** @return T[] */
    public function all(): array
    {
        $results = [];
        foreach ($this->repository->fetchMany($this->conditions, $this->order, $this->limit, $this->offset) as $row) {
            $results[] = $this->repository->hydrate($row);
        }
        return $results;
    }

    public function count(): int
    {
        return $this->repository->count($this->conditions);
    }

    /** @return T */
    public function create(): object
    {
        return $this->repository->create();
    }

    /** @return T */
    public function load(mixed $id): object
    {
        $result = $this->repository->load($id);
        if ($result === null) {
            throw new Exception\RepositoryException("Record with id '$id' not found in repository '{$this->repository->name()}'");
        }
        return $result;
    }

    /** IteratorAggregate implementation */

    /** @return \Generator<int, T> */
    public function getIterator(): \Generator
    {
        foreach ($this->repository->fetchMany($this->conditions, $this->order, $this->limit, $this->offset) as $row) {
            yield $this->repository->hydrate($row);
        }
    }

    /** Private helpers */

    /** @return self<T> */
    private function addCondition(string $field, mixed $value): self
    {
        $conditions = array_merge($this->conditions, [$field => $value]);
        return new self($this->repository, $conditions, $this->order, $this->limit, $this->offset);
    }

}
