<?php

namespace mini\Metadata\Attributes;

use Attribute;
use Stringable;

/**
 * Set metadata description annotation
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
