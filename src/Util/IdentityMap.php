<?php
namespace mini\Util;

/**
 * Identity map pattern implementation with weak references
 *
 * Maintains a bidirectional mapping between unique identifiers and objects,
 * ensuring that only one instance exists for each identifier. Uses weak references
 * to avoid preventing garbage collection of objects that are no longer needed elsewhere.
 *
 * This is commonly used in ORM systems and service containers to ensure that multiple
 * requests for the same entity/service return the exact same object instance (object identity).
 *
 * # Features
 *
 * - **Weak references**: Objects can be garbage collected when no longer referenced elsewhere
 * - **Bidirectional lookup**: Find object by ID or ID by object
 * - **Automatic cleanup**: Dead weak references are periodically removed
 * - **Type-safe**: Generic template ensures type consistency
 *
 * # Usage Example
 *
 * ```php
 * // Create identity map for User objects
 * $map = new IdentityMap();
 *
 * // Store a user
 * $user = new User(id: 123, name: 'John');
 * $map->remember($user, 123);
 *
 * // Later, retrieve by ID - returns the exact same instance
 * $sameUser = $map->tryGet(123);
 * assert($sameUser === $user); // true - same object instance
 *
 * // When all external references are gone, object can be garbage collected
 * unset($user, $sameUser);
 * // Next lookup returns null (object was garbage collected)
 * $map->tryGet(123); // null
 * ```
 *
 * @template T of object The type of objects stored in this identity map
 */
final class IdentityMap
{
    /** @var array<string|int, \WeakReference<T>> Mapping from ID to weak reference */
    private array $byId = [];

    /** @var \WeakMap<T, string|int> Mapping from object to ID (auto-cleaned by PHP) */
    private \WeakMap $byObj;

    /** @var int Operation counter for periodic cleanup */
    private int $ops = 0;

    /** @var int Perform cleanup sweep every N operations */
    private int $sweepEvery;

    /**
     * Create a new identity map
     *
     * @param int $sweepEvery How often to sweep for dead weak references (default: 200 operations)
     *                        Minimum value is 10. Lower values mean more frequent cleanup but
     *                        higher overhead. Higher values mean less overhead but dead references
     *                        linger longer.
     *
     * @example
     * ```php
     * // Default cleanup interval
     * $map = new IdentityMap();
     *
     * // More aggressive cleanup (every 50 operations)
     * $map = new IdentityMap(sweepEvery: 50);
     *
     * // Less frequent cleanup (every 1000 operations)
     * $map = new IdentityMap(sweepEvery: 1000);
     * ```
     */
    public function __construct(int $sweepEvery = 200)
    {
        $this->byObj = new \WeakMap();
        $this->sweepEvery = max(10, $sweepEvery);
    }

    /**
     * Try to retrieve an object by its identifier
     *
     * Returns the object if it exists and hasn't been garbage collected,
     * or null if the object doesn't exist or has been garbage collected.
     *
     * Dead weak references are automatically cleaned up when encountered.
     *
     * @param string|int $id The unique identifier
     * @return T|null The object if found and still alive, null otherwise
     *
     * @example
     * ```php
     * $user = $map->tryGet(123);
     * if ($user !== null) {
     *     // Object exists and is still alive
     *     echo $user->name;
     * } else {
     *     // Object doesn't exist or was garbage collected
     *     $user = new User(id: 123);
     *     $map->remember($user, 123);
     * }
     * ```
     */
    public function tryGet(string|int $id): ?object
    {
        $ref = $this->byId[$id] ?? null;
        if (!$ref) return null;

        $obj = $ref->get();
        if ($obj === null) { // dead, clean lazily
            unset($this->byId[$id]);
            return null;
        }
        $this->tick();
        return $obj;
    }

    /**
     * Store an object with its identifier
     *
     * Associates the given object with the provided identifier using a weak reference.
     * If an object with the same ID already exists, it will be replaced.
     *
     * The object can be garbage collected when all external references are removed,
     * at which point it will automatically be removed from the identity map.
     *
     * @param T $obj The object to store
     * @param string|int $id The unique identifier for this object
     *
     * @example
     * ```php
     * $user = new User(id: 123, name: 'John');
     * $map->remember($user, 123);
     *
     * // Later retrieval returns the exact same instance
     * $sameUser = $map->tryGet(123);
     * assert($sameUser === $user); // true
     *
     * // Can overwrite with new object
     * $newUser = new User(id: 123, name: 'Jane');
     * $map->remember($newUser, 123);
     * ```
     */
    public function remember(object $obj, string|int $id): void
    {
        $this->byId[$id] = \WeakReference::create($obj);
        $this->byObj[$obj] = $id;   // auto-removed when $obj is GC'd
        $this->tick();
    }

    /**
     * Remove an object from the map by its identifier
     *
     * Removes the mapping for the given identifier. The object itself is not
     * affected and will be garbage collected normally when no external references remain.
     *
     * @param string|int $id The identifier to remove
     *
     * @example
     * ```php
     * $map->remember($user, 123);
     * $map->tryGet(123); // Returns $user
     *
     * $map->forgetById(123);
     * $map->tryGet(123); // Returns null
     * ```
     */
    public function forgetById(string|int $id): void
    {
        unset($this->byId[$id]);
    }

    /**
     * Remove an object from the map by the object itself
     *
     * Removes the mapping for the given object. Useful when you have the object
     * but not its identifier.
     *
     * @param T $obj The object to remove
     *
     * @example
     * ```php
     * $user = new User(id: 123);
     * $map->remember($user, 123);
     *
     * // Remove by object reference
     * $map->forgetObject($user);
     * $map->tryGet(123); // Returns null
     * ```
     */
    public function forgetObject(object $obj): void
    {
        $id = $this->byObj[$obj] ?? null;
        if ($id !== null) unset($this->byId[$id], $this->byObj[$obj]);
    }

    private function tick(): void
    {
        if ((++$this->ops % $this->sweepEvery) === 0) $this->sweep();
    }

    private function sweep(): void
    {
        foreach ($this->byId as $id => $ref) {
            if ($ref->get() === null) unset($this->byId[$id]);
        }
    }
}
