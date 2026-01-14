<?php

namespace mini\Authorizer;

/**
 * Query object passed to authorization handlers
 *
 * Handlers inspect the query and return:
 * - true: Allow (stops processing)
 * - false: Deny (stops processing)
 * - null: Pass to next handler
 *
 * Usage in handlers:
 * ```php
 * $auth->for(User::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     return match ($q->ability) {
 *         Ability::Read => true,
 *         Ability::Delete => $q->instance()?->id === auth()->getUserId(),
 *         default => null,
 *     };
 * });
 * ```
 */
readonly class AuthorizationQuery
{
    public function __construct(
        public Ability|string $ability,
        public object|string $entity,
        public ?string $field = null,
    ) {}

    /**
     * Get the entity class name
     */
    public function className(): string
    {
        return is_string($this->entity) ? $this->entity : $this->entity::class;
    }

    /**
     * Get the entity instance, or null if class-level query
     */
    public function instance(): ?object
    {
        return is_object($this->entity) ? $this->entity : null;
    }
}
