<?php
namespace mini\Table\Index;

use Traversable;

/**
 * Parsed leaf page - stores raw page data and parses lazily.
 * Uses object pooling to avoid GC overhead during scans.
 *
 * Page format:
 * - Header: type(1) + count(2) + offsets((n+1) * 2) + rowIdCounts(n * 2)
 * - Entry: rowIds(8 each) + key - no per-entry metadata
 *
 * All header metadata unpacked in single call for efficiency.
 * Uses 0-based indexing: offsets at 0..n, rowIdCounts at n+1..2n.
 * Arrays reused via pooling - count tracks valid entries, stale data ignored.
 *
 * @internal
 */
final class BTreeLeafPage
{
    /** @var self[] */
    private static array $pool = [];
    private static int $poolCount = 0;

    /** Raw page data */
    public string $data;
    /** Entry count */
    public int $count;
    /** @var int[] Header metadata (0-based): offsets (0..n) + rowIdCounts (n+1..2n) */
    public array $meta = [];
    /** @var array<array<int, string|int>> Cached entries for scans, 0-based */
    public array $entries = [];
    /** Whether entries array is valid for current page data */
    public bool $entriesBuilt = false;
    /** @var array<string, int[]> Pending inserts: key => [rowId1, rowId2, ...] */
    public array $writeBuffer = [];
    /** Parent internal node (null for root). Not serialized. */
    public ?BTreeInternalPage $parent = null;

    public static function fromRaw(string $data): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }
        $instance->data = $data;
        $instance->count = \ord($data[1]) | (\ord($data[2]) << 8);
        $instance->entriesBuilt = false;
        // Meta not pre-populated - getKeyAt/getEntry read directly from data
        return $instance;
    }

    /**
     * Build and cache all entries for efficient scan iteration.
     * Also builds meta array for serialization.
     * Entries are 0-based: entries[0], entries[1], ...
     */
    public function buildEntries(): void
    {
        if ($this->entriesBuilt) {
            return;
        }
        $n = $this->count;
        $data = $this->data;
        $offsetBase = 3;
        $rowIdCountBase = 3 + (($n + 1) << 1);

        for ($i = 0; $i < $n; $i++) {
            $offsetPos = $offsetBase + ($i << 1);
            $pos = \ord($data[$offsetPos]) | (\ord($data[$offsetPos + 1]) << 8);
            $nextPos = \ord($data[$offsetPos + 2]) | (\ord($data[$offsetPos + 3]) << 8);
            $rcPos = $rowIdCountBase + ($i << 1);
            $rowIdCount = \ord($data[$rcPos]) | (\ord($data[$rcPos + 1]) << 8);

            // Store meta for serialization
            $this->meta[$i] = $pos;
            $this->meta[$n + 1 + $i] = $rowIdCount;

            $keyLen = $nextPos - $pos - ($rowIdCount << 3);
            $entry = \unpack('P' . $rowIdCount, $data, $pos);
            $entry[0] = \substr($data, $pos + ($rowIdCount << 3), $keyLen);
            $this->entries[$i] = $entry;
        }
        // Store end marker
        if ($n > 0) {
            $offsetPos = $offsetBase + ($n << 1);
            $this->meta[$n] = \ord($data[$offsetPos]) | (\ord($data[$offsetPos + 1]) << 8);
        }
        $this->entriesBuilt = true;
    }

    /**
     * Get just the key at 0-based index (for binary search comparisons).
     * Avoids unpacking rowIds - much faster for probes.
     */
    public function getKeyAt(int $i): string
    {
        // Pages with entriesBuilt=true have entries populated directly (0-based)
        if ($this->entriesBuilt) {
            return $this->entries[$i][0];
        }

        // Read directly from binary data - no meta array needed
        // Header: type(1) + count(2) + offsets((n+1)*2) + rowIdCounts(n*2)
        $data = $this->data;
        $n = $this->count;
        $offsetBase = 3;
        $rowIdCountBase = 3 + (($n + 1) << 1);

        // Read offset[i], offset[i+1], rowIdCount[i] directly
        $offsetPos = $offsetBase + ($i << 1);
        $pos = \ord($data[$offsetPos]) | (\ord($data[$offsetPos + 1]) << 8);
        $nextPos = \ord($data[$offsetPos + 2]) | (\ord($data[$offsetPos + 3]) << 8);
        $rcPos = $rowIdCountBase + ($i << 1);
        $rowIdCount = \ord($data[$rcPos]) | (\ord($data[$rcPos + 1]) << 8);

        $keyLen = $nextPos - $pos - ($rowIdCount << 3);
        return \substr($data, $pos + ($rowIdCount << 3), $keyLen);
    }

    /**
     * Get single entry by 0-based index (for yielding rowIds after match).
     * @return array<int, string|int> Flat: [0 => key, 1 => rowId1, ...]
     */
    public function getEntry(int $i): array
    {
        // Pages with entriesBuilt=true have entries populated directly (0-based)
        if ($this->entriesBuilt) {
            return $this->entries[$i];
        }

        // Read directly from binary data - no meta array needed
        $data = $this->data;
        $n = $this->count;
        $offsetBase = 3;
        $rowIdCountBase = 3 + (($n + 1) << 1);

        $offsetPos = $offsetBase + ($i << 1);
        $pos = \ord($data[$offsetPos]) | (\ord($data[$offsetPos + 1]) << 8);
        $nextPos = \ord($data[$offsetPos + 2]) | (\ord($data[$offsetPos + 3]) << 8);
        $rcPos = $rowIdCountBase + ($i << 1);
        $rowIdCount = \ord($data[$rcPos]) | (\ord($data[$rcPos + 1]) << 8);

        $keyLen = $nextPos - $pos - ($rowIdCount << 3);

        $result = \unpack('P' . $rowIdCount, $data, $pos);
        $result[0] = \substr($data, $pos + ($rowIdCount << 3), $keyLen);
        return $result;
    }

    public function release(): void
    {
        // Don't clear data/meta/entries - fromRaw() will overwrite them
        // writeBuffer should already be empty (imported before serialization)
        $this->writeBuffer = [];
        $this->parent = null;
        self::$pool[self::$poolCount++] = $this;
    }

    /**
     * Serialize to binary page format using precomputed meta.
     * Entries and meta are 0-based.
     */
    public function asString(): string
    {
        // If page was loaded from disk and not modified, return original data
        if (!$this->entriesBuilt && $this->writeBuffer === []) {
            return $this->data;
        }

        $n = $this->count;
        if ($n === 0) {
            return \pack('Cv', BTreeIndex::PAGE_LEAF, 0);
        }

        $meta = $this->meta;
        $entries = $this->entries;

        // Extract offsets (n+1) and rowIdCounts (n) from 0-based meta
        $offsets = [];
        $rowIdCounts = [];
        for ($i = 0; $i <= $n; $i++) {
            $offsets[] = $meta[$i];
        }
        for ($i = 0; $i < $n; $i++) {
            $rowIdCounts[] = $meta[$n + 1 + $i];
        }

        // Build entry data: rowIds + key for each entry (entries are 0-based)
        $entryParts = [];
        for ($i = 0; $i < $n; $i++) {
            $entry = $entries[$i];
            $rowIdCount = $rowIdCounts[$i];
            $rowIds = [];
            for ($j = 1; $j <= $rowIdCount; $j++) {
                $rowIds[] = $entry[$j];
            }
            $entryParts[] = \pack('P*', ...$rowIds);
            $entryParts[] = $entry[0];
        }
        $entryData = \implode('', $entryParts);

        // Single pack for header, then append entry data
        return \pack('Cvv' . ($n + 1) . 'v' . $n, BTreeIndex::PAGE_LEAF, $n, ...$offsets, ...$rowIdCounts) . $entryData;
    }

    /**
     * Calculate the size this page will have when serialized.
     * Avoids the overhead of actually packing the data.
     */
    public function calculateSize(): int
    {
        $n = $this->count;
        if ($n === 0) {
            return 3; // type(1) + count(2)
        }

        $this->buildEntries();

        // Header: type(1) + count(2) + offsets((n+1)*2) + rowIdCounts(n*2)
        $size = 3 + ($n + 1) * 2 + $n * 2;

        // Entry data: rowIds(8 each) + key for each entry (0-based)
        for ($i = 0; $i < $n; $i++) {
            $entry = $this->entries[$i];
            $size += (\count($entry) - 1) * 8 + \strlen($entry[0]);
        }

        return $size;
    }

    /**
     * Get a leaf page from pool (or create new).
     */
    public static function fromPool(): self
    {
        if (self::$poolCount > 0) {
            return self::$pool[--self::$poolCount];
        }
        return new self();
    }

    /**
     * Rebuild meta from current entries array.
     * Call after modifying entries directly.
     * Meta is 0-based: offsets at 0..n, rowIdCounts at n+1..2n.
     */
    public function rebuildMeta(): void
    {
        $n = $this->count;
        if ($n === 0) {
            return;
        }

        // Build 0-based meta: offsets (0..n) + rowIdCounts (n+1..2n)
        // Header size: type(1) + count(2) + offsets((n+1) * 2) + rowIdCounts(n * 2)
        $headerSize = 3 + ($n + 1) * 2 + $n * 2;
        $offset = $headerSize;

        for ($i = 0; $i < $n; $i++) {
            $entry = $this->entries[$i];
            $this->meta[$i] = $offset;
            $rowIdCount = \count((array)$entry) - 1;
            $this->meta[$n + 1 + $i] = $rowIdCount;
            $offset += $rowIdCount * 8 + \strlen($entry[0]);
        }
        $this->meta[$n] = $offset; // end marker
    }

    /**
     * Import writeBuffer into entries, maintaining sorted order.
     * Uses bubble sort for 1-2 entries, ksort merge for more.
     */
    public function importWriteBuffer(): void
    {
        if ($this->writeBuffer === []) {
            $this->buildEntries();
            return;
        }

        $this->buildEntries();
        $bufferCount = \count($this->writeBuffer);

        if ($bufferCount <= 2) {
            // Bubble sort insert for small buffers
            foreach ($this->writeBuffer as $key => $rowIds) {
                $entry = [$key, ...$rowIds];
                $n = $this->count;
                // Binary search for insert position
                $lo = 0;
                $hi = $n;
                while ($lo < $hi) {
                    $mid = ($lo + $hi) >> 1;
                    if (\strcmp($this->entries[$mid][0], $key) < 0) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }
                // Shift and insert
                for ($i = $n; $i > $lo; $i--) {
                    $this->entries[$i] = $this->entries[$i - 1];
                }
                $this->entries[$lo] = $entry;
                $this->count = $n + 1;
            }
        } else {
            // Build combined key => entry map, ksort, rebuild entries
            $combined = [];
            for ($i = 0; $i < $this->count; $i++) {
                $entry = $this->entries[$i];
                $combined[$entry[0]] = $entry;
            }
            foreach ($this->writeBuffer as $key => $rowIds) {
                $combined[$key] = [$key, ...$rowIds];
            }
            \ksort($combined, SORT_STRING);
            // Overwrite in place - stale data beyond count doesn't matter
            $count = 0;
            foreach ($combined as $entry) {
                $this->entries[$count++] = $entry;
            }
            $this->count = $count;
        }

        $this->writeBuffer = [];
        $this->rebuildMeta();
    }

    /**
     * Split this leaf into pages that fit within maxSize.
     * Yields new leaf pages first, then yields $this with remaining entries.
     * If already fits, just yields $this.
     *
     * @param int $maxSize Maximum page size (default 4096)
     * @return \Generator<self>
     */
    public function splitOversized(int $maxSize = 4096): \Generator
    {
        $this->buildEntries();

        // Quick check - if fits, just yield self
        if ($this->calculateSize() <= $maxSize) {
            yield $this;
            return;
        }

        // Header for empty leaf: type(1) + count(2) + offset(2) = 5 bytes
        // Each entry adds: 4 bytes meta (offset + rowIdCount) + rowIdCount*8 + keyLen

        // First pass: collect all entries into batches
        $batches = [];
        $batchSize = 5;
        $batch = [];

        for ($i = 0; $i < $this->count; $i++) {
            $entry = $this->entries[$i];
            $key = $entry[0];
            $keyLen = \strlen($key);
            $rowIdCount = \count($entry) - 1;
            $entrySize = 4 + $rowIdCount * 8 + $keyLen;

            // Single entry too big? Split its rowIds
            if ($entrySize > $maxSize - 5) {
                // Save current batch first
                if (\count($batch) > 0) {
                    $batches[] = $batch;
                    $batch = [];
                    $batchSize = 5;
                }

                // How many rowIds fit per page with this key?
                $maxRowIds = (int)(($maxSize - 5 - 4 - $keyLen) / 8);
                if ($maxRowIds < 1) $maxRowIds = 1;

                for ($r = 1; $r <= $rowIdCount; $r += $maxRowIds) {
                    $splitEntry = [$key];
                    $end = \min($r + $maxRowIds, $rowIdCount + 1);
                    for ($j = $r; $j < $end; $j++) {
                        $splitEntry[] = $entry[$j];
                    }
                    $splitCount = $end - $r;
                    $splitSize = 4 + $splitCount * 8 + $keyLen;

                    // Last chunk goes to batch, others save immediately
                    if ($end > $rowIdCount) {
                        $batch[] = $splitEntry;
                        $batchSize = 5 + $splitSize;
                    } else {
                        $batches[] = [$splitEntry];
                    }
                }
            } else {
                // Would this entry overflow current batch?
                if (\count($batch) > 0 && $batchSize + $entrySize > $maxSize) {
                    $batches[] = $batch;
                    $batch = [];
                    $batchSize = 5;
                }

                $batch[] = $entry;
                $batchSize += $entrySize;
            }
        }
        // Add final batch
        if (\count($batch) > 0) {
            $batches[] = $batch;
        }

        // $this keeps FIRST batch (smallest keys) to preserve position in parent
        $firstBatch = $batches[0];
        $count = \count($firstBatch);
        for ($j = 0; $j < $count; $j++) {
            $this->entries[$j] = $firstBatch[$j];
        }
        $this->count = $count;
        $this->entriesBuilt = true;
        $this->rebuildMeta();
        yield $this;

        // Yield new pages for remaining batches (larger keys)
        for ($b = 1; $b < \count($batches); $b++) {
            yield self::fromBatch($batches[$b], $this->parent);
        }
    }

    /**
     * Create a new leaf page from a batch of entries.
     */
    private static function fromBatch(array $batch, ?BTreeInternalPage $parent = null): self
    {
        $leaf = self::fromPool();
        $count = \count($batch);
        for ($i = 0; $i < $count; $i++) {
            $leaf->entries[$i] = $batch[$i];
        }
        $leaf->count = $count;
        $leaf->entriesBuilt = true;
        $leaf->parent = $parent;
        $leaf->rebuildMeta();
        return $leaf;
    }
}

