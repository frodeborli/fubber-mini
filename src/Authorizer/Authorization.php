<?php

namespace mini\Authorizer;

use mini\Hooks\Handler;

/**
 * Authorization service
 *
 * Manages ability registration and authorization queries via Handler dispatch.
 * Handlers are registered per-class and resolved by type specificity.
 *
 * ## Execution Order
 *
 * For `can(Ability::Delete, $post)` where Post extends Model implements TenantScoped:
 *
 * 1. **Guards** (deny-only, type-specific):
 *    Post guards → TenantScoped guards → Model guards
 *    If any guard returns false → deny immediately
 *
 * 2. **Handlers** (allow/deny, type-specific):
 *    Post → TenantScoped → Model → fallback
 *
 * 3. **Default**: allow (if no handler responds)
 *
 * ## Guards (Cross-Cutting Security)
 *
 * ```php
 * // Guards run FIRST and can only deny or pass
 * $auth->guard(TenantScoped::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     $entity = $q->instance();
 *     if ($entity && $entity->tenant_id !== auth()->getClaim('tenant_id')) {
 *         return false;  // Deny - wrong tenant
 *     }
 *     return null;  // Pass - continue checking
 * });
 * ```
 *
 * ## Handlers
 *
 * ```php
 * // Handlers run after guards pass
 * $auth->for(User::class)->listen(function(AuthorizationQuery $q): ?bool {
 *     return match ($q->ability) {
 *         Ability::List => auth()->isAuthenticated(),
 *         Ability::Create => auth()->hasRole('admin'),
 *         Ability::Read => true,
 *         Ability::Update, Ability::Delete =>
 *             $q->instance()?->id === auth()->getUserId() || auth()->hasRole('admin'),
 *         default => null,
 *     };
 * });
 * ```
 */
class Authorization
{
    /** @var array<string, Handler<AuthorizationQuery, bool>> */
    private array $guards = [];

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
     * Get or create guard for a specific resource
     *
     * Guards run BEFORE normal handlers and can only deny (return false) or pass (return null).
     * Use guards for cross-cutting security concerns like tenant isolation.
     *
     * Guards follow the same type specificity as handlers but run in a separate phase:
     * 1. All guards are checked first (can deny)
     * 2. Then normal handlers are checked (can allow or deny)
     *
     * @param string $resource Class name or resource identifier
     * @return Handler<AuthorizationQuery, bool>
     */
    public function guard(string $resource): Handler
    {
        return $this->guards[$resource] ??= new Handler("authorization-guard:$resource");
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
     * Execution order:
     * 1. Guards (deny-only, type-specific) - if any returns false, deny immediately
     * 2. Handlers (allow/deny, type-specific)
     * 3. Fallback handler
     * 4. Default: allow
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

        // Phase 1: Guards (deny-only)
        foreach ($this->walkClassHierarchy($class) as $type) {
            if (isset($this->guards[$type])) {
                $result = $this->guards[$type]->trigger($query);
                if ($result === true) {
                    throw new \LogicException(
                        "Guard for '$type' returned true. Guards can only deny (false) or pass (null), not allow."
                    );
                }
                if ($result === false) {
                    return false; // Guard denied
                }
            }
        }

        // Phase 2: Normal handlers
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
