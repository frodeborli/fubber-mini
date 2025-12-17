<?php
namespace mini\Table\Index;

use Closure;
use Traversable;

/**
 * Simple hash + lazy sorted array for range queries.
 *
 * Best for bulk builds (fromGenerator/fromArray) where data is loaded once.
 * For dynamic insert-heavy workloads, use LsmIndex instead.
 */
final class SortedArrayIndex implements IndexInterface
{
    /** @var array<string, string> key => packedRowIdsBlob */
    private array $hash = [];

    /** @var list<string>|null sorted keys (lazy-built on first range) */
    private ?array $sorted = null;

    /**
     * Build index from a generator function.
     * Generator should yield [string $key, int $rowId] pairs.
     */
    public static function fromGenerator(Closure $fn): self
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
        $this->hash[$key] = ($this->hash[$key] ?? '') . pack('Q', $rowId);
        $this->sorted = null;
    }

    public function delete(string $key, int $rowId): void
    {
        if (!isset($this->hash[$key])) {
            return;
        }

        $out = '';
        foreach (unpack('Q*', $this->hash[$key]) as $id) {
            if ($id !== $rowId) {
                $out .= pack('Q', $id);
            }
        }

        if ($out === '') {
            unset($this->hash[$key]);
        } else {
            $this->hash[$key] = $out;
        }
        $this->sorted = null;
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

    public function count(string $key): int
    {
        return intdiv(strlen($this->hash[$key] ?? ''), 8);
    }

    public function keyCount(): int
    {
        return count($this->hash);
    }

    public function rowCount(): int
    {
        $sum = 0;
        foreach ($this->hash as $blob) {
            $sum += strlen($blob);
        }
        return intdiv($sum, 8);
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        if ($this->sorted === null) {
            $this->sorted = array_keys($this->hash);
            sort($this->sorted, SORT_STRING);
        }

        $keys = $this->sorted;
        $n = count($keys);

        if ($n === 0) {
            return;
        }

        if ($reverse) {
            $pos = $end === null ? $n - 1 : $this->upperBound($keys, $end) - 1;

            for ($i = $pos; $i >= 0; $i--) {
                $k = $keys[$i];
                if ($start !== null && strcmp($k, $start) < 0) {
                    return;
                }
                $u = unpack('Q*', $this->hash[$k]);
                for ($j = count($u); $j >= 1; $j--) yield $u[$j];
            }
        } else {
            $pos = $start === null ? 0 : $this->lowerBound($keys, $start);

            for ($i = $pos; $i < $n; $i++) {
                $k = $keys[$i];
                if ($end !== null && strcmp($k, $end) > 0) {
                    return;
                }
                foreach (unpack('Q*', $this->hash[$k]) as $rowId) {
                    yield $rowId;
                }
            }
        }
    }

    private function lowerBound(array $a, string $x): int
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

    private function upperBound(array $a, string $x): int
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
