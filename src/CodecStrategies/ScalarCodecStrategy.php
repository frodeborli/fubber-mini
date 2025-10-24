<?php

namespace mini\CodecStrategies;

use mini\Contracts\CodecStrategyInterface;
use mini\Contracts\FieldCodecInterface;
use mini\Attributes\Column;
use mini\Codecs\StringCodecWrapper;
use mini\Codecs\IntegerCodecWrapper;

/**
 * Codec strategy for scalar data sources (CSV, JSON, etc.)
 *
 * Converts all types to/from strings since scalar sources typically store everything as strings.
 * This provides full attribute parity with DatabaseRepository while handling string-based backends.
 */
final class ScalarCodecStrategy implements CodecStrategyInterface
{
    public function getCodecFor(Column $column, \ReflectionProperty $property): ?FieldCodecInterface
    {
        $fieldName = $column->getColumnName('unknown');
        $phpType = $this->getPropertyType($property);

        // Check for registered codecs first
        $registeredCodec = \mini\codecs()->get($phpType);
        if ($registeredCodec) {
            return $this->createCodecFromRegistration($registeredCodec, $fieldName);
        }

        // No registered codec found and no built-in fallback available
        // The property type doesn't have a registered codec, which is expected
        // for primitive types (string, int, float, bool) that don't need conversion
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
        // ScalarCodecStrategy prefers string backend (everything is strings in CSV/JSON)
        if ($codec instanceof \mini\Codecs\StringCodecInterface) {
            return new StringCodecWrapper($codec, $fieldName);
        }

        // Fallback to integer if string not supported
        if ($codec instanceof \mini\Codecs\IntegerCodecInterface) {
            return new IntegerCodecWrapper($codec, $fieldName);
        }

        // If codec doesn't support scalar backends, we can't use it
        return null;
    }
}