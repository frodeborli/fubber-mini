<?php

namespace mini\Database;

/**
 * Interface for entities that can hydrate from and dehydrate to database rows
 *
 * Implement this interface on entity classes that need custom hydration/dehydration
 * logic, such as computed properties, column renaming, or nested object construction.
 *
 * When withEntityClass() is used with a class implementing this interface:
 * - fromSqlRow() is called instead of default reflection-based hydration
 * - toSqlRow() is called for insert/update operations instead of reflection
 *
 * ```php
 * class User implements Hydration
 * {
 *     public int $id;
 *     public string $fullName;
 *     public \DateTimeImmutable $createdAt;
 *
 *     public static function fromSqlRow(array $row): static
 *     {
 *         $user = new static();
 *         $user->id = $row['id'];
 *         $user->fullName = $row['first_name'] . ' ' . $row['last_name'];
 *         $user->createdAt = new \DateTimeImmutable($row['created_at']);
 *         return $user;
 *     }
 *
 *     public function toSqlRow(): array
 *     {
 *         // Split fullName back to first/last for storage
 *         $parts = explode(' ', $this->fullName, 2);
 *         return [
 *             'id' => $this->id,
 *             'first_name' => $parts[0],
 *             'last_name' => $parts[1] ?? '',
 *             'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
 *         ];
 *     }
 * }
 * ```
 *
 * @see SqlValueHydrator For hydrating value objects from a single column
 */
interface Hydration
{
    /**
     * Create instance from a database row
     *
     * @param array<string, mixed> $row Associative array of column => value
     * @return static
     */
    public static function fromSqlRow(array $row): static;

    /**
     * Convert instance to a database row
     *
     * Returns an associative array suitable for INSERT/UPDATE operations.
     * Values should be SQL-compatible scalars (strings, ints, floats, bools, null).
     * DateTimeInterface should be formatted as strings, objects as JSON, etc.
     *
     * @return array<string, mixed> Associative array of column => value
     */
    public function toSqlRow(): array;
}
