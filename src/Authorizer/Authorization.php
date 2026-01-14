<?php

namespace mini\Authorizer;

use mini\Hooks\Handler;

/**
 * Authorization service
 *
 * Manages ability registration and authorization queries via Handler dispatch.
 * Handlers are registered per-class and resolved by type specificity - more specific
 * classes are checked before parent classes and interfaces.
 *
 * Resolution order for `can(Ability::Delete, $post)`:
 * 1. Handlers for Post::class
 * 2. Handlers for parent classes (e.g., Model::class)
 * 3. Handlers for interfaces
 * 4. Fallback handler
 *
 * If no handler responds, the default is true (allow). Authorization is opt-in.
 *
 * ## Registering Handlers
 *
 * ```php
 * $auth = Mini::$mini->get(Authorization::class);
 *
 * // Specific handler for User class
 * $auth->for(User::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     // Field-level check
 *     if ($q->field === 'role' && $q->ability === Ability::Update) {
 *         return auth()->hasRole('admin');
 *     }
 *
 *     return match ($q->ability) {
 *         Ability::List => auth()->isAuthenticated(),
 *         Ability::Create => auth()->hasRole('admin'),
 *         Ability::Read => true,
 *         Ability::Update, Ability::Delete =>
 *             $q->instance()?->id === auth()->getUserId() || auth()->hasRole('admin'),
 *         default => null,
 *     };
 * });
 *
 * // Generic handler for all Model subclasses (checked after specific handlers)
 * $auth->for(Model::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     return auth()->hasRole('admin');
 * });
 *
 * // Fallback for anything not matched
 * $auth->fallback->listen(function(AuthorizationQuery $q): ?bool {
 *     return false;
 * });
 * ```
 *
 * ## Custom Abilities
 *
 * ```php
 * $auth->registerAbility('publish');
 * $auth->registerAbility('archive');
 *
 * $auth->for(Post::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     if ($q->ability === 'publish') {
 *         return auth()->hasRole('editor');
 *     }
 *     return null;
 * });
 * ```
 *
 * ## Checking Authorization
 *
 * ```php
 * // Via service
 * $auth->can(Ability::Delete, $user);
 *
 * // Via helper function
 * can(Ability::Delete, $user);
 * can(Ability::Update, $user, 'role');
 * can(Ability::List, User::class);
 * ```
 */
class Authorization
{
    /** @var array<string, Handler<AuthorizationQuery, bool>> */
    private array $handlers = [];

    /** @var Handler<AuthorizationQuery, bool> Fallback for unmatched classes */
    public Handler $fallback;

    /** @var array<string, true> */
    private array $customAbilities = [];

    public function __construct()
    {
        $this->fallback = new Handler('authorization:fallback');
    }

    /**
     * Get or create handler for a specific resource
     *
     * Resources can be class names or arbitrary identifiers (e.g., 'virtualdatabase.countries').
     * For class names, handlers registered for more specific classes are checked before
     * handlers for parent classes or interfaces.
     *
     * @param string $resource Class name or resource identifier
     * @return Handler<AuthorizationQuery, bool>
     */
    public function for(string $resource): Handler
    {
        return $this->handlers[$resource] ??= new Handler("authorization:$resource");
    }

    /**
     * Check if the current user can perform an ability on an entity
     *
     * @param Ability|string $ability The ability to check
     * @param object|string $entity Entity instance or class name
     * @param string|null $field Optional field name for field-level checks
     * @return bool True if allowed, false if denied
     * @throws \InvalidArgumentException If string ability is not registered
     */
    public function can(Ability|string $ability, object|string $entity, ?string $field = null): bool
    {
        if (is_string($ability) && !isset($this->customAbilities[$ability])) {
            throw new \InvalidArgumentException(
                "Unknown ability '$ability'. Register with registerAbility() first."
            );
        }

        $query = new AuthorizationQuery($ability, $entity, $field);
        $class = is_string($entity) ? $entity : $entity::class;

        // Walk class hierarchy (most specific first)
        foreach ($this->walkClassHierarchy($class) as $type) {
            if (isset($this->handlers[$type])) {
                $result = $this->handlers[$type]->trigger($query);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // Try fallback
        $result = $this->fallback->trigger($query);
        return $result ?? true;
    }

    /**
     * Register a custom string ability
     *
     * Standard Ability enum values are always available. Custom string abilities
     * must be registered before use to ensure typos are caught early.
     *
     * @param string $abilityName Custom ability name
     */
    public function registerAbility(string $abilityName): void
    {
        $this->customAbilities[$abilityName] = true;
    }

    /**
     * Walk class hierarchy in specificity order
     *
     * For objects: yields class, then direct interfaces, then parent class,
     * then parent's direct interfaces, etc.
     *
     * For class strings that don't exist: yields just the string itself.
     *
     * @param string $class Class name to walk
     * @return \Generator<string>
     */
    private function walkClassHierarchy(string $class): \Generator
    {
        if (!class_exists($class) && !interface_exists($class)) {
            yield $class;
            return;
        }

        $rc = new \ReflectionClass($class);
        while ($rc !== false) {
            yield $rc->getName();

            // Direct interfaces (not inherited from parent)
            $parent = $rc->getParentClass();
            foreach ($rc->getInterfaceNames() as $interface) {
                if ($parent === false || !$parent->implementsInterface($interface)) {
                    yield $interface;
                }
            }

            $rc = $parent;
        }
    }
}
