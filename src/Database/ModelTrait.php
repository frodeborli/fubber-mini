<?php

namespace mini\Database;

use mini\Exceptions\AccessDeniedException;
use mini\Mini;
use mini\Validator\Purpose;
use mini\Validator\ValidationError;
use mini\Validator\ValidatorStore;

use function mini\db;

/**
 * Active Record pattern for entity persistence
 *
 * Provides save(), delete(), find(), and query() methods for entities.
 * Automatically handles:
 * - Dehydration (entity → array) via Dehydrator
 * - Validation via WriteValidator (if validation attributes are present)
 * - Identity tracking (correctly detects insert vs update even if PK changes)
 * - Authorization filtering (override query() to restrict access)
 *
 * Safe vs Unsafe methods:
 * - query(), find(), save(), delete() - apply authorization filtering
 * - queryUnsafe(), findUnsafe(), saveUnsafe(), deleteUnsafe() - bypass authorization
 *
 * To add authorization, override query() to filter based on current user:
 * ```php
 * public static function query(): PartialQuery {
 *     return static::queryUnsafe()->eq('user_id', auth()->userId());
 * }
 * ```
 *
 * Example:
 * ```php
 * class User {
 *     use ModelTrait;
 *
 *     #[Required]
 *     public string $email;
 *
 *     #[MinLength(3)]
 *     public string $name;
 *
 *     public ?int $id = null;
 *
 *     protected static function tableName(): string { return 'users'; }
 *     protected static function primaryKey(): string { return 'id'; }
 * }
 *
 * // Create
 * $user = new User();
 * $user->email = 'john@example.com';
 * $user->name = 'John';
 * $user->save(); // INSERT, sets $user->id
 *
 * // Update
 * $user->name = 'John Doe';
 * $user->save(); // UPDATE
 *
 * // Find
 * $user = User::find(123);
 *
 * // Query
 * $admins = User::query()->eq('role', 'admin')->limit(10);
 *
 * // Delete
 * $user->delete();
 * ```
 */
trait ModelTrait
{
    /**
     * Tracks the original primary key value from when entity was loaded.
     * Used to correctly detect insert vs update even if PK is changed.
     */
    private mixed $_modelOriginalId = null;

    /**
     * Get the table name for this entity
     */
    abstract protected static function tableName(): string;

    /**
     * Get the primary key column name
     */
    abstract protected static function primaryKey(): string;

    /**
     * Get the database connection
     *
     * Override to use vdb() or other DatabaseInterface implementations.
     */
    protected static function database(): DatabaseInterface
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
    // Unsafe methods - raw database access, no authorization filtering
    // =========================================================================

    /**
     * Create a query builder without authorization filtering
     *
     * Use this for system operations (CLI, migrations, background jobs)
     * or when you need to bypass user-based row filtering.
     */
    public static function queryUnsafe(): PartialQuery
    {
        $db = static::database();
        $table = $db->quoteIdentifier(static::tableName());

        return $db->query("SELECT * FROM {$table}")
            ->withEntityClass(static::class, false)
            ->withLoadCallback(fn(object $entity) => static::markLoaded($entity));
    }

    /**
     * Find an entity by primary key without authorization filtering
     */
    public static function findUnsafe(mixed $id): ?static
    {
        return static::queryUnsafe()
            ->eq(static::primaryKey(), $id)
            ->limit(1)
            ->one();
    }

