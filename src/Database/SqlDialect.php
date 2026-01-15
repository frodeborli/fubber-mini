<?php

namespace mini\Database;

/**
 * SQL dialect enumeration for database-specific SQL generation
 *
 * Dialects represent different SQL syntax variations across database vendors.
 * Use the most specific dialect when possible, fallback to Generic for unknown databases.
 *
 * @see DatabaseInterface::getDialect()
 */
enum SqlDialect
{
    /**
     * MySQL / MariaDB dialect
     *
     * Key differences:
     * - LIMIT syntax: LIMIT offset, count (non-standard)
     * - Identifier quotes: backticks `table_name`
     * - Case sensitivity: varies by storage engine and OS
     * - Non-standard functions and extensions
     */
    case MySQL;

    /**
     * PostgreSQL dialect
     *
     * Key differences:
     * - LIMIT syntax: LIMIT count OFFSET offset (SQL standard)
     * - Identifier quotes: double quotes "table_name"
     * - Case sensitive by default
     * - Strong standards compliance with useful extensions
     */
    case Postgres;

    /**
     * SQLite dialect
     *
     * Key differences:
     * - LIMIT syntax: LIMIT count OFFSET offset (SQL standard)
     * - Identifier quotes: double quotes "table_name" (also accepts backticks)
     * - Very permissive type system (dynamic typing)
     * - Simplified feature set (no RIGHT JOIN, limited ALTER TABLE)
     */
    case Sqlite;

    /**
     * Microsoft SQL Server dialect
     *
     * Key differences:
     * - LIMIT syntax: OFFSET offset ROWS FETCH NEXT count ROWS ONLY (SQL:2008)
     *   (Pre-2012 versions require ROW_NUMBER() windowing - not supported here)
     * - Identifier quotes: square brackets [table_name]
     * - TOP keyword for simple limits
     * - Significantly different from other databases
     */
    case SqlServer;

    /**
     * Oracle Database dialect
     *
     * Key differences:
     * - LIMIT syntax: Modern (12c+) uses OFFSET/FETCH, older uses ROWNUM
     * - Identifier quotes: double quotes "table_name"
     * - VARCHAR2, NUMBER types instead of VARCHAR, NUMERIC
     * - Unique handling of NULL vs empty strings
     */
    case Oracle;

    /**
     * Generic ANSI SQL dialect (fallback for unknown databases)
     *
     * Uses SQL:2016 standard syntax where possible:
     * - LIMIT syntax: LIMIT count OFFSET offset
     * - Identifier quotes: double quotes "table_name"
     * - Standard string escaping
     *
     * Use this for:
     * - Unknown/unsupported database vendors
     * - Testing and prototyping
     * - Maximum portability (may not use vendor-specific optimizations)
     */
    case Generic;

    /**
     * Virtual database dialect (in-memory, CSV, API backends)
     *
     * Key differences:
     * - No CTE/WITH support
     * - No JOIN support
     * - LIMIT syntax: LIMIT count OFFSET offset
     * - Identifier quotes: double quotes "table_name"
     * - Subqueries supported via lazy evaluation
     *
     * Used by VirtualDatabase for non-SQL data sources.
     */
    case Virtual;

    /**
     * Get the recommended identifier quote character for this dialect
     */
    public function getIdentifierQuote(): string
    {
        return match($this) {
            self::MySQL => '`',
            self::SqlServer => '[',  // Opening bracket, closing is ']'
            self::Postgres, self::Sqlite, self::Oracle, self::Generic, self::Virtual => '"',
        };
    }

    /**
     * Check if this dialect uses standard LIMIT/OFFSET syntax
     */
    public function usesStandardLimit(): bool
    {
        return match($this) {
            self::MySQL => false,  // Uses LIMIT offset, count
            self::SqlServer => false,  // Uses OFFSET/FETCH
            self::Postgres, self::Sqlite, self::Oracle, self::Generic, self::Virtual => true,
        };
    }

    /**
     * Check if this dialect supports subqueries (e.g., IN (SELECT ...))
     */
    public function supportsSubquery(): bool
    {
        return true;
    }

    /**
     * Check if this dialect supports EXCEPT/INTERSECT set operations
     */
    public function supportsExcept(): bool
    {
        return match($this) {
            self::MySQL => false,  // MySQL doesn't support EXCEPT/INTERSECT
            self::Postgres, self::Sqlite, self::SqlServer, self::Oracle, self::Generic, self::Virtual => true,
        };
    }

    /**
     * Get human-readable dialect name
     */
    public function getName(): string
    {
        return match($this) {
            self::MySQL => 'MySQL',
            self::Postgres => 'PostgreSQL',
            self::Sqlite => 'SQLite',
            self::SqlServer => 'SQL Server',
            self::Oracle => 'Oracle',
            self::Generic => 'Generic ANSI SQL',
            self::Virtual => 'Virtual Database',
        };
    }
}
