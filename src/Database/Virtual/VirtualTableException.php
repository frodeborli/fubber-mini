<?php

namespace mini\Database\Virtual;

/**
 * Exception thrown when a virtual table implementation violates requirements
 *
 * This is thrown when:
 * - Virtual table doesn't yield row IDs as keys
 * - Row IDs are not unique
 * - Invalid OrderInfo is yielded
 * - Other implementation contract violations
 */
class VirtualTableException extends \RuntimeException
{
    /**
     * Create exception for yielding non-Row object
     */
    public static function notRowInstance(string $tableName, int $rowNumber, mixed $value): self
    {
        $type = get_debug_type($value);
        return new self(
            "Virtual table '$tableName' violated implementation contract: " .
            "Row #$rowNumber yielded $type instead of Row instance. " .
            "Virtual tables MUST yield Row instances (e.g., 'yield new Row(\$id, \$columns)'). " .
            "This is required for UPDATE/DELETE operations to work correctly."
        );
    }

    /**
     * Create exception for duplicate row ID
     */
    public static function duplicateRowId(string $tableName, string|int $rowId): self
    {
        return new self(
            "Virtual table '$tableName' violated implementation contract: " .
            "Duplicate row ID '$rowId' was yielded. " .
            "Row IDs MUST be unique within the result set."
        );
    }

    /**
     * Create exception for invalid OrderInfo
     */
    public static function invalidOrderInfo(string $tableName, string $reason): self
    {
        return new self(
            "Virtual table '$tableName' yielded invalid OrderInfo: $reason"
        );
    }
}
