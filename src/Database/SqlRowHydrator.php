<?php

namespace mini\Database;

/**
 * Interface for classes that can hydrate themselves from a database row
 *
 * Implement this interface on entity classes that need custom hydration logic,
 * such as computed properties, column renaming, or nested object construction.
 *
 * When withEntityClass() is used with a class implementing this interface,
 * fromSqlRow() is called instead of the default reflection-based hydration.
 *
 * ```php
 * class User implements SqlRowHydrator
 * {
 *     public int $id;
 *     public string $fullName;
 *     public Address $address;
 *
 *     public static function fromSqlRow(array $row): static
 *     {
 *         $user = new static();
 *         $user->id = $row['id'];
 *         $user->fullName = $row['first_name'] . ' ' . $row['last_name'];
 *         $user->address = new Address(
 *             $row['street'],
 *             $row['city'],
 *             $row['zip']
 *         );
 *         return $user;
 *     }
 * }
 *
 * // Hydration uses fromSqlRow() automatically
 * $users = User::query()->limit(10);
 * ```
 *
 * @see SqlValueHydrator For hydrating value objects from a single column
 */
interface SqlRowHydrator
{
    /**
     * Create instance from a database row
     *
     * @param array<string, mixed> $row Associative array of column => value
     * @return static
     */
    public static function fromSqlRow(array $row): static;
}
