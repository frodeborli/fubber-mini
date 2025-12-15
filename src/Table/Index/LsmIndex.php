<?php
namespace mini\Table\Index;

use EmptyIterator;
use Traversable;

/**
 * LSM (Log-Structured Merge) index for dynamic insert-heavy workloads.
 *
 * Optimized for bulk inserts with occasional range queries. Creates inner
 * layers only when sorted and threshold exceeded, keeping sort costs low.
 */
final class LsmIndex implements IndexInterface, \Countable
{
    private const COMPACTION_RATIO = 0.05;

    private array $hash = [];
    private ?array $sorted = null;
    private ?self $inner = null;
    private ?self $outermost = null;
    private int $minimumSizeBeforeDelegation;

    public function __construct(int $minimumSizeBeforeDelegation = 100)
    {
        $this->minimumSizeBeforeDelegation = $minimumSizeBeforeDelegation;
    }

    public function insert(string $key, int $rowId): void
    {
        $packed = pack('Q', $rowId);

        // Existing key - append locally
        if (isset($this->hash[$key])) {
            $this->hash[$key] .= $packed;
            return;
        }

        // New key - delegate to inner if exists
        if ($this->inner !== null) {
            $this->inner->insert($key, $rowId);
            return;
        }

        // Only delegate when sorted and exceeds adaptive threshold (1% of total, min 100)
        if ($this->sorted !== null) {
            $totalKeys = ($this->outermost ?? $this)->count();
            $threshold = max($this->minimumSizeBeforeDelegation, (int)($totalKeys * 0.01));
            if (count($this->hash) >= $threshold) {
                $this->inner = new self($this->minimumSizeBeforeDelegation);
                $this->inner->outermost = $this->outermost ?? $this;
                $this->inner->insert($key, $rowId);
                return;
            }
        }

        $this->hash[$key] = $packed;
        $this->sorted = null;
    }

    public function delete(string $key, int $rowId): void
    {
        if (isset($this->hash[$key])) {
            $packed = pack('Q', $rowId);
            $blob = $this->hash[$key];
            $pos = strpos($blob, $packed);
            while ($pos !== false && $pos % 8 !== 0) {
                $pos = strpos($blob, $packed, $pos + 1);
            }
            if ($pos !== false) {
                $this->hash[$key] = substr($blob, 0, $pos) . substr($blob, $pos + 8);
                if ($this->hash[$key] === '') {
                    unset($this->hash[$key]);
                }
            }
            return;
        }
        $this->inner?->delete($key, $rowId);
    }

    public function has(string $key): bool
    {
        return isset($this->hash[$key]) || ($this->inner?->has($key) ?? false);
    }

    public function eq(string $key): Traversable
    {
        if (isset($this->hash[$key])) {
            foreach (unpack('Q*', $this->hash[$key]) as $rowId) {
                yield $rowId;
            }
        } elseif ($this->inner !== null) {
            yield from $this->inner->eq($key);
        }
    }

    public function count(): int
    {
        return count($this->hash) + ($this->inner ? count($this->inner) : 0);
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        // Compact when inner has grown large relative to local
        if ($this->inner !== null && count($this->inner) > count($this->hash) * self::COMPACTION_RATIO) {
            $this->compact();
        }

        $from = $reverse ? $end : $start;
        $to = $reverse ? $start : $end;

        foreach ($this->walkSortedBlobs($from, $to, $reverse) as $blob) {
            if ($reverse) {
                $u = unpack('Q*', $blob);
                for ($j = count($u); $j >= 1; $j--) yield $u[$j];
            } else {
                foreach (unpack('Q*', $blob) as $rowId) {
                    yield $rowId;
                }
            }
        }
    }

    /**
     * Walk [key => blob] pairs in sorted key order, merging with inner layer.
     */
    private function walkSortedBlobs(?string $from, ?string $to, bool $reverseHint): Traversable
    {
        $this->ensureSorted();

        $reverse = ($from !== null && $to !== null) ? strcmp($from, $to) > 0 : $reverseHint;
        $inner = $this->inner?->walkSortedBlobs($from, $to, $reverse) ?? new EmptyIterator;
        $n = count($this->sorted);

        if (!$reverse) {
            $i = $from !== null ? self::lowerBound($this->sorted, $from) : 0;
            $stop = $to !== null ? self::upperBound($this->sorted, $to) : $n;
            $step = 1;
        } else {
            $i = $from !== null ? self::upperBound($this->sorted, $from) - 1 : $n - 1;
            $stop = $to !== null ? self::lowerBound($this->sorted, $to) - 1 : -1;
            $step = -1;
        }

        for (; $i !== $stop; $i += $step) {
            $key = $this->sorted[$i];
            if (!isset($this->hash[$key])) continue;

            while ($inner->valid() && strcmp($key, $inner->key()) * $step > 0) {
                yield $inner->key() => $inner->current();
                $inner->next();
            }

            if ($inner->valid() && $key === $inner->key()) {
                throw new \LogicException("Key '$key' exists in both local and inner layer");
            }

            yield $key => $this->hash[$key];
        }

        while ($inner->valid()) {
            yield $inner->key() => $inner->current();
            $inner->next();
        }
    }

    private function compact(): void
    {
        if ($this->inner === null) {
            return;
        }

        $sorted = [];
        foreach ($this->walkSortedBlobs(null, null, false) as $key => $blob) {
            $sorted[] = $key;
            $this->hash[$key] = $blob;
        }
        $this->sorted = $sorted;
        $this->inner = null;
    }

    private function ensureSorted(): void
    {
        if ($this->sorted === null) {
            $this->sorted = array_keys($this->hash);
            sort($this->sorted, SORT_STRING);
        }
    }

    private static function lowerBound(array $a, string $x): int
    {
        $lo = 0;
        $hi = count($a);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($a[$mid], $x) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    private static function upperBound(array $a, string $x): int
    {
        $lo = 0;
        $hi = count($a);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($a[$mid], $x) <= 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }
}
