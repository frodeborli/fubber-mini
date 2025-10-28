<?php

namespace mini\Tables;

/**
 * Read-only repository interface for data source abstraction
 *
 * @template T of object
 */
interface ReadonlyRepositoryInterface
{
    /** Identity */
    public function name(): string;
    public function pk(): string;

    /** @return class-string<T> */
    public function getModelClass(): string;

    /** @return Table<T> */
    public function all(): \mini\Table;

    /** Model lifecycle with type safety */
    /** @return T */
    public function create(): object;

    /** @return T|null */
    public function load(mixed $id): ?object;

    /**
     * Load multiple objects by their IDs
     * @param string|int ...$ids Variable number of ID arguments
     * @return array<T> Array of loaded objects (may be fewer than requested IDs)
     */
    public function loadMany(string|int ...$ids): array;

    /** Raw data access */
    public function fetchOne(array $where): ?array;
    public function fetchMany(array $where, array $order = [], ?int $limit = null, int $offset = 0): iterable;
    public function count(array $where = []): int;

    /** Field introspection */
    public function getFieldNames(): array;

    /** Field-level transformation */
    public function transformFromStorage(string $field, mixed $value): mixed;
    public function transformToStorage(string $field, mixed $value): mixed;

    /** Object hydration (can use ObjectHydrationTrait for default implementation) */
    /** @return T */
    public function hydrate(array $row): object;

    /** Optional: Direct codec access for advanced scenarios */
    public function getFieldCodec(string $field): ?object;

    /** Access control methods */
    public function canList(): bool;

    /** @param T $model */
    public function canRead(object $model): bool;
}