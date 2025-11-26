<?php
namespace mini\Database;

/**
 * Active Record pattern with instance methods
 *
 * Use this trait when you want $entity->save() and $entity->delete() methods
 * directly on your entity classes (traditional Active Record pattern).
 *
 * @template T of object
 *
 * Example:
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
 *     protected static function dehydrate(object $entity): array {
 *         return ['id' => $entity->id, 'name' => $entity->name];
 *     }
 * }
 *
 * $user = new User();
 * $user->name = 'John';
 * $user->save(); // INSERT
 *
 * $user->name = 'Updated';
 * $user->save(); // UPDATE
 *
 * $user->delete();
 * ```
 */
trait ModelTrait {
    use ActiveRecordTrait;

    /**
     * Save this entity (INSERT if new, UPDATE if exists)
     *
     * @return int Number of affected rows
     */
    public function save(): int {
        return static::persistEntity($this);
    }

    /**
     * Delete this entity
     *
     * @return int Number of affected rows
     */
    public function delete(): int {
        return static::removeEntity($this);
    }
}
