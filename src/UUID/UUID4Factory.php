<?php

namespace mini\UUID;

/**
 * Generates UUID v4 identifiers using cryptographically secure randomness.
 *
 * UUID v4 is a 128-bit identifier formatted as a 36-character string:
 * `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`
 *
 * Where:
 * - `x` is any hexadecimal digit (0-9, a-f)
 * - `y` is one of 8, 9, a, or b (representing the variant bits)
 * - The `4` indicates UUID version 4
 *
 * This implementation uses `random_bytes()` for cryptographic randomness
 * and requires no configuration or machine identification.
 *
 * ## Specification Details
 *
 * Per RFC 4122:
 * - Version field (4 bits): Set to 0100 (binary) = 4
 * - Variant field (2 bits): Set to 10 (binary) = 8, 9, a, or b in hex
 * - Remaining 122 bits: Cryptographically random
 *
 * ## Example Output
 *
 * ```
 * 550e8400-e29b-41d4-a716-446655440000
 * ```
 */
class UUID4Factory implements FactoryInterface {
    /**
     * Generate a cryptographically secure UUID v4.
     *
     * @return string A UUID v4 string in standard format
     * @throws \Random\RandomException If random_bytes() fails
     */
    public function make(): string {
        $hex = bin2hex($bytes = random_bytes(18));
        $hex[8] = '-';
        $hex[13] = '-';
        $hex[14] = '4';
        $hex[18] = '-';
        $hex[19] = '89ab'[ord($bytes[9]) >> 6];
        $hex[23] = '-';
        return $hex;
    }
}
