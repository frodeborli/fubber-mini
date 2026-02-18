<?php

namespace mini\Database;

use mini\Database\Attributes\PrimaryKey;
use mini\Database\Attributes\Table;
use mini\Exceptions\AccessDeniedException;
use mini\Mini;
use mini\Validator\Purpose;
use mini\Validator\ValidationError;
use mini\Validator\ValidatorStore;

/**
 * Abstract base class for database entities (Active Record pattern)
 *
 * Provides save(), delete(), find(), and query() methods for entities.
 * Table name is detected from #[Table] attribute.
 * Primary key is detected from #[PrimaryKey] attribute (defaults to 'id').
 *
 * Automatically handles:
 * - Dehydration (entity → array) via Dehydrator (includes #[CreatedAt]/#[UpdatedAt] timestamps)
 * - Validation via WriteValidator (if validation attributes are present)
 * - Identity tracking (correctly detects insert vs update even if PK changes)
 * - Authorization filtering (override query() to restrict access)
 *
 * Safe vs Unsafe methods:
 * - saveUnsafe(), deleteUnsafe() are **final** — guaranteed persistence layer
 * - save(), delete() are **overridable** — default checks entity is visible via query() before persisting
 * - query(), find() are **overridable** — default pass-through to unsafe methods
 * - queryUnsafe(), findUnsafe() are **final** — raw database access
 *
 * Override query() to filter rows by user permissions. save() and delete()
 * automatically use query() to verify access before writing:
 * ```php
 * public static function query(): Query {
 *     auth()->requireLogin();
 *     return static::queryUnsafe()->eq('org_id', auth()->getClaim('org_id'));
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

    /** @var array<string, string> Cached table names per entity class */
    private static array $_tableNames = [];

    /** @var array<string, string> Cached primary key columns per entity class */
    private static array $_primaryKeys = [];

    /**
     * Get the table name for this entity
     *
     * Default: detected from #[Table] attribute (cached). Override if preferred.
     */
    protected static function tableName(): string
    {
        $class = static::class;

        if (isset(self::$_tableNames[$class])) {
            return self::$_tableNames[$class];
        }

        $refClass = new \ReflectionClass($class);
        $attrs = $refClass->getAttributes(Table::class);

        if (!empty($attrs)) {
            $table = $attrs[0]->newInstance();
            if ($table->name !== null) {
                return self::$_tableNames[$class] = $table->name;
            }
        }

        throw new \RuntimeException(
            $class . ' must have #[Table("name")] attribute or override tableName()'
        );
    }

    /**
     * Get the primary key column name
     *
     * Default: detected from #[PrimaryKey] attribute, falls back to 'id' (cached). Override if preferred.
     */
    protected static function primaryKey(): string
    {
        $class = static::class;

        if (isset(self::$_primaryKeys[$class])) {
            return self::$_primaryKeys[$class];
        }

        $refClass = new \ReflectionClass($class);

        foreach ($refClass->getProperties() as $prop) {
            if (!empty($prop->getAttributes(PrimaryKey::class))) {
                return self::$_primaryKeys[$class] = $prop->getName();
            }
        }

        return self::$_primaryKeys[$class] = 'id';
    }

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
    public static final function queryUnsafe(): Query
    {
        $db = static::database();
        $table = $db->quoteIdentifier(static::tableName());

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
            ->eq(static::primaryKey(), $id)
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
     * @return int Number of affected rows
     * @throws \mini\ValidationException If validation fails
     */
    public final function saveUnsafe(?array $only = null): int
    {
        $pk = static::primaryKey();
        $entityClass = static::class;
        $db = static::database();
        $data = Dehydrator::dehydrate($this);
        $isUpdate = $this->getOriginalPrimaryKey() !== null;

        // Detect primary key mutation — always a programming error
        if ($this->_modelOriginalId !== null && $this->_modelOriginalId !== $this->{$pk}) {
            throw new \LogicException(
                "Primary key '{$pk}' was changed from {$this->_modelOriginalId} to {$this->{$pk}} on {$entityClass}. "
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

            $affected = $db->update(
                static::queryUnsafe()->eq($pk, $this->getOriginalPrimaryKey())->limit(1),
                $data
            );

            if (isset($this->{$pk})) {
                $this->_modelOriginalId = $this->{$pk};
            }
        } else {
            WriteValidator::validateInsert($entityClass, $data);

            if ($data[$pk] === null) {
                // Auto-increment: let DB generate the PK
                unset($data[$pk]);
                $newId = $db->insert(static::tableName(), $data);
                $this->{$pk} = $newId;
            } else {
                // Developer-supplied PK (e.g. UUID): include in INSERT
                $db->insert(static::tableName(), $data);
            }

            $this->_modelOriginalId = $this->{$pk};
            $affected = 1;
        }

        return $affected;
    }

    /**
     * Delete entity without authorization check — the guaranteed persistence layer
     *
     * Cannot be overridden — override delete() instead for custom logic.
     *
     * @return int Number of affected rows
     * @throws \RuntimeException If entity has no identity
     */
    public final function deleteUnsafe(): int
    {
        $pk = static::primaryKey();
        $id = $this->getOriginalPrimaryKey() ?? $this->{$pk} ?? null;

        if ($id === null) {
            throw new \RuntimeException("Cannot delete entity without primary key");
        }

        $affected = static::database()->delete(
            static::queryUnsafe()->eq($pk, $id)->limit(1)
        );

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
            ->eq(static::primaryKey(), $id)
            ->limit(1)
            ->one();
    }

    /**
     * Save entity with authorization check
     *
     * On update, verifies the entity is visible via query() (which may be
     * filtered by user permissions). Override to customize auth logic.
     *
     * @param string[]|null $only Only save these properties (null = all)
     * @return int Number of affected rows
     * @throws AccessDeniedException If entity exists but is not accessible via query()
     * @throws \mini\ValidationException If validation fails
     */
    public function save(?array $only = null): int
    {
        $originalId = $this->getOriginalPrimaryKey();

        if ($originalId !== null) {
            if (static::query()->eq(static::primaryKey(), $originalId)->one() === null) {
                throw new AccessDeniedException("Not authorized to update this entity");
            }
        }

        return $this->saveUnsafe($only);
    }

    /**
     * Delete entity with authorization check
     *
     * Verifies the entity is visible via query() (which may be filtered
     * by user permissions). Override to customize auth logic.
     *
     * @return int Number of affected rows
     * @throws AccessDeniedException If entity exists but is not accessible via query()
     * @throws \RuntimeException If entity has no identity
     */
    public function delete(): int
    {
        $pk = static::primaryKey();
        $id = $this->getOriginalPrimaryKey() ?? $this->{$pk} ?? null;

        if ($id !== null) {
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
     * Mark this entity as loaded from the database
     *
     * Called by PartialQuery when hydrating entities.
     * Sets the original identity for correct insert/update detection.
     *
     * @internal
     */
    private function markLoaded(): void
    {
        $pk = static::primaryKey();
        if (isset($this->{$pk})) {
            $this->_modelOriginalId = $this->{$pk};
        }
    }
}
