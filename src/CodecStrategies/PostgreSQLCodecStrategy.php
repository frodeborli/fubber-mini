<?php

namespace mini\CodecStrategies;

use mini\Contracts\CodecStrategyInterface;
use mini\Contracts\FieldCodecInterface;
use mini\Attributes\Column;

/**
 * Codec strategy for PostgreSQL database backend
 *
 * PostgreSQL has rich native type support including boolean, JSON/JSONB,
 * timestamps, etc., so fewer conversions are needed.
 */
final class PostgreSQLCodecStrategy implements CodecStrategyInterface
{
    public function getCodecFor(Column $column, \ReflectionProperty $property): ?FieldCodecInterface
    {
        // PostgreSQL strategy now relies on ScalarCodecStrategy for codec selection
        // This strategy can be enhanced later to provide PostgreSQL-specific optimizations
        return null;
    }

    public function getBackendName(): string
    {
        return 'postgresql';
    }
}