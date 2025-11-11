<?php
/**
 * Default ConverterRegistryInterface configuration for Mini framework
 *
 * Applications can override by creating _config/mini/Converter/ConverterRegistryInterface.php
 * to provide a custom converter registry implementation.
 *
 * This factory creates the registry instance. Converter registration (what types to convert)
 * is the responsibility of other features (Router, Dispatcher, etc.) and applications.
 * Applications should register their converters during bootstrap, not in config:
 *
 * ```php
 * // bootstrap.php (NOT in config)
 * use mini\Mini;
 * use mini\Converter\ConverterRegistryInterface;
 *
 * $registry = Mini::$mini->get(ConverterRegistryInterface::class);
 *
 * // Register application-specific converters
 * $registry->register(function(MyModel $model): ResponseInterface {
 *     return new Response(200, ['Content-Type' => 'application/json'], $model->toJson());
 * });
 * ```
 *
 * Example custom registry implementation:
 * ```php
 * class LoggingConverterRegistry extends ConverterRegistry {
 *     public function convert(mixed $input, string $targetType): mixed {
 *         error_log("Converting " . get_debug_type($input) . " to $targetType");
 *         return parent::convert($input, $targetType);
 *     }
 * }
 * return new LoggingConverterRegistry();
 * ```
 */

use mini\Converter\ConverterRegistry;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

return new ConverterRegistry();
