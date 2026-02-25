<?php

namespace mini\Database;

use mini\Authorizer\Ability;
use mini\Exceptions\AccessDeniedException;
use mini\Mini;
use mini\Validator\Purpose;
use mini\Validator\ValidationError;
use mini\Validator\ValidatorStore;

use function mini\can;
use function mini\model;

/**
 * Abstract base class for database entities (Active Record pattern)
 *
 * Provides save(), delete(), find(), and query() methods for entities.
 * Table name is detected from #[Table] attribute via model().
 * Primary key is detected from #[PrimaryKey] attribute via model() (defaults to 'id').
 *
 * Automatically handles:
 * - Dehydration (entity → array) via Dehydrator (includes #[CreatedAt]/#[UpdatedAt] timestamps)
 * - Validation via WriteValidator (if validation attributes are present)
 * - Identity tracking (correctly detects insert vs update even if PK changes)
 * - Authorization via can() system (override provideCanList/Create/Read/Update/Delete)
 *
 * Safe vs Unsafe methods:
 * - saveUnsafe(), deleteUnsafe() are **final** — guaranteed persistence layer
 * - save(), delete() use two-layer auth: can() + updatable()/deletable() row scoping
 * - query(), find() are **overridable** — default pass-through to unsafe methods
 * - updatable(), deletable() — row-level write scoping (default to query())
 * - queryUnsafe(), findUnsafe() are **final** — raw database access
 *
 * Override query() to filter rows by user permissions:
 * ```php
 * public static function query(): Query {
 *     auth()->requireLogin();
 *     return static::queryUnsafe()->eq('org_id', auth()->getClaim('org_id'));
 * }
 * ```
 *
 * Override provideCanUpdate/Delete etc. for action authorization:
 * ```php
 * public function provideCanUpdate(): ?bool {
 *     return $this->user_id === auth()->getUserId();
 * }
 * ```
 *
 * ```php
 * #[Table('users')]
 * class User extends Model
 * {
 *     #[PrimaryKey]
 *     public ?int $id = null;
 *
 *     public string $email = '';
 *     public string $name = '';
 * }
 * ```
 */
abstract class Model
{
    /**
     * Tracks the original primary key value from when entity was loaded.
     * Used to correctly detect insert vs update even if PK is changed.
     */
    private mixed $_modelOriginalId = null;

    /**
     * Whether this entity has been deleted from the database.
     * Prevents accidental re-insertion of deleted entities.
     */
    private bool $_modelDeleted = false;

    /**
     * Snapshot of property values from when the entity was loaded or last saved.
     * Used for dirty tracking — null means entity was never loaded (i.e., it's new).
     */
    private ?array $_modelOriginalData = null;

    /**
     * Get the database connection
     *
     * Override to use vdb() or other DatabaseInterface implementations.
     * The provide* prefix signals this is a framework declaration point.
     */
    protected static function provideDatabase(): DatabaseInterface
    {
        return Mini::$mini->get(DatabaseInterface::class);
    }

    /**
     * Get the original primary key from when entity was loaded.
     *
     * Returns null for new (unsaved) entities.
     * Used internally for insert vs update detection and authorization checks.
     */
    protected function getOriginalPrimaryKey(): mixed
    {
        return $this->_modelOriginalId;
    }

    // =========================================================================
    // Authorization declaration points
    //
    // Override these to control who can perform actions on this entity.
    // Return true to allow, false to deny, null for no opinion (→ default allow).
    // The provide* prefix signals these are framework declaration points.
    // =========================================================================

    /** Can the current user list entities of this class? */
    public static function provideCanList(): ?bool { return null; }

    /** Can the current user create new entities of this class? */
    public static function provideCanCreate(): ?bool { return null; }

    /** Can the current user read this entity? */
    public function provideCanRead(): ?bool { return null; }

    /** Can the current user update this entity? */
    public function provideCanUpdate(): ?bool { return null; }

