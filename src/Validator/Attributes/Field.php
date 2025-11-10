<?php

namespace mini\Validator\Attributes;

use Attribute;

/**
 * Define a field validator on a class/interface without a property
 *
 * Useful for interfaces, DTOs, or when you want to define validation
 * schema without actual properties on the class.
 *
 * @example
 * #[Field(name: 'username', type: 'string', minLength: 8, required: true)]
 * #[Field(name: 'email', type: 'string', format: 'email', required: true)]
 * interface LoginForm {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Field
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|null $exclusiveMinimum = null,
        public int|float|null $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,
        public ?string $pattern = null,
        public ?string $format = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
        public ?bool $required = null,
        public mixed $const = null,
        public ?array $enum = null,
        public ?string $message = null
    ) {}
}
