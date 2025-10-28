<?php

namespace mini\Tables\CodecStrategies;

use mini\Tables\Codecs\CodecStrategyInterface;
use mini\Tables\Codecs\FieldCodecInterface;
use mini\Attributes\Column;

/**
 * Codec strategy for MySQL database backend
 *
 * MySQL has good native type support including:
 * - INTEGER types (TINYINT, SMALLINT, INT, BIGINT)
 * - FLOAT, DOUBLE, DECIMAL for numbers
 * - VARCHAR, TEXT for strings
 * - DATETIME, DATE, TIME, TIMESTAMP for dates
 * - JSON type (MySQL 5.7+)
 * - TINYINT(1) for booleans (0/1)
 *
 * Most PHP types map naturally to MySQL types, so fewer conversions are needed.
 */
final class MySQLCodecStrategy implements CodecStrategyInterface
{
    public function getCodecFor(Column $column, \ReflectionProperty $property): ?FieldCodecInterface
    {
        // MySQL has good native type support, similar to PostgreSQL
        // Booleans are stored as TINYINT(1) and automatically convert
        // JSON is natively supported in MySQL 5.7+
        // DateTime types are natively supported
        // Most conversions are handled by the base codec system
        return null;
    }

    public function getBackendName(): string
    {
        return 'mysql';
    }
}
