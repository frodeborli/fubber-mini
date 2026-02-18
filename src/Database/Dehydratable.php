<?php

namespace mini\Database;

/**
 * Interface for entities with custom dehydration (saving to database)
 *
 * Implement when you need custom logic for converting entities to database rows,
 * such as setting timestamps, normalizing values, or splitting computed properties.
 */
interface Dehydratable
{
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
