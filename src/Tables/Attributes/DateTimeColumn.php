<?php

namespace mini\Tables\Attributes;

use Attribute;
use mini\Tables\Codecs\CodecStrategyInterface;
use mini\Tables\Codecs\FieldCodecInterface;
use mini\Tables\CodecStrategies\ScalarCodecStrategy;
use mini\Tables\CodecStrategies\SQLiteCodecStrategy;

/**
 * DATETIME column attribute for date and time storage
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DateTimeColumn extends Column
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
     * Override to return appropriate DateTime codec based on strategy
     */
    public function createCodec(CodecStrategyInterface $strategy, \ReflectionProperty $property): ?FieldCodecInterface
    {
        $fieldName = $this->getColumnName('unknown');

        // Use parent implementation - strategy now has access to property type
        return parent::createCodec($strategy, $property);
    }
}
