<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Set metadata examples annotation
 *
 * @see \mini\Metadata\Metadata::examples()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Examples
{
    public array $examples;

    public function __construct(mixed ...$examples)
    {
        $this->examples = $examples;
    }
}
