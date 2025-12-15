<?php
namespace mini\Table;

use Closure;
use mini\Table\Index\IndexInterface;
use mini\Table\Index\TreapIndex;

/**
 * Index factory and key encoding utilities.
 *
 * For bulk builds (load once, query many times):
 * ```php
 * $index = Index::fromGenerator(function() use ($table) {
 *     foreach ($table as $rowId => $row) {
 *         yield [Index::packInt($row['age']), $rowId];
 *     }
 * });
 * ```
 *
 * For dynamic insert-heavy workloads, use LsmIndex directly:
 * ```php
 * $index = new LsmIndex();
 * $index->insert(Index::packInt($age), $rowId);
 * ```
 *
 * Query:
 * ```php
 * foreach ($index->eq(Index::packInt(25)) as $rowId) { ... }
 * foreach ($index->range(Index::packInt(18), Index::packInt(65)) as $rowId) { ... }
 * ```
 */
final class Index
{
    private function __construct() {} // Factory only - not instantiable

    /**
     * Build index from a generator function.
     * Generator should yield [string $key, int $rowId] pairs.
     */
    public static function fromGenerator(Closure $fn): IndexInterface
    {
        return TreapIndex::fromGenerator($fn);
    }

    /**
     * Build index from array of [key, rowId] pairs.
     */
    public static function fromArray(array $rows): IndexInterface
    {
        return TreapIndex::fromArray($rows);
    }

    // =========================================================================
    // Static packing helpers (sortable binary encoding)
    // =========================================================================

    /**
     * Pack integer to 8-byte sortable binary
     */
    public static function packInt(int $n): string
    {
        $hi = ($n >> 32) & 0xFFFFFFFF;
        $lo = $n & 0xFFFFFFFF;
        $hi ^= 0x80000000; // Flip sign bit for sortability
        return pack('N2', $hi, $lo);
    }

    /**
     * Unpack 8-byte binary to integer
     */
    public static function unpackInt(string $data): int
    {
        $parts = unpack('N2', $data);
        $hi = $parts[1] ^ 0x80000000; // Restore sign bit
        $lo = $parts[2];
        return ($hi << 32) | $lo;
    }

    /**
     * Pack float to 8-byte sortable binary
     */
    public static function packFloat(float $f): string
    {
        $bin = pack('E', $f); // Big-endian IEEE-754
        $bytes = array_values(unpack('C8', $bin));

        if (($bytes[0] & 0x80) !== 0) {
            // Negative: invert all bits
            for ($i = 0; $i < 8; $i++) {
                $bytes[$i] ^= 0xFF;
            }
        } else {
            // Positive: flip sign bit
            $bytes[0] ^= 0x80;
        }

        return pack('C8', ...$bytes);
    }

    /**
     * Unpack 8-byte binary to float
     */
    public static function unpackFloat(string $data): float
    {
        $bytes = array_values(unpack('C8', $data));

        if (($bytes[0] & 0x80) === 0) {
            // Was negative: invert all bits back
            for ($i = 0; $i < 8; $i++) {
                $bytes[$i] ^= 0xFF;
            }
        } else {
            // Was positive: flip sign bit back
            $bytes[0] ^= 0x80;
        }

        $bin = pack('C8', ...$bytes);
        return unpack('E', $bin)[1];
    }

    /**
     * Pack string with optional max length (for fixed-width keys)
     */
    public static function packString(string $s, ?int $maxLength = null): string
    {
        if ($maxLength !== null && strlen($s) > $maxLength) {
            return substr($s, 0, $maxLength);
        }
        return $s;
    }
}
