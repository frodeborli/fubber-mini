<?php
namespace mini\Table\Index;

use Traversable;

/**
 * Hybrid hash/sorted-array/treap index optimized for real-world access patterns.
 *
 * Three stages based on usage:
 * 1. Hash only: eq() lookups, no range yet
 * 2. Sorted array: first range() called, assume no more inserts
 * 3. Treap: insert after range, convert sorted→treap once, stay in treap
 *
 * This avoids merge overhead by using only one structure at a time for range().
 */
final class TreapIndex implements IndexInterface
{
    private const MODE_HASH = 0;
    private const MODE_SORTED = 1;
    private const MODE_TREAP = 2;

    /** @var array<string, string> key => packed rowIds (always maintained) */
    private array $hash = [];

    /** @var int Current mode */
    private int $mode = self::MODE_HASH;

    /** @var ?array<string> Sorted keys (MODE_SORTED only) */
    private ?array $sorted = null;

    /** @var ?TreapNode Treap root (MODE_TREAP only) */
    private ?TreapNode $treap = null;

    /**
     * Build index from a generator function.
     * Generator should yield [string $key, int $rowId] pairs.
     */
    public static function fromGenerator(\Closure $fn): self
    {
        $index = new self();
        foreach ($fn() as [$key, $rowId]) {
            $index->hash[$key] = ($index->hash[$key] ?? '') . pack('Q', $rowId);
        }
        return $index;
    }

    /**
     * Build index from array of [key, rowId] pairs.
     */
    public static function fromArray(array $rows): self
    {
        $index = new self();
        foreach ($rows as [$key, $rowId]) {
            $index->hash[$key] = ($index->hash[$key] ?? '') . pack('Q', $rowId);
        }
        return $index;
    }

    public function insert(string $key, int $rowId): void
    {
        $packed = pack('Q', $rowId);
        $isNew = !isset($this->hash[$key]);

        if ($isNew) {
            $this->hash[$key] = $packed;
        } else {
            $this->hash[$key] .= $packed;
        }

        // Handle new key based on current mode
        if ($isNew && $this->mode === self::MODE_SORTED) {
            // Insert after range - convert to treap mode
            $this->convertToTreap();
            $this->treap = $this->treapInsert($this->treap, $key);
        } elseif ($isNew && $this->mode === self::MODE_TREAP) {
            $this->treap = $this->treapInsert($this->treap, $key);
        }
    }

    public function delete(string $key, int $rowId): void
    {
        if (!isset($this->hash[$key])) {
            return;
        }

        $packed = pack('Q', $rowId);
        $blob = $this->hash[$key];
        $pos = strpos($blob, $packed);
        while ($pos !== false && $pos % 8 !== 0) {
            $pos = strpos($blob, $packed, $pos + 1);
        }

        if ($pos === false) {
            return;
        }

        $this->hash[$key] = substr($blob, 0, $pos) . substr($blob, $pos + 8);

        if ($this->hash[$key] === '') {
            unset($this->hash[$key]);
            // Note: we don't remove from sorted/treap - range() checks hash
        }
    }

    public function has(string $key): bool
    {
        return isset($this->hash[$key]);
    }

    public function eq(string $key): Traversable
    {
        if (isset($this->hash[$key])) {
            foreach (unpack('Q*', $this->hash[$key]) as $rowId) {
                yield $rowId;
            }
        }
    }

    /**
     * Count rowIds for a specific key.
     */
    public function count(string $key): int
    {
        return intdiv(strlen($this->hash[$key] ?? ''), 8);
    }

    /**
     * Count unique keys.
     */
    public function keyCount(): int
    {
        return count($this->hash);
    }

    /**
     * Count total rowIds across all keys.
     */
    public function rowCount(): int
    {
        $sum = 0;
        foreach ($this->hash as $blob) {
            $sum += strlen($blob);
        }
        return intdiv($sum, 8);
    }

    /**
     * Clear all data from the index.
     */
    public function clear(): void
    {
        $this->hash = [];
        $this->sorted = null;
        $this->treap = null;
        $this->mode = self::MODE_HASH;
    }

    /**
     * Count rowIds in a range.
     */
    public function countRange(?string $start = null, ?string $end = null): int
    {
        $count = 0;
        foreach ($this->range($start, $end) as $_) {
            $count++;
        }
        return $count;
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        // First range() call: build sorted array
        if ($this->mode === self::MODE_HASH) {
            $this->sorted = array_keys($this->hash);
            sort($this->sorted, SORT_STRING);
            $this->mode = self::MODE_SORTED;
        }

        // Use appropriate structure
        if ($this->mode === self::MODE_SORTED) {
            yield from $this->sortedRange($start, $end, $reverse);
        } else {
            yield from $this->treapRangeWithBlobs($start, $end, $reverse);
        }
    }

    /**
     * Convert sorted array to treap (called on first insert after range).
     */
    private function convertToTreap(): void
    {
        // Filter out keys deleted since sorted was built
        $live = [];
        foreach ($this->sorted as $key) {
            if (isset($this->hash[$key])) {
                $live[] = $key;
            }
        }

        $this->treap = $this->buildBalanced($live, 0, count($live) - 1);
        $this->sorted = null;
        $this->mode = self::MODE_TREAP;
    }

