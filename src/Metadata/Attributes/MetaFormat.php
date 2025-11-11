<?php

namespace mini\Metadata\Attributes;

use Attribute;

/**
 * Set format hint in metadata
 *
 * Note: Named MetaFormat to distinguish from Validator\Attributes\Format
 * Since both can be used on the same property, the distinction is important.
 *
 * @see \mini\Metadata\Metadata::format()
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MetaFormat
{
    public function __construct(
        public string $format
    ) {}
}
