<?php

namespace mini\Database;

/**
 * Interface for entities with custom hydration (loading from database)
 *
 * Implement when you need custom logic for converting database rows to entities,
 * such as computed properties, column renaming, or nested object construction.
 */
interface Hydratable
{
    /**
     * Create instance from a database row
     *
     * @param object $row Database row (typically stdClass from PDO)
     * @return static
     */
    public static function fromSqlRow(object $row): static;
}
