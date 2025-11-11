<?php

namespace mini\Metadata\Attributes;

use Attribute;
use Stringable;

/**
 * Define metadata for a property on a class/interface without an actual property
 *
 * Useful for interfaces, DTOs, or when you want to define metadata
 * schema without actual properties on the class.
 *
 * This mirrors Validator\Attributes\Field for metadata purposes.
 *
 * @example
 * #[Property(name: 'username', title: 'Username', description: 'User login identifier', readOnly: true)]
 * #[Property(name: 'email', title: 'Email Address', format: 'email')]
 * interface User {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Property
{
    /** @var array<mixed> */
    public array $examples;

    public function __construct(
        public string $name,
        public Stringable|string|null $title = null,
        public Stringable|string|null $description = null,
        public mixed $default = null,
        public ?bool $readOnly = null,
        public ?bool $writeOnly = null,
        public ?bool $deprecated = null,
        public ?string $format = null,
        mixed ...$examples
    ) {
        $this->examples = $examples;
    }
}
