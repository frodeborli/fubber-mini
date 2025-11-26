<?php
namespace mini\Database;

/**
 * Repository pattern with static methods for POPO entities
 *
 * Use this trait when you want to keep entities as POPOs (Plain Old PHP Objects)
 * and manage persistence through a separate repository class.
 *
 * @template T of object
 *
 * Example:
 * ```php
 * // POPO entity - no framework dependencies
 * class User {
 *     public ?int $id = null;
 *     public string $name;
 *
 *     public function isActive(): bool {
 *         return $this->status === 'active';
 *     }
 * }
 *
 * // Repository with persistence logic
 * /**
 *  * @use RepositoryTrait<User>
 *  *\/
 * class Users {
 *     use RepositoryTrait;
 *
 *     protected static function getTableName(): string { return 'users'; }
 *     protected static function getPrimaryKey(): string { return 'id'; }
 *     protected static function getEntityClass(): string { return User::class; }
 *     protected static function dehydrate(object $entity): array {
 *         return ['id' => $entity->id, 'name' => $entity->name];
 *     }
 * }
 *
 * $user = new User();
 * $user->name = 'John';
 * Users::persist($user); // INSERT
 *
 * $found = Users::find(1);
 * $found->name = 'Updated';
 * Users::persist($found); // UPDATE
 *
 * Users::remove($found);
 * ```
 */
trait RepositoryTrait {
    use ActiveRecordTrait;

    /**
     * Save an entity (INSERT if new, UPDATE if exists)
     *
     * @param object $entity Entity to save
     * @return int Number of affected rows
     */
    public static function persist(object $entity): int {
        return static::persistEntity($entity);
    }

    /**
     * Delete an entity
     *
     * @param object $entity Entity to delete
     * @return int Number of affected rows
     */
    public static function remove(object $entity): int {
        return static::removeEntity($entity);
    }
}
