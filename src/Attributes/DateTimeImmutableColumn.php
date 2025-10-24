<?php

namespace mini\Attributes;

use Attribute;
use mini\Contracts\CodecStrategyInterface;
use mini\Contracts\FieldCodecInterface;
use mini\CodecStrategies\ScalarCodecStrategy;
use mini\CodecStrategies\SQLiteCodecStrategy;

/**
 * DATETIME column attribute for DateTimeImmutable objects
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DateTimeImmutableColumn extends Column
{
    public function __construct(
        ?string $name = null,
        ?int $fractionalSeconds = null,
        bool $nullable = false,
        mixed $default = null,
        ?string $format = null
    ) {
        parent::__construct(
            name: $name,
            type: 'datetime',
            nullable: $nullable,
            default: $default,
            fractionalSeconds: $fractionalSeconds,
            format: $format ?: 'date-time'
        );
    }

    /**
     * Override to return appropriate DateTimeImmutable codec based on strategy
     */
    public function createCodec(CodecStrategyInterface $strategy, \ReflectionProperty $property): ?FieldCodecInterface
    {
        $fieldName = $this->getColumnName('unknown');

        // Use parent implementation - strategy now has access to property type
        return parent::createCodec($strategy, $property);
    }

    /**
     * Override JSON schema to allow datetime objects in validation
     */
    public function getJsonSchema(): array
    {
        $schema = parent::getJsonSchema();

        // For datetime immutable columns, we allow both string and object types
        // This accommodates both the storage string format and the PHP object format
        $schema['type'] = ['string', 'object'];

        return $schema;
    }
}