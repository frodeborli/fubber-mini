<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Mark field as read-only in metadata
 *
 * Query: `metadata(User::class)->$prop?->isReadOnly()` — never use ReflectionClass directly.
 *
 * @see \mini\Metadata\Metadata::readOnly()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class IsReadOnly
{
    public function __construct(
        public bool $value = true
    ) {}
}
