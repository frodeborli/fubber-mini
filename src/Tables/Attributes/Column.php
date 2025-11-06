<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Base column attribute for database field mapping and validation
 *
 * Supports JSON Schema validation and storage hints for cross-database portability.
 * Uses modern JSON Schema draft where exclusiveMinimum/Maximum are numbers, not booleans.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,        // 'string'|'integer'|'number'|'boolean'|'datetime'|'json'|'binary'
        public bool $nullable = false,
        public mixed $default = null,

        // Storage hints for backend optimization
        public ?int $length = null,         // VARCHAR length, etc.
        public ?int $precision = null,      // DECIMAL precision
        public ?int $scale = null,          // DECIMAL scale
        public ?int $fractionalSeconds = null, // DATETIME fractional seconds

        // JSON Schema validation (modern draft - exclusives are numbers)
        public ?float $minimum = null,
        public ?float $maximum = null,
        public ?float $exclusiveMinimum = null,  // Exclusive bound value, not boolean
        public ?float $exclusiveMaximum = null,  // Exclusive bound value, not boolean
        public ?float $multipleOf = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public ?string $format = null,      // 'email', 'date-time', etc.
        public ?array $enum = null,

        // Array validation
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public bool $uniqueItems = false,
        public ?array $items = null,        // Schema for array items

        // Object validation
        public ?array $properties = null,   // Schema for object properties
        public ?array $required = null,     // Required object properties
    ) {}

    /**
     * Get the database column name (defaults to property name)
     */
    public function getColumnName(string $propertyName): string
    {
        return $this->name ?? $propertyName;
    }

    /**
     * Get JSON Schema validation rules
     */
    public function getJsonSchema(): array
    {
        $schema = array_filter([
            'type' => $this->getSchemaType(),
            'nullable' => $this->nullable ? true : null,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'exclusiveMinimum' => $this->exclusiveMinimum,
            'exclusiveMaximum' => $this->exclusiveMaximum,
            'multipleOf' => $this->multipleOf,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'format' => $this->format,
            'enum' => $this->enum,
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'uniqueItems' => $this->uniqueItems ? true : null,
            'items' => $this->items,
            'properties' => $this->properties,
            'required' => $this->required,
        ], fn($value) => $value !== null);

        // Remove uniqueItems if false (default)
        if (isset($schema['uniqueItems']) && $schema['uniqueItems'] === false) {
            unset($schema['uniqueItems']);
        }

        return $schema;
    }

    /**
     * Get the JSON Schema type for this column
     */
    protected function getSchemaType(): ?string
    {
        return match($this->type) {
            'string', 'datetime', 'binary' => 'string',
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            'json' => 'object',
            default => null
        };
    }

    /**
     * Create appropriate codec for this column using strategy pattern
     */
    public function createCodec(\mini\Tables\Codecs\CodecStrategyInterface $strategy, \ReflectionProperty $property): ?\mini\Tables\Codecs\FieldCodecInterface
    {
        return $strategy->getCodecFor($this, $property);
    }

    /**
     * Get storage hints for backend optimization
     */
    public function getStorageHints(): array
    {
        return array_filter([
            'length' => $this->length,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'fractionalSeconds' => $this->fractionalSeconds,
        ], fn($value) => $value !== null);
    }
}