/**
 * Parsed internal page - children are page numbers, keys are separators.
 *
 * Page format:
 * - Header: type(1) + count(2) + children((n+1) * 4) + offsets((n+1) * 2)
 * - Key data: keys concatenated (keyLen = offsets[i+1] - offsets[i])
 *
 * Children are 32-bit page numbers (supports up to 16TB with 4KB pages).
 * Keys are parsed eagerly since internal pages are cached and reused.
 * Uses 0-based indexing: children[0..n], keys[0..n-1].
 *
 * @internal
 */
final class BTreeInternalPage
{
    /** Number of children */
    public int $childCount = 0;
    /** @var int[] Child page numbers - 0-based: children[0..n-1] */
    public array $children = [];
    /** @var string[] Separator keys - 0-based: keys[0..n-2] (one less than children) */
    public array $keys = [];
    /** @var array<array{string, int}> Pending children: [[promoteKey, pageNum], ...] */
    public array $writeBuffer = [];
    /** Parent internal node (null for root). Not serialized. */
    public ?BTreeInternalPage $parent = null;
    /** Separator key for this page when split from sibling. Not serialized. */
    public ?string $promoteKey = null;

    public static function fromRaw(string $data): self
    {
        $instance = new self();

        $type = \ord($data[0]) & 0x7F; // Mask off root marker
        if ($type !== BTreeIndex::PAGE_INTERNAL) {
            throw new \RuntimeException(sprintf(
                "BTreeInternalPage::fromRaw called with non-internal page type 0x%02x (base: 0x%02x)",
                \ord($data[0]), $type
            ));
        }

        $n = \ord($data[1]) | (\ord($data[2]) << 8);
        $childCount = $n + 1;
        $instance->childCount = $childCount;

        // Sanity check: internal page with n keys needs at least 3 + 6*(n+1) bytes
        $minSize = 3 + 6 * $childCount;
        if (\strlen($data) < $minSize) {
            throw new \RuntimeException(sprintf(
                "Corrupt internal page: need %d bytes for %d children, got %d",
                $minSize, $childCount, \strlen($data)
            ));
        }

        // unpack returns 1-based, iterate to build 0-based
        $count = 0;
        foreach (\unpack('V' . $childCount, $data, 3) as $child) {
            $instance->children[$count++] = $child;
        }

        if ($n > 0) {
            // Read n+1 offsets (last is end marker) in one unpack call
            $offsetsStart = 3 + $childCount * 4;
            $offsets = \unpack('v' . ($n + 1), $data, $offsetsStart);

            // Read keys as 0-based array
            $keys = [];
            for ($i = 0; $i < $n; $i++) {
                $keys[$i] = \substr($data, $offsets[$i + 1], $offsets[$i + 2] - $offsets[$i + 1]);
            }
            $instance->keys = $keys;
        }

        return $instance;
    }

