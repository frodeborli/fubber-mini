<?php

namespace mini;

use mini\UUID\FactoryInterface;

// Register UUID factory service
Mini::$mini->addService(FactoryInterface::class, Lifetime::Singleton, fn() => Mini::$mini->loadServiceConfig(FactoryInterface::class));

/**
 * Generate a new UUID/GUID.
 *
 * By default, generates a UUID v7 (time-ordered + cryptographically random).
 * Can be customized by providing a factory implementation at:
 *
 * `_config/mini/UUID/FactoryInterface.php`
 *
 * ## Example Usage
 *
 * ```php
 * $id = uuid();  // "018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c"
 * ```
 *
 * ## Custom Factory Example
 *
 * ```php
 * // _config/mini/UUID/FactoryInterface.php
 * return new class implements \mini\UUID\FactoryInterface {
 *     public function make(): string {
 *         // Custom UUID generation logic
 *         return '...';
 *     }
 * };
 * ```
 *
 * @return string A UUID string (default: v7 format)
 */
function uuid(): string {
    return Mini::$mini->get(FactoryInterface::class)->make();
}

/**
 * Generate a UUID v4 (cryptographically random).
 *
 * UUID v4 provides 122 bits of cryptographic randomness with no temporal component.
 * Use when you need maximum unpredictability or want to avoid timestamp leakage.
 *
 * ## Example Usage
 *
 * ```php
 * $token = uuid4();  // "550e8400-e29b-41d4-a716-446655440000"
 * ```
 *
 * ## Common Use Cases
 *
 * - API tokens and session identifiers
 * - Password reset tokens
 * - Shareable links (prevents enumeration)
 * - Privacy-sensitive identifiers
 *
 * @return string A UUID v4 string
 */
function uuid4(): string {
    return (new UUID\UUID4Factory())->make();
}

/**
 * Generate a UUID v7 (time-ordered + cryptographically random).
 *
 * UUID v7 combines Unix timestamp (milliseconds) with cryptographic randomness,
 * providing natural chronological ordering and better database index performance.
 *
 * ## Example Usage
 *
 * ```php
 * $id = uuid7();  // "018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c"
 * ```
 *
 * ## Common Use Cases
 *
 * - Database primary keys (better B-tree performance)
 * - Sortable identifiers
 * - Time-range queries
 * - High-volume write operations
 *
 * Note: This is the same as uuid() by default, but calling uuid7() explicitly
 * ensures you get v7 even if the default factory is customized.
 *
 * @return string A UUID v7 string
 */
function uuid7(): string {
    return (new UUID\UUID7Factory())->make();
}
