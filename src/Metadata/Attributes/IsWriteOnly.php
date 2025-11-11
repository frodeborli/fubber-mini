<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Mark field as write-only in metadata
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