    /** Can the current user delete this entity? */
    public function provideCanDelete(): ?bool { return null; }

    // =========================================================================
    // Unsafe methods - raw database access, no authorization filtering
    // =========================================================================

    /**
     * Create a query builder without authorization filtering
     *
     * Use this for system operations (CLI, migrations, background jobs)
     * or when you need to bypass user-based row filtering.
     */
    public static final function queryUnsafe(): Query
    {
        $db = static::provideDatabase();
        $table = $db->quoteIdentifier(model(static::class)->tableName);

        return $db->query("SELECT * FROM {$table}")
            ->withEntityClass(static::class)
            ->withLoadCallback(fn(object $entity) => $entity->markLoaded());
    }

    /**
     * Find an entity by primary key without authorization filtering
     */
    public static final function findUnsafe(mixed $id): ?static
    {
        return static::queryUnsafe()
            ->eq(model(static::class)->primaryKey, $id)
            ->limit(1)
            ->one();
    }

    /**
     * Save entity without authorization check — the guaranteed persistence layer
     *
     * Handles timestamps, dehydration, validation, and the actual INSERT/UPDATE.
     * Cannot be overridden — override save() instead for custom logic.
     *
     * @param string[]|null $only Only save these properties (null = all). Filters the
     *     columns written to the database. Validation still runs against full entity state.
     * @param Query|null $scope Query scope for the UPDATE target. Defaults to queryUnsafe().
     *     Used by save() to pass updatable() scope, making the row-level check atomic with the write.
     * @return int Number of affected rows
     * @throws \mini\ValidationException If validation fails
     */
    public final function saveUnsafe(?array $only = null, ?Query $scope = null): int
    {
        if ($this->_modelDeleted) {
            throw new \LogicException(
                "Cannot save a deleted entity. Create a new instance instead."
            );
        }

        $info = model(static::class);
        $pk = $info->primaryKey;
        $entityClass = static::class;
        $db = static::provideDatabase();
        $data = Dehydrator::dehydrate($this);
        $isUpdate = $this->getOriginalPrimaryKey() !== null;

        // Detect primary key mutation — always a programming error
        if ($this->_modelOriginalId !== null && $this->_modelOriginalId !== $this->{$pk}) {
            $from = var_export($this->_modelOriginalId, true);
            $to = var_export($this->{$pk}, true);
            throw new \LogicException(
                "Primary key '{$pk}' was changed from {$from} to {$to} on {$entityClass}. "
                . "Delete and re-insert instead of mutating the primary key."
            );
        }

        if ($isUpdate) {

            // Fetch current DB state for validation
            $currentData = [];
            $current = static::findUnsafe($this->getOriginalPrimaryKey());
            if ($current !== null) {
                $currentData = Dehydrator::dehydrate($current);
            }
            WriteValidator::validateUpdate($entityClass, $currentData, $data);

            unset($data[$pk]);

            // Partial save: only write specified columns
            if ($only !== null) {
                $data = array_intersect_key($data, array_flip($only));
            }

            $updateQuery = ($scope ?? static::queryUnsafe())
                ->eq($pk, $this->getOriginalPrimaryKey())
                ->limit(1);
            $affected = $db->update($updateQuery, $data);

            if (isset($this->{$pk})) {
                $this->_modelOriginalId = $this->{$pk};
            }
        } else {
            WriteValidator::validateInsert($entityClass, $data);

            if ($data[$pk] === null) {
                // Auto-increment: let DB generate the PK
                unset($data[$pk]);
                $newId = $db->insert($info->tableName, $data);
                $this->{$pk} = $newId;
            } else {
                // Developer-supplied PK (e.g. UUID): include in INSERT
                $db->insert($info->tableName, $data);
            }

            $this->_modelOriginalId = $this->{$pk};
            $affected = 1;
        }

        // Re-snapshot so entity is clean after save
        $this->_modelOriginalData = $this->_snapshotProperties();

        return $affected;
    }

