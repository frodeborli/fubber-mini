<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Mark field as deprecated in metadata
 *
 * @see \mini\Metadata\Metadata::deprecated()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class IsDeprecated
{
    public function __construct(
        public bool $value = true
    ) {}
}
