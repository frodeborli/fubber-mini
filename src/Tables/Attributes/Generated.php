<?php

namespace mini\Tables\Attributes;

use Attribute;

/**
 * Generated value attribute for database-generated values
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Generated
{
    public function __construct(
        public string $strategy = 'identity'  // 'identity'|'uuid'|'sequence'
    ) {}
}