    /**
     * Get an internal page and set children/keys.
     * @param int[] $children 0-based input array
     * @param string[] $keys 0-based input array
     */
    public static function fromArrays(array $children, array $keys): self
    {
        $instance = new self();
        $count = \count($children);
        $instance->childCount = $count;
        for ($i = 0; $i < $count; $i++) {
            $instance->children[$i] = $children[$i];
        }
        $instance->keys = $keys;
        return $instance;
    }

    /**
     * Update children and keys in place (for overlay modification).
     * @param int[] $children 0-based input array
     * @param string[] $keys 0-based input array
     */
    public function setChildrenAndKeys(array $children, array $keys): void
    {
        $count = \count($children);
        $this->childCount = $count;
        // Overwrite indices - stale data beyond count doesn't matter
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i] = $children[$i];
        }
        $this->keys = $keys;
    }

    /**
     * Serialize to binary page format.
     * Children and keys are 0-based.
     */
    public function asString(): string
    {
        $n = $this->childCount - 1; // key count = child count - 1
        $c = $this->childCount;

        // Header size: type(1) + count(2) + children((n+1)*4) + offsets((n+1)*2)
        $headerSize = 3 + $c * 4 + $c * 2;

        // Build key data and calculate offsets (0-based)
        $keyData = '';
        $offsets = [];
        $offset = $headerSize;
        for ($i = 0; $i < $n; $i++) {
            $offsets[] = $offset;
            $key = $this->keys[$i];
            $keyData .= $key;
            $offset += \strlen($key);
        }
        $offsets[] = $offset; // End marker

        // Pack children from 0-based array
        $childrenArr = [];
        for ($i = 0; $i < $c; $i++) {
            $childrenArr[] = $this->children[$i];
        }
        return \pack('CvV' . $c . 'v' . $c, BTreeIndex::PAGE_INTERNAL, $n, ...$childrenArr, ...$offsets) . $keyData;
    }

    public function __debugInfo(): array
    {
        return [
            'childCount' => $this->childCount,
            'children' => $this->children,
            'keys' => $this->keys,
            'writeBuffer' => $this->writeBuffer,
        ];
    }

    /**
     * Calculate the size this page will have when serialized.
     * Does NOT include writeBuffer - call importWriteBuffer() first if needed.
     */
    public function calculateSize(): int
    {
        // Header: type(1) + count(2) + children(c*4) + offsets(c*2)
        $c = $this->childCount;
        $size = 3 + $c * 4 + $c * 2;

        // Key data
        for ($i = 0; $i < $c - 1; $i++) {
            $size += \strlen($this->keys[$i]);
        }

        return $size;
    }

    /**
     * Import writeBuffer into children/keys in sorted order.
     * WriteBuffer entries are [[promoteKey, pageNum], ...].
     */
    public function importWriteBuffer(): void
    {
        if ($this->writeBuffer === []) {
            return;
        }

        // Build combined array of [key, childPageNum, isNew]
        // Existing children: child[i] goes AFTER key[i-1] (or first if i=0)
        $combined = [];

        // Add existing children with their preceding keys
        for ($i = 0; $i < $this->childCount; $i++) {
            $key = $i > 0 ? $this->keys[$i - 1] : '';
            $combined[] = [$key, $this->children[$i], false];
        }

        // Add writeBuffer entries
        foreach ($this->writeBuffer as [$promoteKey, $pageNum]) {
            $combined[] = [$promoteKey, $pageNum, true];
        }

        // Sort by key
        \usort($combined, fn($a, $b) => strcmp($a[0], $b[0]));

        // Rebuild children and keys - overwrite in place, stale data beyond count doesn't matter
        $this->keys = [];
        $count = \count($combined);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i] = $combined[$i][1];
            if ($i > 0) {
                $this->keys[$i - 1] = $combined[$i][0];
            }
        }
        $this->childCount = $count;
        $this->writeBuffer = [];
    }

    /**
     * Split this internal page into pages that fit within maxSize.
     * $this keeps smallest keys (first batch) to preserve position in parent.
     * If already fits, just yields $this.
     *
     * @param int $maxSize Maximum page size (default 4096)
     * @return \Generator<self>
     */
    public function splitOversized(int $maxSize = 4096): \Generator
    {
        $this->importWriteBuffer();

        // Quick check - if fits, just yield self
        if ($this->calculateSize() <= $maxSize) {
            yield $this;
            return;
        }

        // Header for internal page: type(1) + count(2) + children(c*4) + offsets(c*2)
        // For c children: 3 + c*4 + c*2 = 3 + 6*c
        // Each additional child adds: 4 (child) + 2 (offset) + keyLen = 6 + keyLen

        // First pass: collect all batches
        $batches = []; // Each batch is [children[], keys[], promoteKey]
        $batchChildren = [];
        $batchKeys = [];
        $batchSize = 3 + 6; // Header base + first child (no key)
        $nextPromoteKey = null;

        for ($i = 0; $i < $this->childCount; $i++) {
            if ($i === 0) {
                // First child - no key, already counted in batchSize
                $batchChildren[] = $this->children[0];
            } else {
                $key = $this->keys[$i - 1];
                $keyLen = \strlen($key);
                $entrySize = 6 + $keyLen; // child + offset + key

                // Would this entry overflow current batch?
                if (\count($batchChildren) > 0 && $batchSize + $entrySize > $maxSize) {
                    // Save current batch
                    $batches[] = [$batchChildren, $batchKeys, $nextPromoteKey];

                    // The key at split point becomes promoteKey for the NEXT batch
                    $nextPromoteKey = $key;

                    // Start new batch - this child becomes first
                    $batchChildren = [$this->children[$i]];
                    $batchKeys = [];
                    $batchSize = 3 + 6;
                } else {
                    $batchChildren[] = $this->children[$i];
                    $batchKeys[] = $key;
                    $batchSize += $entrySize;
                }
            }
        }
        // Add final batch
        $batches[] = [$batchChildren, $batchKeys, $nextPromoteKey];

        // $this keeps FIRST batch (smallest keys) to preserve position in parent
        [$firstChildren, $firstKeys, $firstPromoteKey] = $batches[0];
        $count = \count($firstChildren);
        for ($i = 0; $i < $count; $i++) {
            $this->children[$i] = $firstChildren[$i];
        }
        $this->keys = $firstKeys;
        $this->childCount = $count;
        $this->promoteKey = $firstPromoteKey; // null for first batch
        yield $this;

        // Yield new pages for remaining batches (larger keys)
        for ($b = 1; $b < \count($batches); $b++) {
            [$children, $keys, $promoteKey] = $batches[$b];
            $newPage = self::fromArrays($children, $keys);
            $newPage->parent = $this->parent;
            $newPage->promoteKey = $promoteKey;
            yield $newPage;
        }
    }
}

/**
 * Append-only B-tree index with on-disk persistence.
 *
 * File layout (4KB aligned pages):
 * - Page 0: Header (64 bytes used, rest reserved for metadata)
 * - Page 1+: B-tree nodes (internal and leaf)
 *
 * Design:
 * - Copy-on-write: pages never modified once written (except header)
 * - Variable-length keys with offset arrays for O(log n) binary search within pages
 *
 * TODO: Fully append-only design with separate log file
 * - Main file: pages only, never overwritten
 * - Log file: append-only sequence of [seq, root, checksum] entries
 * - Commit: append pages, fsync, append log entry, fsync
 * - Open: scan log from end for latest valid root
 * - Removes header overwrites for better crash safety
 */
final class BTreeIndex implements IndexInterface
{
    // File format constants
    private const MAGIC = 0x42545249; // "BTRI"
    private const VERSION = 1;
    private const HEADER_SIZE = 64;
    private const PAGE_SIZE = 4096;

