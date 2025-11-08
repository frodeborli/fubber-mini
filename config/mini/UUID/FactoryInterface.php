<?php

/**
 * Default UUID factory configuration, generates UUID version 7 which
 * are essentially UUID version 4 with a 48 bit time component. This
 * ensures that keys are time sortable which is a great benefit for
 * database index performance.
 *
 * To use a different UUID generation strategy, create a custom factory
 * in your application at: `_config/mini/UUID/FactoryInterface.php` that
 * returns a factory
 *
 * Example custom factory:
 * ```php
 * <?php
 * return new class implements \mini\UUID\FactoryInterface {
 *     public function make(): string {
 *         // Use UUID v1, Snowflake, COMB, or custom logic
 *         return '...';
 *     }
 * };
 * ```
 */
return new \mini\UUID\UUID7Factory();
