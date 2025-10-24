<?php

namespace mini\CodecStrategies;

use mini\Contracts\CodecStrategyInterface;
use mini\Contracts\FieldCodecInterface;
use mini\Attributes\Column;
use mini\Attributes\EnumColumn;

/**
 * Codec strategy for SQLite database backend
 *
 * SQLite has limited native types (NULL, INTEGER, REAL, TEXT, BLOB)
 * so most type conversions are needed.
 */
final class SQLiteCodecStrategy implements CodecStrategyInterface
{
    public function getCodecFor(Column $column, \ReflectionProperty $property): ?FieldCodecInterface
    {
        $fieldName = $column->getColumnName('unknown');

        // Get PHP type from property reflection
        $phpType = $this->getPropertyType($property);

        // Check for registered codecs first
        $registeredCodec = \mini\codecs()->get($phpType);
        if ($registeredCodec) {
            return $this->createCodecFromRegistration($registeredCodec, $fieldName);
        }

        // No registered codec found - SQLite can store everything as strings
        // so no conversion needed for primitive types
        return null;
    }

    /**
     * Get PHP type from property reflection
     */
    private function getPropertyType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            // For union types, try to find the first non-null type
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof \ReflectionNamedType && $unionType->getName() !== 'null') {
                    return $unionType->getName();
                }
            }
        }

        // Fallback to 'mixed' if type can't be determined
        return 'mixed';
    }

    /**
     * Create FieldCodecInterface from registered codec
     */
    private function createCodecFromRegistration(object $codec, string $fieldName): ?FieldCodecInterface
    {
        // SQLite prefers string backend (stores everything as strings)
        if ($codec instanceof \mini\Codecs\StringCodecInterface) {
            return new \mini\Codecs\StringCodecWrapper($codec, $fieldName);
        }

        // Fallback to integer if string not supported
        if ($codec instanceof \mini\Codecs\IntegerCodecInterface) {
            return new \mini\Codecs\IntegerCodecWrapper($codec, $fieldName);
        }

        // If codec doesn't support scalar backends, we can't use it
        return null;
    }

    public function getBackendName(): string
    {
        return 'sqlite';
    }
}