<?php

namespace mini\Validator\Attributes;

use Attribute;
use mini\Validator\Purpose;

/**
 * Validate as a URL-friendly slug
 *
 * Composite rule: required, 1-200 chars, lowercase alphanumeric + dashes only,
 * no leading/trailing dashes, no consecutive dashes.
 *
 * ```php
 * #[Slug]
 * public string $slug = '';
 * ```
 *
 * @see \mini\Util\Str::slugify() for generating valid slugs
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Slug
{
    public function __construct(
        public ?string $message = null,
        public Purpose|string|null $purpose = null
    ) {}
}