    // Max key length that fits in a page with one entry:
    // page = type(1) + count(2) + offset(2) + keyLen(2) + key + rowIdCount(4) + rowId(8)
    // So max key = PAGE_SIZE - 19 = 4077
    private const MAX_KEY_LENGTH = 4077;

    // Page types (public for BTreeLeafPage/BTreeInternalPage::asString())
    public const PAGE_INTERNAL = 0x01;
    public const PAGE_LEAF = 0x02;
    public const PAGE_INTERNAL_ROOT = 0x81;  // Internal + root marker (high bit)
    public const PAGE_LEAF_ROOT = 0x82;      // Leaf + root marker

    // CRC is stored in last 4 bytes of root pages
    private const CRC_OFFSET = self::PAGE_SIZE - 4;

    /** @var resource|null File handle */
    private $file = null;

    /** @var resource|null Lock file handle */
    private $lockFile = null;

    /** @var string Index file path */
    private string $path;

    /** @var int Root page number (0 = empty) */
    private int $rootPage = 0;

    /** @var int Next page number for appending */
    private int $nextPage = 1;

    /** @var int Sequence number (incremented on commit) */
    private int $sequence = 0;

    /** @var bool Whether in transaction mode */
    private bool $inTransaction = false;

    /** @var array<int, BTreeLeafPage|BTreeInternalPage> Uncommitted non-root pages (keyed by future page number) */
    private array $unwrittenPages = [];

    /** @var int Count of allocated unwritten non-root pages */
    private int $unwrittenPageCount = 0;

    /** @var BTreeLeafPage|BTreeInternalPage|null Current root (from disk or modified in transaction) */
    private BTreeLeafPage|BTreeInternalPage|null $currentRoot = null;

    // Internal node cache (cleared on findLatestRoot when root changes)
    private const HEADER_CHECK_INTERVAL = 10000; // Check header every N queries

    /** @var array<int, BTreeInternalPage> Parsed internal page cache */
    private array $pageCache = [];

