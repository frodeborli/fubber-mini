<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Mark field as write-only in metadata
 *
 * Query: `metadata(User::class)->$prop?->isWriteOnly()` — never use ReflectionClass directly.
 *
 * @see \mini\Metadata\Metadata::writeOnly()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class IsWriteOnly
{
    public function __construct(
        public bool $value = true
    ) {}
}
