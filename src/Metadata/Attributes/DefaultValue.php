<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Set metadata default value annotation
 *
 * Note: Named DefaultValue to avoid conflict with PHP's reserved keyword 'default'
 *
 * @see \mini\Metadata\Metadata::default()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DefaultValue
{
    public function __construct(
        public mixed $default
    ) {}
}
