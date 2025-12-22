<?php

namespace mini\Table\Types;

/**
 * Column data type for comparison semantics
 *
 * The primary purpose is determining whether string collation should be
 * used for comparisons. Numeric and datetime types use simple binary
 * comparison (<=>), while text columns need locale-aware collation.
 */
enum ColumnType
{
    /** Integer values - binary comparison */
    case Int;

    /** Floating point values - binary comparison */
    case Float;

    /** Arbitrary precision decimal values stored as strings - custom comparison */
    case Decimal;

    /** Text values - uses collator for locale-aware comparison */
    case Text;

    /** Date values stored as text (YYYY-MM-DD) - binary comparison */
    case Date;

    /** Time values stored as text (HH:MM:SS) - binary comparison */
    case Time;

    /** Date/time values stored as text - binary comparison (ISO 8601 sorts correctly) */
    case DateTime;

    /** Binary/blob data or case-sensitive strings - binary comparison */
    case Binary;

    /**
     * Whether this column type should use locale-aware collation for sorting
     */
    public function shouldUseCollator(): bool
    {
        return $this === self::Text;
    }

    /**
     * Whether this column type stores numeric values
     */
    public function isNumeric(): bool
    {
        return $this === self::Int || $this === self::Float || $this === self::Decimal;
    }

    /**
     * Whether this column type requires custom comparison (not native PHP <=>)
     */
    public function needsCustomComparison(): bool
    {
        return $this === self::Text || $this === self::Decimal;
    }

    /**
     * Get the SQL column type name
     *
     * Returns the base SQL type. For types with parameters (like DECIMAL),
     * use sqlTypeWithParams() to include precision/scale.
     */
    public function sqlType(): string
    {
        return match ($this) {
            self::Int => 'INTEGER',
            self::Float => 'REAL',
            self::Decimal => 'DECIMAL',
            self::Text => 'TEXT',
            self::Date => 'DATE',
            self::Time => 'TIME',
            self::DateTime => 'DATETIME',
            self::Binary => 'BLOB',
        };
    }
}