    /** @var int Counter for periodic header check */
    private int $queryCount = 0;

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->open();
    }

    /**
     * Build index from a generator function.
     * Generator should yield [string $key, int $rowId] pairs.
     * Uses bulk loading for efficient tree construction.
     */
    public static function fromGenerator(string $path, \Closure $fn): self
    {
        $index = new self($path);
        $index->begin();

        foreach ($fn() as [$key, $rowId]) {
            $index->insert($key, $rowId);
        }

        $index->commitBulk(); // Use bulk rebuild for initial loading
        return $index;
    }

    /**
     * Build index from array of [key, rowId] pairs.
     * Uses bulk loading for efficient tree construction.
     */
    public static function fromArray(string $path, array $rows): self
    {
        $index = new self($path);
        $index->begin();

        foreach ($rows as [$key, $rowId]) {
            $index->insert($key, $rowId);
        }

        $index->commitBulk(); // Use bulk rebuild for initial loading
        return $index;
    }

    public function __destruct()
    {
        // Auto-commit pending changes (root may have been modified even if unwrittenPages is empty)
        if ($this->inTransaction) {
            $this->commit();
        }
        $this->close();
    }

    // =========================================================================
    // Transaction support
    // =========================================================================

    /**
     * Begin a transaction for bulk operations.
     * Acquires exclusive lock and refreshes root from disk.
     */
    public function begin(): void
    {
        if ($this->inTransaction) {
            throw new \RuntimeException("Already in transaction");
        }
        // Lock file for duration of transaction
        flock($this->lockFile, LOCK_EX);
        $this->findLatestRoot(); // Always sets $currentRoot (empty leaf if new)
        $this->inTransaction = true;
        $this->unwrittenPages = [];
        $this->unwrittenPageCount = 0;
    }

    /**
     * Commit unwritten pages to disk.
     */
    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException("Not in transaction");
        }

        try {
            if (empty($this->unwrittenPages) && $this->currentRoot === null) {
                return;
            }

            // Split any oversized pages before writing
            $this->splitOversizedPages();

            // Write all non-root unwritten pages in order
            ksort($this->unwrittenPages);
            foreach ($this->unwrittenPages as $pageNum => $page) {
                $offset = $pageNum * self::PAGE_SIZE;
                fseek($this->file, $offset);
                fwrite($this->file, pack('a' . self::PAGE_SIZE, $page->asString()));
            }
            $this->nextPage += $this->unwrittenPageCount;

            // Sync non-root pages to disk
            fdatasync($this->file);

            // Write root with CRC (makes the transaction visible)
            if ($this->currentRoot !== null) {
                $this->writeRootWithCrc($this->currentRoot);
            }

            // Final sync
            fdatasync($this->file);
            $this->sequence++;

            // Move pages from overlay: internal nodes to cache, leaf nodes released to pool
            foreach ($this->unwrittenPages as $pageNum => $page) {
                if ($page instanceof BTreeInternalPage) {
                    $this->pageCache[$pageNum] = $page;
                } else {
                    $page->release();
                }
            }
        } finally {
            $this->unwrittenPages = [];
            $this->unwrittenPageCount = 0;
            $this->inTransaction = false;
            flock($this->lockFile, LOCK_UN);
        }
    }

    /**
     * Commit with bulk rebuild - use for initial loading only.
     * Now identical to commit() since overlay handles incremental structure building.
     */
    public function commitBulk(): void
    {
        $this->commit();
    }

    /**
     * Rollback pending changes.
     */
    public function rollback(): void
    {
        if ($this->inTransaction) {
            // Restore root from disk
            $this->findLatestRoot();
            flock($this->lockFile, LOCK_UN);
        }
        $this->unwrittenPages = [];
        $this->unwrittenPageCount = 0;
        $this->inTransaction = false;
    }

    /**
     * Split any oversized pages before writing to disk.
     * Uses parent references and writeBuffer for efficient multi-way splits.
     */
    private function splitOversizedPages(): void
    {
        if ($this->currentRoot === null) {
            return;
        }

        // Set parent references on all pages in unwrittenPages
        $this->setParentReferences();

        // Import writeBuffers on all modified leaves
        foreach ($this->unwrittenPages as $page) {
            if ($page instanceof BTreeLeafPage) {
                $page->importWriteBuffer();
            }
        }
        if ($this->currentRoot instanceof BTreeLeafPage) {
            $this->currentRoot->importWriteBuffer();
        }

        // Phase 1: Split all oversized leaves
        $rootNewChildren = []; // For when root is a leaf that splits

        if ($this->currentRoot instanceof BTreeLeafPage) {
            // Root is a leaf - split it if needed
            // Collect all pages in order (new pages first with smaller keys, original last)
            $allPages = [];
            foreach ($this->currentRoot->splitOversized(self::PAGE_SIZE) as $leaf) {
                $allPages[] = $leaf;
            }

            // If only one page, no split needed
            if (\count($allPages) <= 1) {
                return;
            }

            // Create new internal root with all pages as children
            $children = [];
            $keys = [];
            foreach ($allPages as $i => $leaf) {
                $pageNum = $this->allocatePage($leaf);
                $children[] = $pageNum;
                if ($i > 0) {
                    $keys[] = $leaf->entries[0][0]; // First key is the separator
                }
            }
            $this->currentRoot = BTreeInternalPage::fromArrays($children, $keys);
            // Fall through to phase 2 to check if new internal root needs splitting
        } else {
            // Root is internal - process leaves under it
            foreach ($this->unwrittenPages as $pageNum => $page) {
                if (!$page instanceof BTreeLeafPage) {
                    continue;
                }

                $parent = $page->parent;
                foreach ($page->splitOversized(self::PAGE_SIZE) as $leaf) {
                    if ($leaf === $page) {
                        continue; // Original already in tree
                    }
                    $newPageNum = $this->allocatePage($leaf);
                    $promoteKey = $leaf->entries[0][0];

                    if ($parent !== null) {
                        $parent->writeBuffer[] = [$promoteKey, $newPageNum];
                    }
                }
            }
        }

        // Phase 2: Process internal nodes level by level (children before parents)
        // Repeatedly scan until no more work is needed
        $changed = true;
        while ($changed) {
            $changed = false;

            // Process all internal nodes in unwrittenPages
            foreach ($this->unwrittenPages as $page) {
                if (!$page instanceof BTreeInternalPage) {
                    continue;
                }
                if ($page->writeBuffer === [] && $page->calculateSize() <= self::PAGE_SIZE) {
                    continue; // No work needed
                }

                $parent = $page->parent;
                $splitPages = [];
                foreach ($page->splitOversized(self::PAGE_SIZE) as $internal) {
                    $splitPages[] = $internal;
                }

                if (\count($splitPages) > 1) {
                    $changed = true;
                    foreach ($splitPages as $internal) {
                        if ($internal === $page) {
                            continue;
                        }
                        $pageNum = $this->allocatePage($internal);
                        if ($parent !== null) {
                            $parent->writeBuffer[] = [$internal->promoteKey, $pageNum];
                        }
                    }
                }
            }
        }

        // Finally process the root if needed
        if ($this->currentRoot instanceof BTreeInternalPage) {
            if ($this->currentRoot->writeBuffer !== [] ||
                $this->currentRoot->calculateSize() > self::PAGE_SIZE) {

                $splitPages = [];
                foreach ($this->currentRoot->splitOversized(self::PAGE_SIZE) as $internal) {
                    $splitPages[] = $internal;
                }

                if (\count($splitPages) > 1) {
                    $children = [];
                    $keys = [];
                    foreach ($splitPages as $internal) {
                        $pageNum = $this->allocatePage($internal);
                        $children[] = $pageNum;
                        if ($internal->promoteKey !== null) {
                            $keys[] = $internal->promoteKey;
                        }
                    }
                    $this->currentRoot = BTreeInternalPage::fromArrays($children, $keys);
                }
            }
        }
    }

    /**
     * Set parent references on all pages in unwrittenPages by traversing from root.
     */
    private function setParentReferences(): void
    {
        if (!$this->currentRoot instanceof BTreeInternalPage) {
            return;
        }

        $this->setParentReferencesRecursive($this->currentRoot);
    }

    /**
     * Recursively set parent references on children.
     */
    private function setParentReferencesRecursive(BTreeInternalPage $node): void
    {
        foreach ($node->children as $childNum) {
            $child = $this->unwrittenPages[$childNum] ?? null;
            if ($child !== null) {
                $child->parent = $node;
                if ($child instanceof BTreeInternalPage) {
                    $this->setParentReferencesRecursive($child);
                }
            }
        }
    }

    // =========================================================================
    // IndexInterface implementation
    // =========================================================================

    public function insert(string $key, int $rowId): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException("Key exceeds maximum length of " . self::MAX_KEY_LENGTH);
        }

        $autoCommit = false;
        if (!$this->inTransaction) {
            $this->begin();
            $autoCommit = true;
        }

        try {
            // begin() guarantees $currentRoot is never null
            $path = $this->findPath($key);
            $this->insertIntoTree($key, $rowId, $path);

            if ($autoCommit) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($autoCommit) {
                $this->rollback();
            }
            throw $e;
        }
    }

    public function delete(string $key, int $rowId): void
    {
        $autoCommit = false;
        if (!$this->inTransaction) {
            $this->begin();
            $autoCommit = true;
        }

        try {
            if ($this->currentRoot === null) {
                // Empty tree - nothing to delete
                if ($autoCommit) {
                    $this->rollback();
                }
                return;
            }

            $path = $this->findPath($key);
            $this->deleteFromTree($key, $rowId, $path);

            if ($autoCommit) {
                $this->commit();
            }
        } catch (\Throwable $e) {
            if ($autoCommit) {
                $this->rollback();
            }
            throw $e;
        }
    }

    public function eq(string $key): Traversable
    {
        // Outside transaction, refresh from disk periodically
        if (!$this->inTransaction) {
            $this->readHeaderWithLock();
        }

        if ($this->currentRoot === null) {
            return;
        }

        // Navigate to leaf containing the key (0-based)
        $page = $this->currentRoot;
        $stack = [];
        $stackTop = 0;
        $leafPageNum = null; // null = root is the leaf, don't release

        while (!($page instanceof BTreeLeafPage)) {
            $childIdx = $this->findChildIndex($page->keys, $page->childCount - 1, $key);
            $leafPageNum = $page->children[$childIdx];
            $stack[$stackTop++] = $leafPageNum;
            $stack[$stackTop++] = $childIdx;
            $stack[$stackTop++] = $page;
            $page = $this->getPage($leafPageNum);
        }
        $leaf = $page;

        // Check writeBuffer first (only on initial leaf - that's where the key would be buffered)
        if (isset($leaf->writeBuffer[$key])) {
            foreach ($leaf->writeBuffer[$key] as $rowId) {
                yield $rowId;
            }
        }

        // Binary search within leaf using getKeyAt() - no rowId unpacking for probes
        $n = $leaf->count;
        if ($n === 0) {
            if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
            return;
        }

        // Binary search for first entry >= key (0-based)
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($leaf->getKeyAt($mid), $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        // Yield all matching entries (high-cardinality keys may span multiple entries/leaves)
        while (true) {
            // Yield matching entries in current leaf
            while ($lo < $n && $leaf->getKeyAt($lo) === $key) {
                $entry = $leaf->getEntry($lo);
                $rowIdCount = count($entry) - 1;
                for ($j = 1; $j <= $rowIdCount; $j++) {
                    yield $entry[$j];
                }
                $lo++;
            }

            // If we didn't exhaust the leaf, key is done
            if ($lo < $n) {
                if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
                return;
            }

            // Try to find next leaf via parent stack
            if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);

            $foundNext = false;
            while ($stackTop > 0) {
                $parentPage = $stack[--$stackTop];
                $childIdx = $stack[--$stackTop];
                $parentNum = $stack[--$stackTop];

                if ($childIdx < $parentPage->childCount - 1) {
                    // Move to next sibling
                    $childIdx++;
                    $stack[$stackTop++] = $parentNum;
                    $stack[$stackTop++] = $childIdx;
                    $stack[$stackTop++] = $parentPage;
                    $leafPageNum = $parentPage->children[$childIdx];

                    // Navigate down to leftmost leaf
                    while (true) {
                        $page = $this->getPage($leafPageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }
                        $stack[$stackTop++] = $leafPageNum;
                        $stack[$stackTop++] = 0;
                        $stack[$stackTop++] = $page;
                        $leafPageNum = $page->children[0];
                    }

                    // Check if first entry matches
                    $n = $leaf->count;
                    if ($n > 0 && $leaf->getKeyAt(0) === $key) {
                        $lo = 0;
                        $foundNext = true;
                        break; // Continue outer loop
                    }

                    // Next leaf doesn't have our key
                    $this->releaseLeaf($leaf, $leafPageNum);
                    return;
                }
            }

            if (!$foundNext) {
                return; // Exhausted stack
            }
        }
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        // Outside transaction, refresh from disk periodically
        if (!$this->inTransaction) {
            $this->readHeaderWithLock();
        }

        if ($this->currentRoot === null) {
            return;
        }

        if ($reverse) {
            yield from $this->rangeReverse($start, $end);
        } else {
            yield from $this->rangeForward($start, $end);
        }
    }

    // =========================================================================
    // Optional helper methods
    // =========================================================================

    public function has(string $key): bool
    {
        foreach ($this->eq($key) as $_) {
            return true;
        }
        return false;
    }

    public function count(string $key): int
    {
        $count = 0;
        foreach ($this->eq($key) as $_) {
            $count++;
        }
        return $count;
    }

    public function close(): void
    {
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
        if ($this->lockFile !== null) {
            fclose($this->lockFile);
            $this->lockFile = null;
        }
    }

    public function compact(): void
    {
        $this->withWriteLock(function() {
            $this->findLatestRoot();

            if ($this->rootPage === 0) {
                return; // Nothing to compact
            }

            $tempPath = $this->path . '.compact.' . getmypid();
            $temp = fopen($tempPath, 'c+b');
            if ($temp === false) {
                throw new \RuntimeException("Failed to create temp file: $tempPath");
            }

            try {
                // Write minimal header (page 0)
                $header = pack('VCC', self::MAGIC, self::VERSION, 0);
                fwrite($temp, pack('a' . self::PAGE_SIZE, $header));

                // Rewrite all reachable pages
                $pageMap = []; // old page => new page
                $newNextPage = 1;

                $newRoot = $this->rewritePages($temp, $this->rootPage, $pageMap, $newNextPage);

                // Mark root with CRC
                fseek($temp, $newRoot * self::PAGE_SIZE);
                $page = fread($temp, self::PAGE_SIZE);
                $type = ord($page[0]);
                $page[0] = chr($type | 0x80);
                $crc = crc32(substr($page, 0, self::CRC_OFFSET));
                $page = substr($page, 0, self::CRC_OFFSET) . pack('V', $crc);

                // Append root with CRC as final page
                fseek($temp, $newNextPage * self::PAGE_SIZE);
                fwrite($temp, $page);

                fclose($temp);

                // Atomic rename
                $this->close();
                if (!rename($tempPath, $this->path)) {
                    throw new \RuntimeException("Failed to rename temp file");
                }
                $this->open();
            } catch (\Throwable $e) {
                fclose($temp);
                @unlink($tempPath);
                throw $e;
            }
        });
    }

    // =========================================================================
    // File operations
    // =========================================================================

    private function open(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->file = fopen($this->path, 'c+b');
        if ($this->file === false) {
            throw new \RuntimeException("Failed to open index file: {$this->path}");
        }

        $this->lockFile = fopen($this->path . '.lock', 'c+b');
        if ($this->lockFile === false) {
            fclose($this->file);
            $this->file = null;
            throw new \RuntimeException("Failed to open lock file: {$this->path}.lock");
        }

        // Check if file is empty
        fseek($this->file, 0, SEEK_END);
        $size = ftell($this->file);

        if ($size === 0) {
            // New file - write minimal header (page 0 with just magic/version)
            $this->rootPage = 0;
            $this->nextPage = 1;
            $this->sequence = 0;
            $this->writeFileHeader();
        } else {
            // Existing file - find latest root by scanning backwards
            $this->findLatestRoot();
        }
    }

    /**
     * Write minimal file header (page 0) - just magic and version.
     * Root is found by scanning for latest valid root page.
     */
    private function writeFileHeader(): void
    {
        $header = pack('VCC', self::MAGIC, self::VERSION, 0);
        fseek($this->file, 0);
        fwrite($this->file, pack('a' . self::PAGE_SIZE, $header));
        fflush($this->file);
    }

    /**
     * Append root page with root marker and CRC.
     * Reads current root, marks it as root, computes CRC, appends as new page.
     */
    /**
     * Write root page object with CRC marker (for new overlay approach).
     */
    private function writeRootWithCrc(BTreeLeafPage|BTreeInternalPage $root): void
    {
        $page = pack('a' . self::PAGE_SIZE, $root->asString());

        // Set root marker (high bit in type byte)
        $type = ord($page[0]);
        $page[0] = chr($type | 0x80);

        // Compute and store CRC (last 4 bytes)
        $crc = crc32(substr($page, 0, self::CRC_OFFSET));
        $page = substr($page, 0, self::CRC_OFFSET) . pack('V', $crc);

        // Append as new page
        $this->rootPage = $this->nextPage++;
        fseek($this->file, $this->rootPage * self::PAGE_SIZE);
        fwrite($this->file, $page);
        fflush($this->file);
    }

    /**
     * Find the latest valid root page by scanning backwards from file end.
     * Root pages have high bit set in type byte and valid CRC in last 4 bytes.
     * Clears page cache if root changed.
     */
    private function findLatestRoot(): void
    {
        fseek($this->file, 0, SEEK_END);
        $size = ftell($this->file);
        $numPages = (int)($size / self::PAGE_SIZE);
        $oldRoot = $this->rootPage;

        // Scan backwards for root page with valid CRC
        for ($pageNum = $numPages - 1; $pageNum >= 1; $pageNum--) {
            fseek($this->file, $pageNum * self::PAGE_SIZE);
            $page = fread($this->file, self::PAGE_SIZE);

            if (strlen($page) < self::PAGE_SIZE) {
                continue;
            }

            $type = ord($page[0]);

            // Check if this is a root page (high bit set)
            if ($type !== self::PAGE_INTERNAL_ROOT && $type !== self::PAGE_LEAF_ROOT) {
                continue;
            }

            // Validate CRC (stored in last 4 bytes)
            $storedCrc = unpack('V', $page, self::CRC_OFFSET)[1];
            $dataCrc = crc32(substr($page, 0, self::CRC_OFFSET));

            if ($storedCrc === $dataCrc) {
                // Valid root found - next write overwrites any garbage after root
                $this->rootPage = $pageNum;
                $this->nextPage = $pageNum + 1;
                // Load root page object
                $baseType = $type & 0x7F;
                $this->currentRoot = ($baseType === self::PAGE_LEAF)
                    ? BTreeLeafPage::fromRaw($page)
                    : BTreeInternalPage::fromRaw($page);
                if ($oldRoot !== $pageNum) {
                    $this->sequence++;
                    $this->pageCache = [];
                }
                return;
            }
        }

        // No valid root found - create empty leaf as root
        $this->rootPage = 0;
        $this->nextPage = 1;
        $emptyRoot = BTreeLeafPage::fromPool();
        $emptyRoot->count = 0;
        $emptyRoot->entriesBuilt = true;
        $this->currentRoot = $emptyRoot;
        if ($oldRoot !== 0) {
            $this->sequence++;
            $this->pageCache = [];
        }
    }

    private function readHeaderWithLock(): void
    {
        // Skip check if we've checked recently (optimization for read-heavy workloads)
        if (++$this->queryCount < self::HEADER_CHECK_INTERVAL) {
            return;
        }
        $this->queryCount = 0;

        flock($this->lockFile, LOCK_SH);
        try {
            $this->findLatestRoot();
        } finally {
            flock($this->lockFile, LOCK_UN);
        }
    }

    private function withWriteLock(callable $fn): void
    {
        flock($this->lockFile, LOCK_EX);
        try {
            $fn();
        } finally {
            flock($this->lockFile, LOCK_UN);
        }
    }

    // =========================================================================
    // Page operations
    // =========================================================================

    /**
     * Read raw page from disk (no caching).
     */
    private function readPageRaw(int $pageNum): string
    {
        // Page 0 is the header page, data pages start at 1
        $offset = $pageNum * self::PAGE_SIZE;
        fseek($this->file, $offset);
        $data = fread($this->file, self::PAGE_SIZE);

        if (strlen($data) < self::PAGE_SIZE) {
            throw new \RuntimeException("Corrupted index: page $pageNum truncated");
        }

        return $data;
    }

    /**
     * Load a page from unwritten overlay, cache, or disk.
     */
    private function getPage(int $pageNum): BTreeLeafPage|BTreeInternalPage
    {
        // Check uncommitted pages (transaction overlay)
        if (isset($this->unwrittenPages[$pageNum])) {
            return $this->unwrittenPages[$pageNum];
        }

        // Check cache (committed internal nodes)
        if (isset($this->pageCache[$pageNum])) {
            return $this->pageCache[$pageNum];
        }

        // Read from disk
        $data = $this->readPageRaw($pageNum);
        $type = ord($data[0]) & 0x7F; // Mask off root marker bit

        if ($type === self::PAGE_LEAF) {
            return BTreeLeafPage::fromRaw($data);
        }

        $page = BTreeInternalPage::fromRaw($data);
        $this->pageCache[$pageNum] = $page;
        return $page;
    }

    /**
     * Allocate a new page in the overlay. Returns the page number.
     * Used when creating new pages (e.g., splits).
     */
    private function allocatePage(BTreeLeafPage|BTreeInternalPage $page): int
    {
        $pageNum = $this->nextPage + $this->unwrittenPageCount++;
        $this->unwrittenPages[$pageNum] = $page;
        return $pageNum;
    }

    /**
     * Get a page for modification. If from disk/cache, moves to overlay.
     * Updates $pageNum by reference to the new page number in overlay.
     */
    private function getPageForWrite(int &$pageNum): BTreeLeafPage|BTreeInternalPage
    {
        // Already in overlay - return as-is
        if (isset($this->unwrittenPages[$pageNum])) {
            return $this->unwrittenPages[$pageNum];
        }

        // Remove from cache if present (avoid double reference)
        $oldPageNum = $pageNum;
        unset($this->pageCache[$oldPageNum]);

        // Move to overlay with new page number
        $page = $this->getPage($oldPageNum);
        $pageNum = $this->nextPage + $this->unwrittenPageCount++;
        $this->unwrittenPages[$pageNum] = $page;
        return $page;
    }

    /**
     * Release a leaf page back to pool, but only if it's not in the overlay.
     * Pages from unwrittenPages must not be released - they're still needed.
     */
    private function releaseLeaf(BTreeLeafPage $leaf, int $pageNum): void
    {
        if (!isset($this->unwrittenPages[$pageNum])) {
            $leaf->release();
        }
    }

    // =========================================================================
    // Internal page format
    // =========================================================================

    /**
     * Find child index for given key in internal node.
     * Returns 0-based index into $children array.
     * Keys array is 0-based.
     */
    private function findChildIndex(array $keys, int $keyCount, string $key): int
    {
        // Binary search for first key >= $key (keys are 0-based)
        $lo = 0;
        $hi = $keyCount;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($keys[$mid], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo; // 0-based child index
    }

    // =========================================================================
    // Tree traversal
    // =========================================================================

    /**
     * Find path from root to leaf for given key.
     *
     * Returns flat array: [pageNum, childIdx, pageNum, childIdx, ...]
     * - pageNum: null for root, otherwise actual page number
     * - childIdx: -1 for leaf, otherwise which child was followed (0-based)
     *
     * Example paths:
     * - [null, -1] - root is a leaf
     * - [null, 1, 5, -1] - root is internal, followed child[1], leaf is page 5
     * - [null, 0, 3, 0, 7, -1] - two internal levels before leaf
     */
    private function findPath(string $key): array
    {
        $path = [];
        $page = $this->currentRoot;
        $pageNum = null; // null = root, then becomes actual page number

        while (true) {
            if ($page instanceof BTreeLeafPage) {
                $path[] = $pageNum;
                $path[] = -1;
                return $path;
            }

            // Find child index via binary search (keys are 0-based)
            // Finds first key > target, result is 0-based child index
            $keyCount = $page->childCount - 1;
            $lo = 0;
            $hi = $keyCount;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($page->keys[$mid], $key) <= 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $path[] = $pageNum;
            $path[] = $lo; // 0-based child index

            // Descend to child - children array is 0-based
            $childPageNum = $page->children[$lo];
            $pageNum = $childPageNum; // Now actual page number, not null
            $page = $this->getPage($childPageNum);
        }
    }

    // =========================================================================
    // Insert
    // =========================================================================

    private function insertIntoTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path - last two elements are [leafPageNum, -1]
        $pathLen = count($path);
        $leafPageNum = $path[$pathLen - 2];

        // Get leaf for modification - either root or from overlay
        if ($leafPageNum === null) {
            $leaf = $this->currentRoot;
        } else {
            $leaf = $this->getPageForWrite($leafPageNum);
            // getPageForWrite may create new page number - update parent pointers
            $this->updatePath($path, $pathLen - 4, $leafPageNum);
        }

        // Check writeBuffer first
        if (isset($leaf->writeBuffer[$key])) {
            $leaf->writeBuffer[$key][] = $rowId;
            return;
        }

        // Check entries for existing key
        $leaf->buildEntries();
        $n = $leaf->count;
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($leaf->entries[$mid][0], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        if ($lo < $n && $leaf->entries[$lo][0] === $key) {
            // Key exists in entries - append rowId
            $leaf->entries[$lo][] = $rowId;
            $leaf->rebuildMeta();
            return;
        }

        // New key - add to writeBuffer (splitting happens at finalize time)
        $leaf->writeBuffer[$key] = [$rowId];
    }

    /**
     * Propagate a split up the tree.
     * Called after a leaf or internal node split to insert the new child pointer.
     * @param int $pathIdx Index of parent entry in path (pageNum at $pathIdx, childIdx at $pathIdx+1)
     */
    private function propagateSplit(array $path, int $pathIdx, int $leftChild, int $rightChild, string $promoteKey): void
    {
        if ($pathIdx < 0) {
            // Create new root with the two children
            $this->currentRoot = BTreeInternalPage::fromArrays([$leftChild, $rightChild], [$promoteKey]);
            return;
        }

        // Get parent from path (flat: pageNum, childIndex - childIndex is 0-based)
        $parentPageNum = $path[$pathIdx];
        $childIndex = $path[$pathIdx + 1]; // 0-based

        // Get parent for modification
        if ($parentPageNum === null) {
            $parent = $this->currentRoot;
        } else {
            $parent = $this->getPageForWrite($parentPageNum); // $parentPageNum updated
        }

        // Insert new child and key (0-based arrays)
        $oldChildCount = $parent->childCount;
        $oldKeyCount = $oldChildCount - 1;
        $newChildren = [];
        $newKeys = [];

        // Copy children up to split point, replacing split child with left
        for ($i = 0; $i < $childIndex; $i++) {
            $newChildren[] = $parent->children[$i];
        }
        $newChildren[] = $leftChild;
        $newChildren[] = $rightChild;
        for ($i = $childIndex + 1; $i < $oldChildCount; $i++) {
            $newChildren[] = $parent->children[$i];
        }

        // Copy keys, inserting promoteKey at position childIndex
        for ($i = 0; $i < $childIndex; $i++) {
            $newKeys[] = $parent->keys[$i];
        }
        $newKeys[] = $promoteKey;
        for ($i = $childIndex; $i < $oldKeyCount; $i++) {
            $newKeys[] = $parent->keys[$i];
        }

        // Update parent using setChildrenAndKeys
        $parent->setChildrenAndKeys($newChildren, $newKeys);

        if (strlen($parent->asString()) <= self::PAGE_SIZE) {
            // Fits - page already modified in overlay
            if ($parentPageNum !== null) {
                // Update grandparent pointers to new page number
                $this->updatePath($path, $pathIdx - 2, $parentPageNum);
            }
        } else {
            // Split internal node
            $newKeyCount = $oldKeyCount + 1;
            $mid = $newKeyCount >> 1; // 0-based mid key index

            // Left: keys 0..mid-1, children 0..mid (0-based)
            // Right: keys mid+1..n-1, children mid+1..n
            $leftChildren = [];
            $leftKeys = [];
            for ($i = 0; $i <= $mid; $i++) {
                $leftChildren[] = $newChildren[$i];
            }
            for ($i = 0; $i < $mid; $i++) {
                $leftKeys[] = $newKeys[$i];
            }

            $rightChildren = [];
            $rightKeys = [];
            for ($i = $mid + 1; $i < $oldChildCount + 1; $i++) {
                $rightChildren[] = $newChildren[$i];
            }
            for ($i = $mid + 1; $i < $newKeyCount; $i++) {
                $rightKeys[] = $newKeys[$i];
            }

            $splitPromoteKey = $newKeys[$mid];

            // Reuse left page (already in overlay), create new right page
            $parent->setChildrenAndKeys($leftChildren, $leftKeys);
            $leftNum = $parentPageNum ?? $this->allocatePage($parent);

            $rightPage = BTreeInternalPage::fromArrays($rightChildren, $rightKeys);
            $rightNum = $this->allocatePage($rightPage);

            $this->propagateSplit($path, $pathIdx - 2, $leftNum, $rightNum, $splitPromoteKey);
        }
    }

    /**
     * Update parent pointers after a child was replaced.
     * Walks up the path, updating each internal node's child pointer.
     * @param int $pathIdx Index of first parent entry to update
     */
    private function updatePath(array $path, int $pathIdx, int $newChild): void
    {
        while ($pathIdx >= 0) {
            $parentPageNum = $path[$pathIdx];
            $childIndex = $path[$pathIdx + 1]; // 0-based

            // Get parent for modification
            if ($parentPageNum === null) {
                $parent = $this->currentRoot;
            } else {
                $parent = $this->getPageForWrite($parentPageNum); // $parentPageNum updated
            }

            // Replace child pointer directly (0-based)
            $parent->children[$childIndex] = $newChild;

            if ($parentPageNum === null) {
                // Root modified in place, done
                return;
            }

            // Continue up with new page number
            $newChild = $parentPageNum;
            $pathIdx -= 2;
        }
    }

    // =========================================================================
    // Delete
    // =========================================================================

    private function deleteFromTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path - last two elements are [leafPageNum, -1]
        $pathLen = count($path);
        $leafPageNum = $path[$pathLen - 2];

        // Get leaf for modification
        if ($leafPageNum === null) {
            $leaf = $this->currentRoot;
        } else {
            $leaf = $this->getPageForWrite($leafPageNum);
        }

        // Check writeBuffer first
        if (isset($leaf->writeBuffer[$key])) {
            $rowIds = $leaf->writeBuffer[$key];
            $newRowIds = [];
            $found = false;
            foreach ($rowIds as $rid) {
                if (!$found && $rid === $rowId) {
                    $found = true; // Skip this one (only first match)
                } else {
                    $newRowIds[] = $rid;
                }
            }
            if ($found) {
                if ($newRowIds === []) {
                    unset($leaf->writeBuffer[$key]);
                } else {
                    $leaf->writeBuffer[$key] = $newRowIds;
                }
                return;
            }
        }

        $leaf->buildEntries();
        $n = $leaf->count;

        // Find key and remove rowId (0-based entries, each entry: [key, id1, id2, ...])
        $found = false;
        $removeIdx = -1;
        for ($i = 0; $i < $n; $i++) {
            $entry = $leaf->entries[$i];
            if ($entry[0] === $key) {
                // Search for rowId in positions 1, 2, 3...
                $rowIdCount = count($entry) - 1;
                for ($j = 1; $j <= $rowIdCount; $j++) {
                    if ($entry[$j] === $rowId) {
                        // Rebuild entry without this rowId
                        $newEntry = [$entry[0]]; // key
                        for ($k = 1; $k <= $rowIdCount; $k++) {
                            if ($k !== $j) {
                                $newEntry[] = $entry[$k];
                            }
                        }

                        if (count($newEntry) === 1) {
                            // No more rowIds - mark for removal
                            $removeIdx = $i;
                        } else {
                            $leaf->entries[$i] = $newEntry;
                        }

                        $found = true;
                        break;
                    }
                }
                break;
            }
        }

        if (!$found) {
            return; // Key/rowId not found, nothing to do
        }

        // Remove entry if marked - shift entries left
        if ($removeIdx >= 0) {
            for ($i = $removeIdx; $i < $n - 1; $i++) {
                $leaf->entries[$i] = $leaf->entries[$i + 1];
            }
            $leaf->count = $n - 1;
        }

        $leaf->rebuildMeta();

        if ($leafPageNum !== null) {
            // Update parent pointers to new page number
            $this->updatePath($path, $pathLen - 4, $leafPageNum);
        }
    }

    // =========================================================================
    // Range queries
    // =========================================================================

    private function rangeForward(?string $start, ?string $end): \Generator
    {
        $startKey = $start ?? "\x00";
        $page = $this->currentRoot;
        $stack = [];
        $stackTop = 0;
        $leafPageNum = null; // null = root is the leaf, don't release

        // Navigate to first relevant leaf (0-based)
        while (!($page instanceof BTreeLeafPage)) {
            $childIdx = $this->findChildIndex($page->keys, $page->childCount - 1, $startKey);
            $leafPageNum = $page->children[$childIdx];
            $stack[$stackTop++] = $leafPageNum;
            $stack[$stackTop++] = $childIdx;
            $stack[$stackTop++] = $page;
            $page = $this->getPage($leafPageNum);
        }
        $leaf = $page;

        // Iterate through leaves (entries are 0-based)
        while (true) {
            $leaf->importWriteBuffer();
            $leaf->buildEntries();
            $entries = $leaf->entries;
            $entryCount = $leaf->count;

            for ($e = 0; $e < $entryCount; $e++) {
                $entry = $entries[$e];
                $key = $entry[0];
                if ($start !== null && strcmp($key, $start) < 0) {
                    continue;
                }
                if ($end !== null && strcmp($key, $end) > 0) {
                    if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($i = 1; $i <= $rowIdCount; $i++) {
                    yield $entry[$i];
                }
            }

            // Release current leaf before getting next
            if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);

            // Find next leaf via backtracking (0-based)
            $found = false;
            while ($stackTop > 0) {
                $parentPage = $stack[--$stackTop];
                $childIdx = $stack[--$stackTop];
                $parentNum = $stack[--$stackTop];

                if ($childIdx < $parentPage->childCount - 1) {
                    $childIdx++;
                    $stack[$stackTop++] = $parentNum;
                    $stack[$stackTop++] = $childIdx;
                    $stack[$stackTop++] = $parentPage;
                    $leafPageNum = $parentPage->children[$childIdx];

                    // Navigate down to leftmost leaf
                    while (true) {
                        $page = $this->getPage($leafPageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $stack[$stackTop++] = $leafPageNum;
                        $stack[$stackTop++] = 0;
                        $stack[$stackTop++] = $page;
                        $leafPageNum = $page->children[0];
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return;
            }
        }
    }

    private function rangeReverse(?string $start, ?string $end): \Generator
    {
        $page = $this->currentRoot;
        $stack = [];
        $stackTop = 0;
        $leafPageNum = null; // null = root is the leaf, don't release

        // Navigate to last relevant leaf (0-based)
        while (!($page instanceof BTreeLeafPage)) {
            // Find rightmost child that could contain keys <= end
            $childIdx = $page->childCount - 1; // Start with rightmost (0-based)
            if ($end !== null) {
                $keyCount = $page->childCount - 1;
                for ($i = $keyCount - 1; $i >= 0; $i--) {
                    if (strcmp($page->keys[$i], $end) <= 0) {
                        $childIdx = $i + 1;
                        break;
                    }
                    $childIdx = $i;
                }
            }

            $leafPageNum = $page->children[$childIdx];
            $stack[$stackTop++] = $leafPageNum;
            $stack[$stackTop++] = $childIdx;
            $stack[$stackTop++] = $page;
            $page = $this->getPage($leafPageNum);
        }
        $leaf = $page;

        // Iterate through leaves in reverse (entries are 0-based)
        while (true) {
            $leaf->importWriteBuffer();
            $leaf->buildEntries();
            $entries = $leaf->entries;
            $entryCount = $leaf->count;

            for ($e = $entryCount - 1; $e >= 0; $e--) {
                $entry = $entries[$e];
                $key = $entry[0];
                if ($end !== null && strcmp($key, $end) > 0) {
                    continue;
                }
                if ($start !== null && strcmp($key, $start) < 0) {
                    if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($j = $rowIdCount; $j >= 1; $j--) {
                    yield $entry[$j];
                }
            }

            // Release current leaf before getting next
            if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);

            // Find previous leaf via backtracking (0-based)
            $found = false;
            while ($stackTop > 0) {
                $parentPage = $stack[--$stackTop];
                $childIdx = $stack[--$stackTop];
                $parentNum = $stack[--$stackTop];

                if ($childIdx > 0) {
                    $childIdx--;
                    $stack[$stackTop++] = $parentNum;
                    $stack[$stackTop++] = $childIdx;
                    $stack[$stackTop++] = $parentPage;
                    $leafPageNum = $parentPage->children[$childIdx];

                    // Navigate down to rightmost leaf
                    while (true) {
                        $page = $this->getPage($leafPageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $lastChild = $page->childCount - 1; // 0-based last child
                        $stack[$stackTop++] = $leafPageNum;
                        $stack[$stackTop++] = $lastChild;
                        $stack[$stackTop++] = $page;
                        $leafPageNum = $page->children[$lastChild];
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return;
            }
        }
    }

    // =========================================================================
    // Compaction
    // =========================================================================

    /**
     * Recursively rewrite reachable pages to temp file.
     * @param resource $temp Temp file handle
     * @param int $pageNum Page to rewrite
     * @param array<int, int> $pageMap Old page => new page mapping
     * @param int $newNextPage Next page number in temp file
     * @return int New page number in temp file
     */
    private function rewritePages($temp, int $pageNum, array &$pageMap, int &$newNextPage): int
    {
        if (isset($pageMap[$pageNum])) {
            return $pageMap[$pageNum];
        }

        $page = $this->getPage($pageNum);

        if ($page instanceof BTreeLeafPage) {
            // Leaf - copy raw bytes directly
            $rawPage = $this->readPageRaw($pageNum);
            $newPageNum = $newNextPage++;
            $pageMap[$pageNum] = $newPageNum;

            $offset = $newPageNum * self::PAGE_SIZE;
            fseek($temp, $offset);
            fwrite($temp, $rawPage);

            return $newPageNum;
        }

        // Internal node - recursively rewrite children first (0-based)
        $newChildren = [];
        for ($i = 0; $i < $page->childCount; $i++) {
            $newChildren[] = $this->rewritePages($temp, $page->children[$i], $pageMap, $newNextPage);
        }

        // Create new internal page with updated children (keys are 0-based)
        $keys0 = [];
        $keyCount = $page->childCount - 1;
        for ($i = 0; $i < $keyCount; $i++) {
            $keys0[] = $page->keys[$i];
        }
        $newNode = BTreeInternalPage::fromArrays($newChildren, $keys0);

        $newPageNum = $newNextPage++;
        $pageMap[$pageNum] = $newPageNum;

        $offset = $newPageNum * self::PAGE_SIZE;
        fseek($temp, $offset);
        fwrite($temp, pack('a' . self::PAGE_SIZE, $newNode->asString()));

        return $newPageNum;
    }
}
