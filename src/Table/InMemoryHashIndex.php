<?php
namespace mini\Table;

use Traversable;

/**
 * In-memory hash index using PHP arrays
 *
 * Provides O(1) equality lookups. Supports compound keys via arrays.
 *
 * ```php
 * $index = new InMemoryHashIndex();
 *
 * // Single column index
 * $index->insert(5, 'row1');
 * $index->insert(5, 'row2');
 * foreach ($index->eq(5) as $rowId) { ... }  // yields 'row1', 'row2'
 *
 * // Compound key
 * $index->insert([5, 'active'], 'row1');
 * foreach ($index->eq([5, 'active']) as $rowId) { ... }
 * ```
 */
class InMemoryHashIndex implements HashIndexInterface
{
    /**
     * @var array<string, array<int|string, true>> Normalized key => [rowId => true, ...]
     */
    private array $index = [];

    public function insert(mixed $key, int|string $rowId): void
    {
        $normalizedKey = $this->normalizeKey($key);
        $this->index[$normalizedKey][$rowId] = true;
    }

    public function delete(mixed $key, int|string $rowId): void
    {
        $normalizedKey = $this->normalizeKey($key);
        unset($this->index[$normalizedKey][$rowId]);

        // Clean up empty buckets
        if (empty($this->index[$normalizedKey])) {
            unset($this->index[$normalizedKey]);
        }
    }

    public function eq(mixed $key): Traversable
    {
        $normalizedKey = $this->normalizeKey($key);

        if (!isset($this->index[$normalizedKey])) {
            return;
        }

        foreach ($this->index[$normalizedKey] as $rowId => $_) {
            yield $rowId;
        }
    }

    /**
     * Check if any rows exist for a key (without iterating)
     */
    public function has(mixed $key): bool
    {
        $normalizedKey = $this->normalizeKey($key);
        return !empty($this->index[$normalizedKey]);
    }

    /**
     * Count rows for a key (without iterating)
     */
    public function count(mixed $key): int
    {
        $normalizedKey = $this->normalizeKey($key);
        return count($this->index[$normalizedKey] ?? []);
    }

    /**
     * Normalize key to string for array indexing
     *
     * Handles:
     * - Scalars: converted to type-prefixed string
     * - Arrays: serialized with type info preserved
     * - Null: special marker
     */
    private function normalizeKey(mixed $key): string
    {
        if ($key === null) {
            return "\x00null";
        }

        if (is_array($key)) {
            // Compound key - serialize with type preservation
            $parts = [];
            foreach ($key as $part) {
                $parts[] = $this->normalizeKey($part);
            }
            return "\x00arr:" . implode("\x01", $parts);
        }

        if (is_int($key)) {
            return "\x00int:" . $key;
        }

        if (is_float($key)) {
            return "\x00float:" . $key;
        }

        if (is_bool($key)) {
            return "\x00bool:" . ($key ? '1' : '0');
        }

        // String - prefix to distinguish from other types
        return "\x00str:" . $key;
    }

    /**
     * Clear all entries from the index
     */
    public function clear(): void
    {
        $this->index = [];
    }

    /**
     * Get total number of unique keys in the index
     */
    public function keyCount(): int
    {
        return count($this->index);
    }

    /**
     * Get total number of row references in the index
     */
    public function rowCount(): int
    {
        $count = 0;
        foreach ($this->index as $bucket) {
            $count += count($bucket);
        }
        return $count;
    }
}