    /**
     * Save entity without authorization check
     *
     * Still validates before persisting. Throws ValidationException if invalid.
     *
     * @return int Number of affected rows
     * @throws \mini\ValidationException If validation fails
     */
    public function saveUnsafe(): int
    {
        $pk = static::primaryKey();
        $entityClass = static::class;
        $db = static::database();

        // Dehydrate entity to array
        $data = Dehydrator::dehydrate($this);

        // Determine if this is an insert or update based on original identity
        $isUpdate = $this->getOriginalPrimaryKey() !== null;

        if ($isUpdate) {
            // UPDATE: validate merged state
            $currentData = [];
            $current = static::findUnsafe($this->getOriginalPrimaryKey());
            if ($current !== null) {
                $currentData = Dehydrator::dehydrate($current);
            }
            WriteValidator::validateUpdate($entityClass, $currentData, $data);

            // Don't update the PK column itself
            unset($data[$pk]);

            $affected = $db->update(
                static::queryUnsafe()->eq($pk, $this->getOriginalPrimaryKey())->limit(1),
                $data
            );

            // Update tracked identity if PK changed
            if (isset($this->{$pk})) {
                $this->_modelOriginalId = $this->{$pk};
            }
        } else {
            // INSERT: validate new entity
            WriteValidator::validateInsert($entityClass, $data);

            // Don't try to insert null/empty PK
            unset($data[$pk]);

            $newId = $db->insert(static::tableName(), $data);

            // Set the generated ID on the entity
            $this->{$pk} = $newId;
            $this->_modelOriginalId = $newId;

            $affected = 1;
        }

        return $affected;
    }

    /**
     * Delete entity without authorization check
     *
     * @return int Number of affected rows
     * @throws \RuntimeException If entity has no identity
     */
    public function deleteUnsafe(): int
    {
        $pk = static::primaryKey();

        // Use original ID if available, fall back to current PK
        $id = $this->getOriginalPrimaryKey() ?? $this->{$pk} ?? null;

        if ($id === null) {
            throw new \RuntimeException("Cannot delete entity without primary key");
        }

        $affected = static::database()->delete(
            static::queryUnsafe()->eq($pk, $id)->limit(1)
        );

        // Clear identity after deletion
        $this->_modelOriginalId = null;

        return $affected;
    }

    // =========================================================================
    // Safe methods - with authorization filtering
    // Override query() to add user-based row filtering
    // =========================================================================

    /**
     * Create a query builder with authorization filtering
     *
     * Override this method to filter rows based on the current user:
     * ```php
     * public static function query(): PartialQuery {
     *     return static::queryUnsafe()->eq('user_id', auth()->userId());
     * }
     * ```
     *
     * By default, no filtering is applied (same as queryUnsafe).
     */
    public static function query(): PartialQuery
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
            ->eq(static::primaryKey(), $id)
            ->limit(1)
            ->one();
    }

    /**
     * Save entity with authorization check
     *
     * For updates, verifies the current user has access to the entity
     * via the filtered query() method before saving.
     *
     * @return int Number of affected rows
     * @throws AccessDeniedException If not authorized to update this entity
     * @throws \mini\ValidationException If validation fails
     */
    public function save(): int
    {
        $originalId = $this->getOriginalPrimaryKey();

        if ($originalId !== null) {
            // UPDATE: verify user can access this row via filtered query
            if (static::query()->eq(static::primaryKey(), $originalId)->one() === null) {
                throw new AccessDeniedException("Not authorized to update this entity");
            }
        }

        return $this->saveUnsafe();
    }

    /**
     * Delete entity with authorization check
     *
     * Verifies the current user has access to the entity
     * via the filtered query() method before deleting.
     *
     * @return int Number of affected rows
     * @throws AccessDeniedException If not authorized to delete this entity
     * @throws \RuntimeException If entity has no identity
     */
    public function delete(): int
    {
        $pk = static::primaryKey();
        $id = $this->getOriginalPrimaryKey() ?? $this->{$pk} ?? null;

        if ($id !== null) {
            // Verify user can access this row via filtered query
            if (static::query()->eq($pk, $id)->one() === null) {
                throw new AccessDeniedException("Not authorized to delete this entity");
            }
        }

        return $this->deleteUnsafe();
    }

    // =========================================================================
    // Validation
    // =========================================================================

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
     * Mark an entity as loaded from the database
     *
     * Called by PartialQuery when hydrating entities.
     * Sets the original identity for correct insert/update detection.
     *
     * @internal
     */
    private static function markLoaded(object $entity): void
    {
        $pk = static::primaryKey();
        if (property_exists($entity, '_modelOriginalId') && isset($entity->{$pk})) {
            $entity->_modelOriginalId = $entity->{$pk};
        }
    }
}
