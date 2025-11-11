<?php

namespace mini\Converter;

/**
 * Interface for type converters
 *
 * Converters transform values from one type to another, enabling automatic
 * conversion of route return values, exceptions, and other types to appropriate
 * response formats.
 *
 * Implementations must:
 * - Specify input type via getInputType() (may be union like "string|array")
 * - Specify output type via getOutputType() (single type only)
 * - Implement supports() to check if input can be converted to target
 * - Implement convert() to perform the actual transformation
 *
 * Most applications should register typed closures rather than implementing
 * this interface directly. ClosureConverter wraps closures automatically.
 *
 * Example implementation:
 * ```php
 * class JsonableConverter implements ConverterInterface {
 *     public function getInputType(): string {
 *         return Jsonable::class;
 *     }
 *
 *     public function getOutputType(): string {
 *         return ResponseInterface::class;
 *     }
 *
 *     public function supports(mixed $input, string $targetType): bool {
 *         return $input instanceof Jsonable
 *             && ($targetType === ResponseInterface::class
 *                 || is_subclass_of($targetType, ResponseInterface::class));
 *     }
 *
 *     public function convert(mixed $input, string $targetType): mixed {
 *         $json = $input->toJson();
 *         return new Response(200, ['Content-Type' => 'application/json'], $json);
 *     }
 * }
 * ```
 *
 * However, using a closure is simpler:
 * ```php
 * $registry->register(function(Jsonable $obj): ResponseInterface {
 *     $json = $obj->toJson();
 *     return new Response(200, ['Content-Type' => 'application/json'], $json);
 * });
 * ```
 *
 * @template I The input type this converter accepts
 * @template O The output type this converter produces
 * @see ClosureConverter For closure-based converters
 * @see ConverterRegistry For converter registration
 */
interface ConverterInterface
{
    /**
     * Get the input type this converter accepts
     *
     * May be a single type or union type string (e.g., "string|array|int").
     *
     * @return string Type or union type string
     */
    public function getInputType(): string;

    /**
     * Get the output type this converter produces
     *
     * @return string Fully qualified class name
     */
    public function getOutputType(): string;

    /**
     * Check if this converter can handle the given input for target type
     *
     * @param mixed $input The value to potentially convert
     * @param string $targetType The desired output type
     * @return bool
     */
    public function supports(mixed $input, string $targetType): bool;

    /**
     * Convert the input to the target type
     *
     * @param I $input The value to convert
     * @param class-string<O> $targetType The desired output type
     * @return O The converted value
     */
    public function convert(mixed $input, string $targetType): mixed;
}