    /**
     * Build balanced treap from sorted keys.
     */
    private function buildBalanced(array $keys, int $lo, int $hi): ?TreapNode
    {
        if ($lo > $hi) {
            return null;
        }

        $mid = ($lo + $hi) >> 1;
        $key = $keys[$mid];
        $node = new TreapNode($key, crc32($key) & 0xffffffff); // Deterministic priority
        $node->left = $this->buildBalanced($keys, $lo, $mid - 1);
        $node->right = $this->buildBalanced($keys, $mid + 1, $hi);

        return $node;
    }

    // =========================================================================
    // Sorted array operations
    // =========================================================================

    private function sortedRange(?string $start, ?string $end, bool $reverse): \Generator
    {
        $n = count($this->sorted);

        if (!$reverse) {
            $i = $start !== null ? $this->lowerBound($start) : 0;
            $stop = $end !== null ? $this->upperBound($end) : $n;
            for (; $i < $stop; $i++) {
                $key = $this->sorted[$i];
                if (!isset($this->hash[$key])) continue;
                foreach (unpack('Q*', $this->hash[$key]) as $rowId) {
                    yield $rowId;
                }
            }
        } else {
            $i = $end !== null ? $this->upperBound($end) - 1 : $n - 1;
            $stop = $start !== null ? $this->lowerBound($start) - 1 : -1;
            for (; $i > $stop; $i--) {
                $key = $this->sorted[$i];
                if (!isset($this->hash[$key])) continue;
                $u = unpack('Q*', $this->hash[$key]);
                for ($j = count($u); $j >= 1; $j--) {
                    yield $u[$j];
                }
            }
        }
    }

    private function lowerBound(string $key): int
    {
        $lo = 0;
        $hi = count($this->sorted);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($this->sorted[$mid], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    private function upperBound(string $key): int
    {
        $lo = 0;
        $hi = count($this->sorted);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($this->sorted[$mid], $key) <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    // =========================================================================
    // Treap operations
    // =========================================================================

    private function treapInsert(?TreapNode $node, string $key): TreapNode
    {
        if ($node === null) {
            return new TreapNode($key, crc32($key) & 0xffffffff); // Deterministic priority
        }

        $cmp = strcmp($key, $node->key);
        if ($cmp === 0) {
            return $node;
        }

        if ($cmp < 0) {
            $node->left = $this->treapInsert($node->left, $key);
            if ($node->left->priority > $node->priority) {
                $node = $this->rotateRight($node);
            }
        } else {
            $node->right = $this->treapInsert($node->right, $key);
            if ($node->right->priority > $node->priority) {
                $node = $this->rotateLeft($node);
            }
        }

        return $node;
    }

    /**
     * Treap range with blob yielding (iterative, strict pruning).
     */
    private function treapRangeWithBlobs(?string $start, ?string $end, bool $reverse): \Generator
    {
        $node = $this->treap;
        $stack = [];

        if (!$reverse) {
            while ($node !== null || $stack) {
                // Go left, but prune subtrees that are entirely out of range
                while ($node !== null) {
                    // If node < start, entire left subtree is also < start, skip to right
                    if ($start !== null && strcmp($node->key, $start) < 0) {
                        $node = $node->right;
                        continue;
                    }
                    $stack[] = $node;
                    $node = $node->left;
                }

                if (!$stack) break;
                $node = array_pop($stack);

                // If past end, we're done (in-order means all remaining are > end)
                if ($end !== null && strcmp($node->key, $end) > 0) {
                    return;
                }

                // Yield if key exists in hash (lazy delete check)
                if (isset($this->hash[$node->key])) {
                    foreach (unpack('Q*', $this->hash[$node->key]) as $rowId) {
                        yield $rowId;
                    }
                }

                $node = $node->right;
            }
        } else {
            while ($node !== null || $stack) {
                // Go right, but prune subtrees that are entirely out of range
                while ($node !== null) {
                    // If node > end, entire right subtree is also > end, skip to left
                    if ($end !== null && strcmp($node->key, $end) > 0) {
                        $node = $node->left;
                        continue;
                    }
                    $stack[] = $node;
                    $node = $node->right;
                }

                if (!$stack) break;
                $node = array_pop($stack);

                // If past start, we're done (reverse in-order means all remaining are < start)
                if ($start !== null && strcmp($node->key, $start) < 0) {
                    return;
                }

                // Yield if key exists in hash (lazy delete check)
                if (isset($this->hash[$node->key])) {
                    $u = unpack('Q*', $this->hash[$node->key]);
                    for ($j = count($u); $j >= 1; $j--) {
                        yield $u[$j];
                    }
                }

                $node = $node->left;
            }
        }
    }

    private function rotateRight(TreapNode $node): TreapNode
    {
        $left = $node->left;
        $node->left = $left->right;
        $left->right = $node;
        return $left;
    }

    private function rotateLeft(TreapNode $node): TreapNode
    {
        $right = $node->right;
        $node->right = $right->left;
        $right->left = $node;
        return $right;
    }
}

/**
 * @internal
 */
final class TreapNode
{
    public ?TreapNode $left = null;
    public ?TreapNode $right = null;

    public function __construct(
        public string $key,
        public int $priority,
    ) {}
}
