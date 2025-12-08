<?php

/**
 * Converter Feature - Type conversion system
 *
 * Provides automatic type conversion with reflection-based type matching.
 * Transform route return values, exceptions, and domain objects to HTTP responses
 * without manual serialization code in every route handler.
 *
 * Features:
 * - Type-safe conversion using PHP's type system via reflection
 * - Union input types (single converter handles multiple input types)
 * - Specificity resolution (single > union, class > interface > parent)
 * - Extensible converter registration for custom types
 * - Returns null when conversion impossible (no exceptions)
 *
 * Common use cases:
 * - Route return value conversion (arrays → JSON, strings → text/plain)
 * - Exception to HTTP response conversion (Throwable → error pages)
 * - Content negotiation (same data → JSON/XML/HTML based on Accept header)
 * - Domain model serialization (custom objects → appropriate response format)
 *
 * @see ConverterRegistry For converter registration and management
 * @see ConverterInterface For implementing custom converters
 * @see README.md For comprehensive documentation and examples
 */

namespace mini;

use mini\Converter\ConverterRegistryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Convert a value to a target type
 *
 * Uses the converter registry to find an appropriate converter for the given
 * input and target type. Returns null if no suitable converter found.
 *
 * The converter system uses reflection-based type matching to find the most
 * specific converter. Resolution order: direct single-type converter, union
 * type converter, parent class converters, interface converters.
 *
 * Examples:
 * ```php
 * // Convert exception to error response
 * $response = convert($exception, ResponseInterface::class);
 *
 * // Convert array to JSON response
 * $response = convert(['data' => 'value'], ResponseInterface::class);
 *
 * // Convert string to text response
 * $response = convert("Hello World", ResponseInterface::class);
 *
 * // Check if conversion happened (distinguishes null result from no converter)
 * $result = convert($value, 'string', $found);
 * if (!$found) {
 *     // No converter registered for this type
 * }
 *
 * // Custom domain objects
 * $response = convert($userModel, ResponseInterface::class);
 * ```
 *
 * Register converters in bootstrap.php:
 * ```php
 * $registry = Mini::$mini->get(ConverterRegistryInterface::class);
 * $registry->register(function(MyModel $m): ResponseInterface { ... });
 * ```
 *
 * @template O
 * @param mixed $input The value to convert
 * @param class-string<O> $targetType The desired output type
 * @param bool|null &$found Set to true if a converter was found and executed, false otherwise
 * @return O|null The converted value, or null if no converter found
 * @see ConverterRegistryInterface::register() For registering custom converters
 */
function convert(mixed $input, string $targetType, ?bool &$found = null): mixed
{
    return Mini::$mini->get(ConverterRegistryInterface::class)->convert($input, $targetType, $found);
}

/**
 * ============================================================================
 * Converter Service Registration
 * ============================================================================
 */

namespace mini\Converter;

use mini\Mini;
use mini\Lifetime;

// Register ConverterRegistryInterface as singleton
Mini::$mini->addService(ConverterRegistryInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(ConverterRegistryInterface::class));
