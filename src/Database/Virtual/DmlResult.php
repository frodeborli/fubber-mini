<?php

namespace mini\Database\Virtual;

/**
 * Result from INSERT/UPDATE/DELETE operations
 */
final class DmlResult
{
    /**
     * @param int $affectedRows Number of rows affected
     * @param array|null $generatedIds Generated IDs for INSERT operations
     */
    public function __construct(
        public int $affectedRows,
        public ?array $generatedIds = null
    ) {
    }
}