    /**
     * Delete entity without authorization check — the guaranteed persistence layer
     *
     * Cannot be overridden — override delete() instead for custom logic.
     *
     * @param Query|null $scope Query scope for the DELETE target. Defaults to queryUnsafe().
     *     Used by delete() to pass deletable() scope, making the row-level check atomic with the write.
     * @return int Number of affected rows
     * @throws \RuntimeException If entity has no identity
     */
    public final function deleteUnsafe(?Query $scope = null): int
    {
        if ($this->_modelDeleted) {
            throw new \LogicException("Cannot delete an already deleted entity.");
        }

        $pk = model(static::class)->primaryKey;
        $id = $this->getOriginalPrimaryKey() ?? $this->{$pk} ?? null;

        if ($id === null) {
            throw new \RuntimeException("Cannot delete entity without primary key");
        }

        $deleteQuery = ($scope ?? static::queryUnsafe())->eq($pk, $id)->limit(1);
        $affected = static::provideDatabase()->delete($deleteQuery);

        if ($affected > 0) {
            $this->_modelOriginalId = null;
            $this->_modelDeleted = true;
        }

        return $affected;
    }

    // =========================================================================
    // Safe methods - with authorization
    // Override query() for row-level scoping, providecan*() for action authorization
    // =========================================================================

    /**
     * Create a query builder with authorization filtering
     *
     * Override this method to filter rows based on the current user:
     * ```php
     * public static function query(): Query {
     *     return static::queryUnsafe()->eq('user_id', auth()->userId());
     * }
     * ```
     *
     * By default, no filtering is applied (same as queryUnsafe).
     */
    public static function query(): Query
    {
        return static::queryUnsafe();
    }

    /**
     * Find an entity by primary key with authorization filtering
     *
     * Returns null if entity doesn't exist OR if current user lacks access.
     */
    public static function find(mixed $id): ?static
    {
        return static::query()
            ->eq(model(static::class)->primaryKey, $id)
            ->limit(1)
            ->one();
    }

    /**
     * Row-level scope for updates — which rows can be updated?
     *
     * Defaults to query() (same as read scoping). Override to diverge:
     * ```php
     * public static function updatable(): Query {
     *     return static::queryUnsafe()->eq('owner_id', auth()->getUserId());
     * }
     * ```
     */
    public static function updatable(): Query
    {
        return static::query();
    }

    /**
     * Row-level scope for deletes — which rows can be deleted?
     *
     * Defaults to query() (same as read scoping). Override to diverge:
     * ```php
     * public static function deletable(): Query {
     *     return static::queryUnsafe()->eq('owner_id', auth()->getUserId());
     * }
     * ```
     */
    public static function deletable(): Query
    {
        return static::query();
    }

    /**
     * Save entity with authorization
     *
     * Two layers:
     * 1. can() — action authorization (provideCanUpdate/provideCanCreate)
     * 2. updatable() — row-level write scoping (entity must be reachable)
     *
     * @param string[]|null $only Only save these properties (null = all)
     * @return int Number of affected rows
     * @throws AccessDeniedException If not authorized
     * @throws \mini\ValidationException If validation fails
     */
    public function save(?array $only = null): int
    {
        $originalId = $this->getOriginalPrimaryKey();

        if ($originalId !== null) {
            if (!can(Ability::Update, $this)) {
                throw new AccessDeniedException("Not authorized to update this entity");
            }
            // Row-level scoping is atomic with the UPDATE — if the row isn't
            // reachable via updatable(), the UPDATE affects 0 rows.
            $affected = $this->saveUnsafe($only, static::updatable());
            if ($affected === 0) {
                throw new AccessDeniedException("Not authorized to update this entity");
            }
            return $affected;
        }

        if (!can(Ability::Create, static::class)) {
            throw new AccessDeniedException("Not authorized to create this entity");
        }

        return $this->saveUnsafe($only);
    }

