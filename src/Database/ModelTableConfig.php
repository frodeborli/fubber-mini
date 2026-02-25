<?php

namespace mini\Database;

/**
 * Per-table model configuration for VirtualDatabase::registerModel()
 *
 * Stores the model class and optional scope overrides. When a scope is null,
 * VDB calls the model's default method at execution time (lazy evaluation).
 */
final class ModelTableConfig
{
    /**
     * @param class-string<Model> $modelClass The entity class
     * @param Query|null $readable Row-level read scope (null = call $modelClass::query())
     * @param Query|null $updatable Row-level update scope (null = call $modelClass::updatable())
     * @param Query|null $deletable Row-level delete scope (null = call $modelClass::deletable())
     * @param bool|null $allowInsert Insert gate (null = call can(Ability::Create, $modelClass))
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly ?Query $readable = null,
        public readonly ?Query $updatable = null,
        public readonly ?Query $deletable = null,
        public readonly ?bool $allowInsert = null,
    ) {}
}
