<?php

namespace mini\UUID;

/**
 * Generates UUID v7 identifiers using Unix timestamp (milliseconds) + cryptographically secure randomness.
 *
 * UUID v7 is a 128-bit time-ordered identifier formatted as a 36-character string:
 * `xxxxxxxx-xxxx-7xxx-yxxx-xxxxxxxxxxxx`
 *
 * Where:
 * - First 48 bits: Unix timestamp in milliseconds (big-endian)
 * - Next 4 bits: Version field (0111 = 7)
 * - Next 12 bits: Random data (rand_a)
 * - Next 2 bits: Variant field (10 = RFC 4122)
 * - Last 62 bits: Random data (rand_b)
 *
 * ## Key Benefits
 *
 * - **Time-ordered**: Naturally sorts by creation time (lexicographically sortable)
 * - **Database-friendly**: Better B-tree index performance than UUID v4
 * - **Future-proof**: Valid until year ~10889 AD
 * - **Unique**: 74 bits of cryptographic randomness per millisecond
 *
 * ## Specification
 *
 * Implements RFC 9562 Section 5.7
 * https://datatracker.ietf.org/doc/rfc9562/
 *
 * ## Example Output
 *
 * ```
 * 018c8f3a-2b4e-7a1c-9f23-4d5e6f7a8b9c
 * ```
 *
 * Note: The first segment changes every ~4 days (2^32 milliseconds).
 */
class UUID7Factory implements FactoryInterface {
    /**
     * Generate a time-ordered UUID v7.
     *
     * @return string A UUID v7 string in standard format
     * @throws \Random\RandomException If random_bytes() fails
     */
    public function make(): string {
        $timestamp = (int)(microtime(true) * 1000);

        // Pack 48-bit timestamp into 52 bits (13 hex chars) with gaps for version nibble,
        // then append 22 hex chars of randomness (11 bytes) for total of 35 chars
        $uuid = dechex(
            0xF0000000000000 |                       // Set high nibble (ensures 14 hex char output)
            (($timestamp << 8) & 0x0FFFFFFF000000) | // Timestamp bits 20-47 → result bits 24-51
            (($timestamp << 4) & 0x000000000FFFF0)   // Timestamp bits 0-19 → result bits 4-23
        ) . bin2hex(random_bytes(11));               // Add 22 random hex chars

        // Overwrite positions with formatting and version/variant bits
        $uuid[0] = '0';                              // Clear the 0xF marker we used
        $uuid[8] = '-';
        $uuid[13] = '-';
        $uuid[14] = '7';                             // Version 7
        $uuid[18] = '-';
        $uuid[19] = '89ab'[ord($uuid[19]) >> 6];     // RFC 4122 variant
        $uuid[23] = '-';

        return $uuid;
    }
}
