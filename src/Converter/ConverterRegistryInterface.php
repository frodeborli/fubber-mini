<?php

namespace mini\Converter;

/**
 * Interface for converter registry implementations
 *
 * The converter registry manages type converters that transform values from
 * one type to another. The primary use case is converting route return values
 * and exceptions to HTTP responses, but the system is general-purpose.
 *
 * Applications register converters during bootstrap:
 * ```php
 * // bootstrap.php
 * use mini\Mini;
 * use mini\Converter\ConverterRegistryInterface;
 *
 * $registry = Mini::$mini->get(ConverterRegistryInterface::class);
 *
 * // Register custom converter
 * $registry->register(function(MyModel $model): ResponseInterface {
 *     return new Response(200, ['Content-Type' => 'application/json'], $model->toJson());
 * });
 * ```
 *
 * The registry provides:
 * - Type-safe converter registration via typed closures
 * - Union input type support (single converter handles multiple types)
 * - Specificity resolution (single > union, class > interface > parent)
 * - Conflict detection for overlapping registrations
 *
 * @see ConverterInterface For implementing custom converter classes
 * @see ClosureConverter For closure-based converters (automatic wrapping)
 */
interface ConverterRegistryInterface
{
    /**
     * Register a converter
     *
     * Accepts either a ConverterInterface implementation or a typed closure.
     * Closures are automatically wrapped in ClosureConverter.
     *
     * Closure requirements:
     * - Exactly one typed parameter (may be union type: string|array)
     * - Typed return value (single type only, no unions or null)
     * - No null in input or output types
     *
     * Examples:
     * ```php
     * // Simple converter
     * $registry->register(function(string $text): ResponseInterface {
     *     return new Response(200, ['Content-Type' => 'text/plain'], $text);
     * });
     *
     * // Union input type
     * $registry->register(function(string|array $data): ResponseInterface {
     *     if (is_string($data)) {
     *         return new Response(200, ['Content-Type' => 'text/plain'], $data);
     *     }
     *     $json = json_encode($data);
     *     return new Response(200, ['Content-Type' => 'application/json'], $json);
     * });
     *
     * // Custom object converter
     * $registry->register(function(MyModel $model): ResponseInterface {
     *     return new Response(200, ['Content-Type' => 'application/json'], $model->toJson());
     * });
     * ```
     *
     * @param ConverterInterface|\Closure $converter Converter instance or typed closure
     * @throws \InvalidArgumentException If converter conflicts with existing registration
     * @throws \InvalidArgumentException If closure signature is invalid
     */
    public function register(ConverterInterface|\Closure $converter): void;

    /**
     * Check if a converter exists for input to target type
     *
     * @param mixed $input The value to convert
     * @param class-string $targetType The desired output type
     * @return bool True if a suitable converter exists
     */
    public function has(mixed $input, string $targetType): bool;

    /**
     * Get the converter for input to target type
     *
     * Returns the most specific converter based on type hierarchy:
     * 1. Direct single-type converter (most specific)
     * 2. Union type converter (less specific)
     * 3. Parent class converters
     * 4. Interface converters
     *
     * @param mixed $input The value to convert
     * @param class-string $targetType The desired output type
     * @return ConverterInterface|null The converter, or null if none found
     */
    public function get(mixed $input, string $targetType): ?ConverterInterface;

    /**
     * Convert a value to target type
     *
     * Finds the most specific converter and performs the conversion.
     * Returns null if no suitable converter found (does not throw exceptions).
     *
     * @template O
     * @param mixed $input The value to convert
     * @param class-string<O> $targetType The desired output type
     * @return O|null The converted value, or null if no converter found
     */
    public function convert(mixed $input, string $targetType): mixed;
}
