<?php

namespace mini\Metadata\Attributes;

use Attribute;
use Stringable;

/**
 * Set metadata title annotation
 *
 * @see \mini\Metadata\Metadata::title()
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class Title
{
    public function __construct(
        public Stringable|string $title
    ) {}
}
