<?php
namespace mini\Database;

/**
 * Active Record trait for database models
 *
 * Provides Eloquent-style CRUD operations for database entities.
 * Can be used directly on entity classes or on separate repository classes.
 *
 * @template T of object
 *
 * Usage with entity class:
 * ```php
 * class User {
 *     use ModelTrait;
 *
 *     public ?int $id = null;
 *     public string $name;
 *
 *     protected static function getTableName(): string { return 'users'; }
 *     protected static function getPrimaryKey(): string { return 'id'; }
 *     protected static function getEntityClass(): string { return self::class; }
 *     protected function dehydrate(): array { return ['id' => $this->id, 'name' => $this->name]; }
 * }
 *
 * $user = new User();
 * $user->name = 'John';
 * $user->save();
 * ```
 *
 * Usage with separate repository class (POPO pattern):
 * ```php
 * class User {
 *     public ?int $id = null;
 *     public string $name;
 * }
 *
 * /**
 *  * @use ModelTrait<User>
 *  *\/
 * class Users {
 *     use ModelTrait;
 *
 *     protected static function getTableName(): string { return 'users'; }
 *     protected static function getPrimaryKey(): string { return 'id'; }
 *     protected static function getEntityClass(): string { return User::class; }
 *
 *     protected static function dehydrate(object $entity): array {
 *         return ['id' => $entity->id, 'name' => $entity->name];
 *     }
 * }
 *
 * $user = new User();
 * $user->name = 'John';
 * Users::persist($user);  // INSERT
 *
 * $found = Users::find(1);
 * $found->name = 'Updated';
 * Users::persist($found);  // UPDATE
 *
 * Users::remove($found);  // DELETE
 * ```
 */
trait ModelTrait {
    abstract protected static function getTableName(): string;
    abstract protected static function getPrimaryKey(): string;
    abstract protected static function getEntityClass(): string;

    /**
     * @return PartialQuery<T>
     */
    public static function query(): PartialQuery {
        return db()->table(static::getTableName())
            ->withHydrator(static::getEntityClass(), false);
    }

    /**
     * @return T|null
     */
    public static function find(mixed $id): ?object {
        $pk = static::getPrimaryKey();
        return static::query()
            ->eq($pk, $id)
            ->limit(1)
            ->one();
    }

    /**
     * Convert entity to array for database operations
     *
     * @param object $entity The entity to dehydrate
     * @return array Associative array of column => value
     */
    abstract protected static function dehydrate(object $entity): array;

    /**
     * Save an entity (INSERT if new, UPDATE if exists) - static method
     *
     * Use this for repository pattern: Users::persist($user)
     *
     * @param object $entity Entity to save
     * @return int Number of affected rows
     */
    public static function persist(object $entity): int {
        $pk = static::getPrimaryKey();
        $data = static::dehydrate($entity);

        // If PK is set and not null, this is an UPDATE
        if (isset($entity->{$pk}) && $entity->{$pk} !== null) {
            $id = $entity->{$pk};
            unset($data[$pk]); // Don't update the PK itself

            $affected = db()->update(
                static::query()->eq($pk, $id)->limit(1),
                $data
            );
        } else {
            // This is an INSERT
            unset($data[$pk]); // Don't try to insert null/empty PK

            $entity->{$pk} = db()->insert(
                static::getTableName(),
                $data
            );

            $affected = 1; // insert() succeeded (would throw otherwise)
        }

        return $affected;
    }

    /**
     * Delete an entity - static method
     *
     * Use this for repository pattern: Users::remove($user)
     *
     * @param object $entity Entity to delete
     * @return int Number of affected rows
     */
    public static function remove(object $entity): int {
        $pk = static::getPrimaryKey();
        if ($entity->{$pk} === null) {
            throw new \RuntimeException("Cannot delete entity without primary key set");
        }

        return db()->delete(
            static::query()->eq($pk, $entity->{$pk})->limit(1)
        );
    }

    /**
     * Save this entity (INSERT if new, UPDATE if exists) - instance method
     *
     * Use this for entity pattern: $user->save()
     *
     * @return int Number of affected rows
     */
    public function save(): int {
        return static::persist($this);
    }

    /**
     * Delete this entity - instance method
     *
     * Use this for entity pattern: $user->delete()
     *
     * @return int Number of affected rows
     */
    public function delete(): int {
        return static::remove($this);
    }
}