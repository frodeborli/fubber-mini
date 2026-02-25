<?php

namespace mini\Database\Attributes;

/**
 * Excludes property from database mapping
 *
 * Query: Parsed internally by Dehydrator — never use ReflectionClass directly.
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
