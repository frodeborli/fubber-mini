<?php

namespace mini\Metadata\Attributes;

use Attribute;
use Stringable;

/**
 * Set metadata description annotation
 *
 * Query: `metadata(User::class)->$prop?->getDescription()` — never use ReflectionClass directly.
 *
 * @see \mini\Metadata\Metadata::description()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class Description
{
    public function __construct(
        public Stringable|string $description
    ) {}
}