    /**
     * Delete entity with authorization
     *
     * Two layers:
     * 1. can() — action authorization (provideCanDelete)
     * 2. deletable() — row-level write scoping (entity must be reachable)
     *
     * @return int Number of affected rows
     * @throws AccessDeniedException If not authorized
     * @throws \RuntimeException If entity has no identity
     */
    public function delete(): int
    {
        if (!can(Ability::Delete, $this)) {
            throw new AccessDeniedException("Not authorized to delete this entity");
        }

        // Row-level scoping is atomic with the DELETE — if the row isn't
        // reachable via deletable(), the DELETE affects 0 rows.
        $affected = $this->deleteUnsafe(static::deletable());
        if ($affected === 0) {
            throw new AccessDeniedException("Not authorized to delete this entity");
        }
        return $affected;
    }

    // =========================================================================
    // Validation & dirty tracking
    // =========================================================================

    /**
     * Check which properties have changed since the entity was loaded (or last saved).
     *
     * Returns null if the entity is clean or was never loaded from the database
     * (new entities are not considered dirty — they have no original state).
     *
     * Returns an associative array of original values for changed properties:
     *   ['name' => 'Old Name', 'email' => 'old@example.com']
     *
     * The caller can get the new values from the entity itself.
     *
     * @return array<string, mixed>|null Original values of changed properties, or null if clean
     */
    public function isDirty(): ?array
    {
        if ($this->_modelOriginalData === null) {
            return null;
        }

        $dirty = [];
        $refClass = new \ReflectionClass($this);

        foreach ($refClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }

            $name = $prop->getName();

            if (!array_key_exists($name, $this->_modelOriginalData)) {
                continue;
            }

            $current = $prop->isInitialized($this) ? $prop->getValue($this) : null;
            $original = $this->_modelOriginalData[$name];

            if ($current !== $original) {
                $dirty[$name] = $original;
            }
        }

        return $dirty ?: null;
    }

    /**
     * Check if entity is valid without saving
     *
     * Auto-detects Create vs Update purpose based on entity state.
     * Runs purpose-scoped validation first, then core validation.
     *
     * @return ValidationError|null Null if valid, ValidationError if invalid
     */
    public function isInvalid(): ?ValidationError
    {
        $data = Dehydrator::dehydrate($this);
        $store = Mini::$mini->get(ValidatorStore::class);
        $purpose = $this->getOriginalPrimaryKey() !== null
            ? Purpose::Update
            : Purpose::Create;

        // Purpose-scoped validation
        $error = $store->get(static::class, $purpose)->isInvalid($data);
        if ($error !== null) {
            return $error;
        }

        // Core validation
        return $store->get(static::class)->isInvalid($data);
    }

    // =========================================================================
    // Internal
    // =========================================================================

    /**
     * Reset identity on clone — cloned entity is treated as new (unsaved)
     */
    public function __clone(): void
    {
        $this->_modelOriginalId = null;
        $this->_modelOriginalData = null;
        $this->_modelDeleted = false;
    }

    /**
     * Mark this entity as loaded from the database
     *
     * Called by PartialQuery when hydrating entities.
     * Sets the original identity for correct insert/update detection
     * and snapshots property values for dirty tracking.
     *
     * @internal
     */
    private function markLoaded(): void
    {
        $pk = model(static::class)->primaryKey;
        if (isset($this->{$pk})) {
            $this->_modelOriginalId = $this->{$pk};
        }
        $this->_modelOriginalData = $this->_snapshotProperties();
    }

    /**
     * Snapshot all public property values for dirty tracking
     */
    private function _snapshotProperties(): array
    {
        $data = [];
        $refClass = new \ReflectionClass($this);

        foreach ($refClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            if ($prop->isInitialized($this)) {
                $data[$prop->getName()] = $prop->getValue($this);
            }
        }

        return $data;
    }
}
