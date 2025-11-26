<?php

namespace mini\Database\Attributes;

/**
 * Excludes property from database mapping
 *
 * Inspired by Entity Framework Core's [NotMapped] attribute.
 *
 * Use for computed properties or properties that should not be persisted.
 *
 * Example:
 * ```php
 * #[NotMapped]
 * public string $fullName;
 *
 * #[NotMapped]
 * public array $cachedData = [];
 * ```
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class NotMapped
{
}
