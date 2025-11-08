<?php

namespace mini\UUID;

/**
 * Interface for UUID/GUID generation factories.
 *
 * Implementations can generate UUIDs using various algorithms (v1, v4, COMB, Snowflake, etc.).
 * The default implementation uses UUID v4 (cryptographically random).
 *
 * To provide a custom factory, create a class implementing this interface and configure it
 * in your application's service configuration file at:
 * `_config/mini/UUID/FactoryInterface.php`
 *
 * Example custom factory configuration:
 * ```php
 * <?php
 * return new class implements \mini\UUID\FactoryInterface {
 *     public function make(): string {
 *         // Your custom UUID generation logic
 *         return '...';
 *     }
 * };
 * ```
 */
interface FactoryInterface {
    /**
     * Generate a new UUID/GUID.
     *
     * @return string A UUID string (format depends on implementation)
     */
    public function make(): string;
}
