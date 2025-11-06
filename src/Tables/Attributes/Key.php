<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Primary key attribute for marking key properties
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Key
{
    public function __construct(
        public bool $autoIncrement = true
    ) {}
}
