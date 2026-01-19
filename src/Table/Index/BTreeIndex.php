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
    /** @var array Header metadata: offsets (1..n+1) + rowIdCounts (n+2..2n+1), 1-based */
    public array $meta;
    /** @var array<array<int, string|int>> Cached entries for scans */
    public array $entries = [];
    /** Whether entries array is valid for current page data */
    public bool $entriesBuilt = false;

    private function __construct() {}

    public static function fromRaw(string $data): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }
        $instance->data = $data;
        $instance->count = \ord($data[1]) | (\ord($data[2]) << 8);
        // Single unpack: n+1 offsets + n rowIdCounts = 2n+1 uint16 values (1-based)
        $instance->meta = $instance->count > 0
            ? \unpack('v' . (2 * $instance->count + 1), $data, 3)
            : [];
        $instance->entriesBuilt = false;
        return $instance;
    }

    /**
     * Build and cache all entries for efficient scan iteration.
     * Use $leaf->count to bound iteration, not count($entries).
     */
    public function buildEntries(): void
    {
        if ($this->entriesBuilt) {
            return;
        }
        $n = $this->count;
        $meta = $this->meta;
        $data = $this->data;
        for ($i = 0; $i < $n; $i++) {
            $j = $i + 1; // 1-based index into meta
            $pos = $meta[$j];
            $rowIdCount = $meta[$n + 1 + $j];
            $keyLen = $meta[$j + 1] - $pos - ($rowIdCount << 3);
            $entry = \unpack('P' . $rowIdCount, $data, $pos);
            $entry[0] = \substr($data, $pos + ($rowIdCount << 3), $keyLen);
            $this->entries[$i] = $entry;
        }
        $this->entriesBuilt = true;
    }

    /**
     * Get entries as array (for modification in insert/delete).
     * @return array<array<int, string|int>>
     */
    public function toArray(): array
    {
        $this->buildEntries();
        return \array_slice($this->entries, 0, $this->count);
    }

    /**
     * Get just the key at 1-based index (for binary search comparisons).
     * Avoids unpacking rowIds - much faster for probes.
     */
    public function getKeyAt(int $i): string
    {
        $n = $this->count;
        $pos = $this->meta[$i];
        $rowIdCount = $this->meta[$n + 1 + $i];
        $keyLen = $this->meta[$i + 1] - $pos - ($rowIdCount << 3);
        return \substr($this->data, $pos + ($rowIdCount << 3), $keyLen);
    }

    /**
     * Get single entry by 1-based index (for yielding rowIds after match).
     * @return array<int, string|int> Flat: [0 => key, 1 => rowId1, ...]
     */
    public function getEntry(int $i): array
    {
        $n = $this->count;
        $pos = $this->meta[$i];
        $nextPos = $this->meta[$i + 1];
        $rowIdCount = $this->meta[$n + 1 + $i];
        $keyLen = $nextPos - $pos - ($rowIdCount << 3);

        $result = \unpack('P' . $rowIdCount, $this->data, $pos);
        $result[0] = \substr($this->data, $pos + ($rowIdCount << 3), $keyLen);
        return $result;
    }

    public function release(): void
    {
        // Don't clear data/meta/entries - fromRaw() will overwrite them
        self::$pool[self::$poolCount++] = $this;
    }

    /**
     * Serialize to binary page format using precomputed meta.
     */
    public function asString(): string
    {
        $n = $this->count;
        if ($n === 0) {
            return \pack('Cv', BTreeIndex::PAGE_LEAF, 0);
        }

        $meta = $this->meta;
        $entries = $this->entries;

        // Extract offsets (n+1) and rowIdCounts (n) from 1-based meta
        $offsets = [];
        $rowIdCounts = [];
        for ($i = 1; $i <= $n + 1; $i++) {
            $offsets[] = $meta[$i];
        }
        for ($i = $n + 2; $i <= 2 * $n + 1; $i++) {
            $rowIdCounts[] = $meta[$i];
        }

        // Build entry data: rowIds + key for each entry (rowIdCount always >= 1)
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
     * Get a leaf page from pool (or create new) and set entries.
     * Builds meta immediately for consistency.
     * @param array<array<int, string|int>> $entries Flat format [[key, rowId1, ...], ...]
     */
    public static function fromEntries(array $entries): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }

        $n = \count($entries);
        $instance->entries = $entries;
        $instance->count = $n;
        $instance->entriesBuilt = true;

        if ($n === 0) {
            $instance->meta = [];
            return $instance;
        }

        // Build meta: offsets (1..n+1) + rowIdCounts (n+2..2n+1), 1-based
        // Header size: type(1) + count(2) + offsets((n+1) * 2) + rowIdCounts(n * 2)
        $headerSize = 3 + ($n + 1) * 2 + $n * 2;
        $meta = [];
        $offset = $headerSize;

        for ($i = 0; $i < $n; $i++) {
            $entry = $entries[$i];
            $meta[$i + 1] = $offset; // offset at 1-based index
            $rowIdCount = \count($entry) - 1;
            $meta[$n + 2 + $i] = $rowIdCount; // rowIdCount at n+2+i
            $offset += $rowIdCount * 8 + \strlen($entry[0]);
        }
        $meta[$n + 1] = $offset; // end marker

        $instance->meta = $meta;
        return $instance;
    }
}

/**
 * Parsed internal page - children are page numbers, keys are separators.
 *
 * Page format:
 * - Header: type(1) + count(2) + children((n+1) * 8) + offsets((n+1) * 2)
 * - Key data: keys concatenated (keyLen = offsets[i+1] - offsets[i])
 *
 * Keys are parsed eagerly since internal pages are cached and reused.
 *
 * @internal
 */
final class BTreeInternalPage
{
    /** @var self[] */
    private static array $pool = [];
    private static int $poolCount = 0;

    /** Number of children */
    public int $childCount;
    /** @var array<int, int> Child page numbers (1-based from unpack, 0-based when building) */
    public array $children;
    /** @var string[] Separator keys (0-based) */
    public array $keys;

    public static function fromRaw(string $data): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }

        $n = \ord($data[1]) | (\ord($data[2]) << 8);
        $childCount = $n + 1;
        $instance->childCount = $childCount;
        $instance->children = \unpack('P' . $childCount, $data, 3);

        if ($n === 0) {
            $instance->keys = [];
        } else {
            // Read n+1 offsets (last is end marker) in one unpack call
            $offsetsStart = 3 + $childCount * 8;
            $offsets = \unpack('v' . ($n + 1), $data, $offsetsStart);

            // Read keys: keyLen = offsets[i+1] - offsets[i]
            $instance->keys = [];
            for ($i = 1; $i <= $n; $i++) {
                $instance->keys[] = \substr($data, $offsets[$i], $offsets[$i + 1] - $offsets[$i]);
            }
        }

        return $instance;
    }

    /**
     * Get an internal page from pool (or create new) and set children/keys.
     * @param int[] $children 0-based array of child page numbers
     * @param string[] $keys 0-based array of separator keys
     */
    public static function fromArrays(array $children, array $keys): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }
        $instance->children = $children;
        $instance->childCount = \count($children);
        $instance->keys = $keys;
        return $instance;
    }

    /**
     * Serialize to binary page format.
     * Children must be 0-based when building (not 1-based from unpack).
     */
    public function asString(): string
    {
        $n = \count($this->keys);
        $c = $n + 1; // child/offset count

        // Header size: type(1) + count(2) + children((n+1)*8) + offsets((n+1)*2)
        $headerSize = 3 + $c * 8 + $c * 2;

        // Build key data and calculate offsets
        $keyData = \implode('', $this->keys);
        $offsets = [];
        $offset = $headerSize;
        foreach ($this->keys as $key) {
            $offsets[] = $offset;
            $offset += \strlen($key);
        }
        $offsets[] = $offset; // End marker

        // Single pack for header, then append key data
        return \pack('CvP' . $c . 'v' . $c, BTreeIndex::PAGE_INTERNAL, $n, ...$this->children, ...$offsets) . $keyData;
    }

    public function release(): void
    {
        self::$pool[self::$poolCount++] = $this;
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

    /** @var array<string, string> Buffered inserts: key => packed rowIds */
    private array $buffer = [];

    /** @var array<string, array<int, true>> Buffered deletes: key => [rowId => true] */
    private array $deleteBuffer = [];

    /** @var array<int, BTreeLeafPage|BTreeInternalPage> Dirty nodes awaiting write: tempId => node */
    private array $dirtyNodes = [];

    /** @var int Next temporary ID for dirty nodes (negative, decrements) */
    private int $nextTempId = -1;

    // Internal node cache (parsed data)
    // This interval is for multi-process safety; single-process usage doesn't need frequent checks
    private const CACHE_CHECK_INTERVAL = 10000; // Check for file changes every N page reads

    /** @var array<int, BTreeInternalPage> Parsed internal page cache */
    private array $pageCache = [];

    /** @var int Counter for periodic cache invalidation check */
    private int $cacheReadCount = 0;

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
        // Auto-commit pending changes
        if ($this->inTransaction && (!empty($this->buffer) || !empty($this->deleteBuffer))) {
            $this->commit();
        }
        $this->close();
    }

    // =========================================================================
    // Transaction support
    // =========================================================================

    /**
     * Begin a transaction for bulk operations.
     * Changes are buffered in memory until commit().
     */
    public function begin(): void
    {
        if ($this->inTransaction) {
            throw new \RuntimeException("Already in transaction");
        }
        $this->inTransaction = true;
        $this->buffer = [];
        $this->deleteBuffer = [];
    }

    /**
     * Commit buffered changes to disk incrementally.
     * Applies inserts and deletes one by one to avoid loading entire tree.
     */
    public function commit(): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException("Not in transaction");
        }

        if (empty($this->buffer) && empty($this->deleteBuffer)) {
            $this->inTransaction = false;
            return;
        }

        $this->withWriteLock(function() {
            $this->readHeader();

            // Sort buffer by key to optimize disk seek patterns
            ksort($this->buffer, SORT_STRING);

            // Apply inserts incrementally (generates COW pages)
            foreach ($this->buffer as $key => $packed) {
                // Skip if all rowIds for this key were also deleted
                $deletedIds = $this->deleteBuffer[$key] ?? [];

                foreach (unpack('P*', $packed) as $rowId) {
                    if (isset($deletedIds[$rowId])) {
                        continue; // Skip - inserted then deleted in same transaction
                    }

                    if ($this->rootPage === 0) {
                        // Empty tree - create first leaf (sync handled below)
                        $leaf = BTreeLeafPage::fromEntries([[$key, $rowId]]);
                        $this->rootPage = $this->appendPage($leaf->asString());
                        $leaf->release();
                    } else {
                        $path = $this->findPath($key);
                        $this->insertIntoTree($key, $rowId, $path);
                    }
                }
            }

            // Apply deletes incrementally (skip ones that were just inserted)
            foreach ($this->deleteBuffer as $key => $rowIds) {
                if ($this->rootPage === 0) {
                    continue; // Nothing to delete from empty tree
                }

                foreach (array_keys($rowIds) as $rowId) {
                    // Check if this was a newly inserted rowId
                    if (isset($this->buffer[$key])) {
                        $insertedIds = unpack('P*', $this->buffer[$key]);
                        if (in_array($rowId, $insertedIds, true)) {
                            continue; // Already skipped during insert
                        }
                    }

                    $path = $this->findPath($key);
                    $this->deleteFromTree($key, $rowId, $path);
                }
            }

            // Flush all dirty nodes to disk in one pass
            $this->flushDirtyNodes();

            // Ensure all data pages are durable before updating header
            // (flushDirtyNodes syncs dirty nodes, this covers direct appendPage calls)
            fdatasync($this->file);

            $this->sequence++;
            $this->writeHeader();
        });

        $this->buffer = [];
        $this->deleteBuffer = [];
        $this->inTransaction = false;
    }

    /**
     * Commit with bulk rebuild - use for initial loading only.
     * Loads entire tree into memory, merges, and rebuilds optimally.
     */
    public function commitBulk(): void
    {
        if (!$this->inTransaction) {
            throw new \RuntimeException("Not in transaction");
        }

        if (empty($this->buffer) && empty($this->deleteBuffer)) {
            $this->inTransaction = false;
            return;
        }

        $this->withWriteLock(function() {
            $this->readHeader();

            // Merge buffer with existing data (loads entire tree)
            $merged = $this->mergeBufferWithTree();

            // Rebuild tree from merged data
            $this->rebuildTree($merged);

            // Sync all data pages before updating header (crash safety)
            fdatasync($this->file);

            $this->sequence++;
            $this->writeHeader();
        });

        $this->buffer = [];
        $this->deleteBuffer = [];
        $this->inTransaction = false;
    }

    /**
     * Rollback pending changes.
     */
    public function rollback(): void
    {
        $this->buffer = [];
        $this->deleteBuffer = [];
        $this->inTransaction = false;
    }

    // =========================================================================
    // IndexInterface implementation
    // =========================================================================

    public function insert(string $key, int $rowId): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException("Key exceeds maximum length of " . self::MAX_KEY_LENGTH);
        }

        if ($this->inTransaction) {
            // Buffer the insert
            $this->buffer[$key] = ($this->buffer[$key] ?? '') . pack('P', $rowId);
            return;
        }

        $this->withWriteLock(function() use ($key, $rowId) {
            $this->readHeader(); // Re-read header under lock

            if ($this->rootPage === 0) {
                // Empty tree - create first leaf
                $leaf = BTreeLeafPage::fromEntries([[$key, $rowId]]);
                $this->rootPage = $this->appendPage($leaf->asString());
                $leaf->release();
                fdatasync($this->file); // Sync before header update
            } else {
                // Find path to leaf and insert
                $path = $this->findPath($key);
                $this->insertIntoTree($key, $rowId, $path);
                $this->flushDirtyNodes(); // Syncs pages
            }

            $this->sequence++;
            $this->writeHeader(); // Syncs header
        });
    }

    public function delete(string $key, int $rowId): void
    {
        if ($this->inTransaction) {
            // Buffer the delete
            $this->deleteBuffer[$key][$rowId] = true;
            return;
        }

        $this->withWriteLock(function() use ($key, $rowId) {
            $this->readHeader();

            if ($this->rootPage === 0) {
                return; // Empty tree
            }

            $path = $this->findPath($key);
            $this->deleteFromTree($key, $rowId, $path);
            $this->flushDirtyNodes();

            $this->sequence++;
            $this->writeHeader();
        });
    }

    public function eq(string $key): Traversable
    {
        // No header check needed - append-only design means old root always
        // points to valid (immutable) pages. We get snapshot isolation for free.

        // If in transaction with buffered data, use merged range
        if ($this->inTransaction && (!empty($this->buffer) || !empty($this->deleteBuffer))) {
            yield from $this->rangeMerged($key, $key, false);
            return;
        }

        if ($this->rootPage === 0) {
            return;
        }

        // Navigate to leaf containing the key
        $pageNum = $this->rootPage;
        $stack = [];

        while (true) {
            $page = $this->getCachedPage($pageNum);
            if ($page instanceof BTreeLeafPage) {
                $leaf = $page;
                break;
            }
            $childIdx = $this->findChildIndex($page->keys, $key);
            $stack[] = [$pageNum, $childIdx, $page];
            $pageNum = $page->children[$childIdx + 1];
        }

        // Binary search within leaf using getKeyAt() - no rowId unpacking for probes
        while (true) {
            $n = $leaf->count;
            if ($n === 0) {
                $leaf->release();
                return;
            }

            // Binary search for first entry >= key (key-only comparison)
            $lo = 1;
            $hi = $n;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($leaf->getKeyAt($mid), $key) < 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            // Yield matching entries - only unpack rowIds for actual matches
            for ($i = $lo; $i <= $n; $i++) {
                $cmp = strcmp($leaf->getKeyAt($i), $key);
                if ($cmp > 0) {
                    $leaf->release();
                    return; // Past our key
                }
                if ($cmp === 0) {
                    $entry = $leaf->getEntry($i);
                    $rowIdCount = count($entry) - 1;
                    for ($j = 1; $j <= $rowIdCount; $j++) {
                        yield $entry[$j];
                    }
                }
            }

            // Key might continue on next leaf - backtrack to find it
            $leaf->release();
            $found = false;

            while (!empty($stack)) {
                [$parentNum, $childIdx, $parentPage] = array_pop($stack);
                if ($childIdx + 1 < $parentPage->childCount) {
                    $childIdx++;
                    $stack[] = [$parentNum, $childIdx, $parentPage];
                    $pageNum = $parentPage->children[$childIdx + 1];

                    while (true) {
                        $page = $this->getCachedPage($pageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }
                        $stack[] = [$pageNum, 0, $page];
                        $pageNum = $page->children[1];
                    }

                    // Check if first entry matches (duplicates can span pages)
                    if ($leaf->count > 0) {
                        $firstEntry = $leaf->getEntry(1);
                        if ($firstEntry[0] === $key) {
                            $found = true;
                            break;
                        }
                    }
                    $leaf->release();
                    return; // Next leaf starts with different key
                }
            }

            if (!$found) {
                return;
            }
        }
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        // No header check needed - append-only design means old root always
        // points to valid (immutable) pages. We get snapshot isolation for free.

        // If in transaction with buffered data, merge disk + buffer
        if ($this->inTransaction && (!empty($this->buffer) || !empty($this->deleteBuffer))) {
            yield from $this->rangeMerged($start, $end, $reverse);
            return;
        }

        if ($this->rootPage === 0) {
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
            $this->readHeader();

            if ($this->rootPage === 0) {
                return; // Nothing to compact
            }

            $tempPath = $this->path . '.compact.' . getmypid();
            $temp = fopen($tempPath, 'c+b');
            if ($temp === false) {
                throw new \RuntimeException("Failed to create temp file: $tempPath");
            }

            try {
                // Write header placeholder (full page for alignment)
                fwrite($temp, pack('@' . self::PAGE_SIZE));

                // Rewrite all reachable pages
                $pageMap = []; // old page => new page
                $newNextPage = 1;

                $this->rewritePages($temp, $this->rootPage, $pageMap, $newNextPage);

                // Write final header (page 0)
                fseek($temp, 0);
                $newRoot = $pageMap[$this->rootPage] ?? 0;
                $header = pack('VCCvPPP',
                    self::MAGIC,
                    self::VERSION,
                    0, // reserved
                    self::PAGE_SIZE,
                    $newRoot,
                    $newNextPage,
                    $this->sequence + 1
                );
                fwrite($temp, pack('a' . self::PAGE_SIZE, $header));

                // Sync temp file before atomic rename (crash safety)
                fdatasync($temp);
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

        // Check if file is empty or has valid header
        fseek($this->file, 0, SEEK_END);
        $size = ftell($this->file);

        if ($size === 0) {
            // New file - initialize header
            $this->rootPage = 0;
            $this->nextPage = 1;
            $this->sequence = 0;
            $this->writeHeader();
        } else {
            // Existing file - read header
            $this->readHeader();
        }
    }

    private function readHeader(): void
    {
        fseek($this->file, 0);
        $data = fread($this->file, self::HEADER_SIZE);

        if (strlen($data) < self::HEADER_SIZE) {
            throw new \RuntimeException("Corrupted index: header too short");
        }

        $header = unpack('Vmagic/Cversion/Creserved/vpageSize/ProotPage/PnextPage/Psequence', $data);

        if ($header['magic'] !== self::MAGIC) {
            throw new \RuntimeException("Invalid index file: wrong magic number");
        }

        if ($header['version'] !== self::VERSION) {
            throw new \RuntimeException("Unsupported index version: {$header['version']}");
        }

        $this->rootPage = $header['rootPage'];
        $this->nextPage = $header['nextPage'];
        $this->sequence = $header['sequence'];
    }

    private function writeHeader(): void
    {
        // Header occupies entire page 0 (4KB aligned for disk I/O)
        $header = pack('VCCvPPP',
            self::MAGIC,
            self::VERSION,
            0, // reserved
            self::PAGE_SIZE,
            $this->rootPage,
            $this->nextPage,
            $this->sequence
        );

        fseek($this->file, 0);
        fwrite($this->file, pack('a' . self::PAGE_SIZE, $header));

        // Sync header to disk (crash safety - completes the atomic commit)
        fdatasync($this->file);

        // Clear stale cached pages after header update
        $this->pageCache = [];
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
     * Get parsed leaf page with caching.
     */
    private function getLeafPage(int $pageNum): BTreeLeafPage
    {
        $page = $this->getCachedPage($pageNum);
        if (!$page instanceof BTreeLeafPage) {
            throw new \RuntimeException("Page $pageNum is not a leaf");
        }
        return $page;
    }

    /**
     * Get parsed internal page with caching.
     */
    private function getInternalPage(int $pageNum): BTreeInternalPage
    {
        $page = $this->getCachedPage($pageNum);
        if (!$page instanceof BTreeInternalPage) {
            throw new \RuntimeException("Page $pageNum is not internal");
        }
        return $page;
    }

    /**
     * Get parsed page, caching internal nodes only.
     */
    private function getCachedPage(int $pageNum): BTreeLeafPage|BTreeInternalPage
    {
        // Negative page numbers are dirty (unwritten) nodes
        if ($pageNum < 0) {
            return $this->dirtyNodes[$pageNum];
        }

        // Periodic check for file modifications by other processes
        if (++$this->cacheReadCount >= self::CACHE_CHECK_INTERVAL) {
            $this->cacheReadCount = 0;
            $this->checkCacheValidity();
        }

        // Check cache (internal nodes only)
        if (isset($this->pageCache[$pageNum])) {
            return $this->pageCache[$pageNum];
        }

        // Read and parse
        $page = $this->readPageRaw($pageNum);
        $type = ord($page[0]);

        if ($type === self::PAGE_LEAF) {
            // Don't cache leaf nodes - they're accessed once per query
            return BTreeLeafPage::fromRaw($page);
        }

        // Cache internal nodes - they're traversed repeatedly
        $parsed = BTreeInternalPage::fromRaw($page);
        $this->pageCache[$pageNum] = $parsed;
        return $parsed;
    }

    /**
     * Check if file was modified by another process and invalidate cache if so.
     * Append-only design means we just check if file grew.
     */
    private function checkCacheValidity(): void
    {
        clearstatcache(true, $this->path);
        $size = filesize($this->path);
        $expectedSize = $this->nextPage * self::PAGE_SIZE;

        if ($size > $expectedSize) {
            // File grew - another process appended pages. Re-read header.
            $this->pageCache = [];
            $this->readHeader();
        }
    }

    private function appendPage(string $page): int
    {
        $pageNum = $this->nextPage++;
        // Page 0 is the header page, data pages start at 1
        $offset = $pageNum * self::PAGE_SIZE;

        fseek($this->file, $offset);
        fwrite($this->file, \pack('a' . self::PAGE_SIZE, $page));
        fflush($this->file);

        return $pageNum;
    }

    /**
     * Allocate a dirty (unwritten) page. Returns a negative temp ID.
     * The node will be written to disk when flushDirtyNodes() is called.
     */
    private function allocateDirtyPage(BTreeLeafPage|BTreeInternalPage $node): int
    {
        $tempId = $this->nextTempId--;
        $this->dirtyNodes[$tempId] = $node;
        return $tempId;
    }

    /**
     * Recursively write dirty nodes to disk, children before parents.
     * Returns the real page number for the given page reference.
     */
    private function writeDirtySubtree(int $pageRef): int
    {
        if ($pageRef >= 0) {
            return $pageRef;  // Already on disk
        }

        $node = $this->dirtyNodes[$pageRef];

        if ($node instanceof BTreeInternalPage) {
            // Write children first, update pointers to real page numbers
            $newChildren = [];
            foreach ($node->children as $child) {
                $newChildren[] = $this->writeDirtySubtree($child);
            }
            $node->children = $newChildren;
        }

        // Write this node and return real page number
        $realPage = $this->appendPage($node->asString());
        $node->release();
        return $realPage;
    }

    /**
     * Flush all dirty nodes to disk in correct order.
     * Must be called within write lock.
     */
    private function flushDirtyNodes(): void
    {
        if (empty($this->dirtyNodes)) {
            return;
        }

        // Write from root - recursion handles children-first ordering
        if ($this->rootPage < 0) {
            $this->rootPage = $this->writeDirtySubtree($this->rootPage);
        }

        // Sync data pages to disk before updating header (crash safety)
        fdatasync($this->file);

        $this->dirtyNodes = [];
        $this->nextTempId = -1;
    }

    // =========================================================================
    // Internal page format
    // =========================================================================

    /**
     * Find child index for given key in internal node.
     * Returns the index into $children array (not the page number).
     */
    private function findChildIndex(array $keys, string $key): int
    {
        // Binary search for first key >= $key
        $lo = 0;
        $hi = count($keys);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($keys[$mid], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    // =========================================================================
    // Tree traversal
    // =========================================================================

    /**
     * Find path from root to leaf for given key.
     * @return array<array{int, int}> [[pageNum, childIndex], ...] where leaf has childIndex=-1
     */
    private function findPath(string $key): array
    {
        $path = [];
        $pageNum = $this->rootPage;

        while (true) {
            $page = $this->getCachedPage($pageNum);

            if ($page instanceof BTreeLeafPage) {
                $path[] = [$pageNum, -1];
                return $path;
            }

            // Find child index via binary search
            $lo = 0;
            $hi = count($page->keys);
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($page->keys[$mid], $key) <= 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $path[] = [$pageNum, $lo];
            $pageNum = $page->children[$lo];
        }
    }

    // =========================================================================
    // Insert
    // =========================================================================

    private function insertIntoTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path
        [$leafPageNum, $_] = array_pop($path);
        $entries = $this->getLeafPage($leafPageNum)->toArray();

        // Find position and insert/update
        $pos = $this->findInsertPosition($entries, $key);

        if ($pos < count($entries) && $entries[$pos][0] === $key) {
            // Key exists - append rowId (flat format: [key, id1, id2, ...])
            $entries[$pos][] = $rowId;
        } else {
            // New key - insert at position (flat format: [key, rowId])
            array_splice($entries, $pos, 0, [[$key, $rowId]]);
        }

        // Check if leaf needs splitting
        $leaf = BTreeLeafPage::fromEntries($entries);

        if (strlen($leaf->asString()) <= self::PAGE_SIZE) {
            // Fits - allocate dirty page and update parent
            $newLeafNum = $this->allocateDirtyPage($leaf);
            $this->updatePath($path, $leafPageNum, $newLeafNum, null, null);
        } else {
            // Split required
            $leaf->release();
            $mid = count($entries) >> 1;
            $leftEntries = array_slice($entries, 0, $mid);
            $rightEntries = array_slice($entries, $mid);

            $leftLeaf = BTreeLeafPage::fromEntries($leftEntries);
            $rightLeaf = BTreeLeafPage::fromEntries($rightEntries);

            $leftNum = $this->allocateDirtyPage($leftLeaf);
            $rightNum = $this->allocateDirtyPage($rightLeaf);

            // Promote first key of right leaf
            $promoteKey = $rightEntries[0][0];

            $this->propagateSplit($path, $leafPageNum, $leftNum, $rightNum, $promoteKey);
        }
    }

    private function findInsertPosition(array $entries, string $key): int
    {
        $lo = 0;
        $hi = count($entries);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($entries[$mid][0], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    private function propagateSplit(array $path, int $_oldChild, int $leftChild, int $rightChild, string $promoteKey): void
    {
        if (empty($path)) {
            // Create new root (as dirty page)
            $root = BTreeInternalPage::fromArrays([$leftChild, $rightChild], [$promoteKey]);
            $this->rootPage = $this->allocateDirtyPage($root);
            return;
        }

        // Pop parent from path
        [$parentPageNum, $childIndex] = array_pop($path);
        $parent = $this->getInternalPage($parentPageNum);
        $children = \array_values($parent->children);
        $keys = $parent->keys;

        // Replace old child with left, insert right after
        $children[$childIndex] = $leftChild;
        array_splice($children, $childIndex + 1, 0, [$rightChild]);
        array_splice($keys, $childIndex, 0, [$promoteKey]);

        // Check if internal node needs splitting
        $newParent = BTreeInternalPage::fromArrays($children, $keys);

        if (strlen($newParent->asString()) <= self::PAGE_SIZE) {
            // Fits - allocate dirty page
            $newParentNum = $this->allocateDirtyPage($newParent);
            $this->updatePath($path, $parentPageNum, $newParentNum, null, null);
        } else {
            // Split internal node
            $newParent->release();
            $mid = count($keys) >> 1;
            $leftKeys = array_slice($keys, 0, $mid);
            $rightKeys = array_slice($keys, $mid + 1);
            $leftChildren = array_slice($children, 0, $mid + 1);
            $rightChildren = array_slice($children, $mid + 1);
            $splitPromoteKey = $keys[$mid];

            $leftPage = BTreeInternalPage::fromArrays($leftChildren, $leftKeys);
            $rightPage = BTreeInternalPage::fromArrays($rightChildren, $rightKeys);

            $leftNum = $this->allocateDirtyPage($leftPage);
            $rightNum = $this->allocateDirtyPage($rightPage);

            $this->propagateSplit($path, $parentPageNum, $leftNum, $rightNum, $splitPromoteKey);
        }
    }

    private function updatePath(array $path, int $_oldChild, int $newChild, ?int $extraChild, ?string $extraKey): void
    {
        if (empty($path)) {
            // We've reached the root level
            if ($extraChild === null) {
                $this->rootPage = $newChild;
            } else {
                // This shouldn't happen - splits are handled in propagateSplit
                $root = BTreeInternalPage::fromArrays([$newChild, $extraChild], [$extraKey]);
                $this->rootPage = $this->allocateDirtyPage($root);
            }
            return;
        }

        // Pop parent
        [$parentPageNum, $childIndex] = array_pop($path);
        $parent = $this->getInternalPage($parentPageNum);
        $children = \array_values($parent->children);
        $keys = $parent->keys;

        // Replace child pointer
        $children[$childIndex] = $newChild;

        if ($extraChild !== null) {
            array_splice($children, $childIndex + 1, 0, [$extraChild]);
            array_splice($keys, $childIndex, 0, [$extraKey]);
        }

        $newParent = BTreeInternalPage::fromArrays($children, $keys);
        $newParentNum = $this->allocateDirtyPage($newParent);

        $this->updatePath($path, $parentPageNum, $newParentNum, null, null);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    private function deleteFromTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path
        [$leafPageNum, $_] = array_pop($path);
        $entries = $this->getLeafPage($leafPageNum)->toArray();

        // Find key and remove rowId (flat format: [key, id1, id2, ...])
        $found = false;
        foreach ($entries as $i => $entry) {
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
                            // No more rowIds - remove entire entry
                            array_splice($entries, $i, 1);
                        } else {
                            $entries[$i] = $newEntry;
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

        // Create new leaf (even if empty - lazy cleanup via compact)
        $leaf = BTreeLeafPage::fromEntries($entries);
        $newLeafNum = $this->allocateDirtyPage($leaf);

        $this->updatePath($path, $leafPageNum, $newLeafNum, null, null);
    }

    // =========================================================================
    // Transaction support helpers
    // =========================================================================

    /**
     * Merge buffer with existing tree data.
     * @return array<string, int[]> Merged key => rowIds
     */
    private function mergeBufferWithTree(): array
    {
        $merged = [];

        // First, collect all data from disk
        if ($this->rootPage !== 0) {
            $this->collectAllEntries($this->rootPage, $merged);
        }

        // Apply deletes
        foreach ($this->deleteBuffer as $key => $rowIds) {
            if (isset($merged[$key])) {
                $merged[$key] = array_values(array_diff($merged[$key], array_keys($rowIds)));
                if (empty($merged[$key])) {
                    unset($merged[$key]);
                }
            }
        }

        // Apply inserts
        foreach ($this->buffer as $key => $packed) {
            $rowIds = array_values(unpack('P*', $packed));
            // Remove any that were also deleted in same transaction
            if (isset($this->deleteBuffer[$key])) {
                $rowIds = array_values(array_diff($rowIds, array_keys($this->deleteBuffer[$key])));
            }
            if (!empty($rowIds)) {
                $merged[$key] = array_merge($merged[$key] ?? [], $rowIds);
            }
        }

        return $merged;
    }

    /**
     * Recursively collect all entries from the tree.
     */
    private function collectAllEntries(int $pageNum, array &$merged): void
    {
        $page = $this->getCachedPage($pageNum);

        if ($page instanceof BTreeLeafPage) {
            // Flat format: [0 => key, 1 => id1, 2 => id2, ...]
            $page->buildEntries();
            $entries = $page->entries;
            $entryCount = $page->count;

            for ($e = 0; $e < $entryCount; $e++) {
                $entry = $entries[$e];
                $key = $entry[0];
                $rowIdCount = count($entry) - 1;
                if (!isset($merged[$key])) {
                    $merged[$key] = [];
                }
                for ($i = 1; $i <= $rowIdCount; $i++) {
                    $merged[$key][] = $entry[$i];
                }
            }
            return;
        }

        // Internal node - recurse into children
        foreach ($page->children as $child) {
            $this->collectAllEntries($child, $merged);
        }
    }

    /**
     * Rebuild tree from merged data using bulk loading.
     * @param array<string, int[]> $data key => rowIds
     */
    private function rebuildTree(array $data): void
    {
        if (empty($data)) {
            $this->rootPage = 0;
            return;
        }

        // Sort keys
        ksort($data, SORT_STRING);

        // Convert to flat entries format: [key, rowId1, rowId2, ...]
        $entries = [];
        foreach ($data as $key => $rowIds) {
            $entries[] = [$key, ...$rowIds];
        }

        // Build tree bottom-up for optimal structure
        $this->rootPage = $this->bulkBuildTree($entries);
    }

    /**
     * Build balanced B-tree from sorted entries using bulk loading.
     * @param array<array<int, string|int>> $entries Sorted flat format [[key, rowId1, ...], ...]
     * @return int Root page number
     */
    private function bulkBuildTree(array $entries): int
    {
        // First, split any entries that have too many rowIds to fit in a page
        // Max rowIds per entry: (PAGE_SIZE - header - key overhead) / 8 bytes per rowId
        // Conservative: ~400 rowIds per entry to leave room for key and page overhead
        $maxRowIdsPerEntry = 400;
        $splitEntries = [];

        foreach ($entries as $entry) {
            $key = $entry[0];
            $rowIdCount = count($entry) - 1;
            if ($rowIdCount <= $maxRowIdsPerEntry) {
                $splitEntries[] = $entry;
            } else {
                // Split into multiple entries with same key
                for ($i = 1; $i <= $rowIdCount; $i += $maxRowIdsPerEntry) {
                    $chunk = [$key];
                    for ($j = $i; $j < $i + $maxRowIdsPerEntry && $j <= $rowIdCount; $j++) {
                        $chunk[] = $entry[$j];
                    }
                    $splitEntries[] = $chunk;
                }
            }
        }
        $entries = $splitEntries;

        // Calculate max entries per leaf (approximate)
        // Leave some headroom for variable-length keys
        $maxPerLeaf = 50; // Conservative estimate

        // Create leaf pages
        $leafPages = [];
        $leafKeys = []; // First key of each leaf (for building internal nodes)

        for ($i = 0; $i < count($entries); $i += $maxPerLeaf) {
            $chunk = array_slice($entries, $i, $maxPerLeaf);

            // Check if chunk fits in a page, split if needed
            $leaf = BTreeLeafPage::fromEntries($chunk);
            $page = $leaf->asString();
            while (strlen($page) > self::PAGE_SIZE && count($chunk) > 1) {
                $leaf->release();
                $chunk = array_slice($chunk, 0, (int)(count($chunk) * 0.8));
                $leaf = BTreeLeafPage::fromEntries($chunk);
                $page = $leaf->asString();
            }

            $pageNum = $this->appendPage($page);
            $leaf->release();
            $leafPages[] = $pageNum;
            $leafKeys[] = $chunk[0][0];

            // Adjust i to account for actual chunk size
            $i = $i + count($chunk) - $maxPerLeaf;
        }

        if (count($leafPages) === 1) {
            return $leafPages[0];
        }

        // Build internal nodes bottom-up
        return $this->buildInternalLevels($leafPages, $leafKeys);
    }

    /**
     * Build internal node levels from child pages.
     * @param int[] $children Child page numbers
     * @param string[] $keys First key of each child (same length as $children)
     */
    private function buildInternalLevels(array $children, array $keys): int
    {
        while (count($children) > 1) {
            $newChildren = [];
            $newKeys = [];

            // Max keys per internal node (approximate)
            $maxPerNode = 100;

            for ($i = 0; $i < count($children);) {
                $nodeFirstKey = $keys[$i]; // First key of this subtree
                $nodeChildren = [$children[$i]];
                $nodeKeys = [];
                $i++;

                while ($i < count($children) && count($nodeKeys) < $maxPerNode) {
                    // Separator key is the first key of the next child
                    $nodeKeys[] = $keys[$i];
                    $nodeChildren[] = $children[$i];
                    $i++;

                    // Check if page fits
                    $node = BTreeInternalPage::fromArrays($nodeChildren, $nodeKeys);
                    $pageStr = $node->asString();
                    if (strlen($pageStr) > self::PAGE_SIZE) {
                        // Back up one
                        $node->release();
                        array_pop($nodeChildren);
                        array_pop($nodeKeys);
                        $i--;
                        break;
                    }
                    $node->release();
                }

                $node = BTreeInternalPage::fromArrays($nodeChildren, $nodeKeys);
                $pageNum = $this->appendPage($node->asString());
                $node->release();
                $newChildren[] = $pageNum;
                $newKeys[] = $nodeFirstKey; // Propagate first key of this subtree
            }

            $children = $newChildren;
            $keys = $newKeys;
        }

        return $children[0];
    }

    /**
     * Range query merging disk and buffer data with streaming merge.
     * Does not load entire result set into memory.
     */
    private function rangeMerged(?string $start, ?string $end, bool $reverse): \Generator
    {
        // Create disk iterator with delete filtering
        $diskIter = $this->diskRangeIterator($start, $end, $reverse);

        // Create buffer iterator (sorted)
        $bufferIter = $this->bufferRangeIterator($start, $end, $reverse);

        // Streaming merge of two sorted iterators
        yield from $this->mergeIterators($diskIter, $bufferIter, $reverse);
    }

    /**
     * Iterator over disk entries with delete filtering.
     */
    private function diskRangeIterator(?string $start, ?string $end, bool $reverse): \Generator
    {
        if ($this->rootPage === 0) {
            return;
        }

        $source = $reverse
            ? $this->rangeReverseWithKeys($start, $end)
            : $this->rangeForwardWithKeys($start, $end);

        foreach ($source as [$key, $id]) {
            // Filter deletes from buffer
            if (!isset($this->deleteBuffer[$key][$id])) {
                yield [$key, $id];
            }
        }
    }

    /**
     * Iterator over buffer entries in sorted order.
     */
    private function bufferRangeIterator(?string $start, ?string $end, bool $reverse): \Generator
    {
        // Get keys in range, sorted
        $keys = array_keys($this->buffer);
        sort($keys, SORT_STRING);
        if ($reverse) {
            $keys = array_reverse($keys);
        }

        foreach ($keys as $key) {
            if ($start !== null && strcmp($key, $start) < 0) continue;
            if ($end !== null && strcmp($key, $end) > 0) continue;

            $rowIds = array_values(unpack('P*', $this->buffer[$key]));
            $deletedIds = $this->deleteBuffer[$key] ?? [];

            if ($reverse) {
                $rowIds = array_reverse($rowIds);
            }

            foreach ($rowIds as $id) {
                if (!isset($deletedIds[$id])) {
                    yield [$key, $id];
                }
            }
        }
    }

    /**
     * Merge two sorted [key, id] iterators maintaining sort order.
     */
    private function mergeIterators(\Generator $a, \Generator $b, bool $reverse): \Generator
    {
        $aValid = $a->valid();
        $bValid = $b->valid();

        while ($aValid && $bValid) {
            $aVal = $a->current();
            $bVal = $b->current();

            // Compare [key, id] tuples
            $cmp = strcmp($aVal[0], $bVal[0]);
            if ($cmp === 0) {
                $cmp = $aVal[1] <=> $bVal[1];
            }
            if ($reverse) {
                $cmp = -$cmp;
            }

            if ($cmp <= 0) {
                yield $aVal[1];
                $a->next();
                $aValid = $a->valid();
            } else {
                yield $bVal[1];
                $b->next();
                $bValid = $b->valid();
            }
        }

        // Drain remaining from either iterator
        while ($aValid) {
            yield $a->current()[1];
            $a->next();
            $aValid = $a->valid();
        }

        while ($bValid) {
            yield $b->current()[1];
            $b->next();
            $bValid = $b->valid();
        }
    }

    /**
     * Range forward yielding [key, rowId] pairs for internal use.
     */
    private function rangeForwardWithKeys(?string $start, ?string $end): \Generator
    {
        $startKey = $start ?? '';
        $pageNum = $this->rootPage;
        $stack = [];

        while (true) {
            $page = $this->getCachedPage($pageNum);
            if ($page instanceof BTreeLeafPage) {
                $leaf = $page;
                break;
            }

            $childIdx = $this->findChildIndex($page->keys, $startKey);
            $stack[] = [$pageNum, $childIdx, $page];
            $pageNum = $page->children[$childIdx + 1];
        }

        while (true) {
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
                    $leaf->release();
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($i = 1; $i <= $rowIdCount; $i++) {
                    yield [$key, $entry[$i]];
                }
            }

            $leaf->release();

            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $parentPage] = array_pop($stack);

                if ($childIdx + 1 < $parentPage->childCount) {
                    $childIdx++;
                    $stack[] = [$parentNum, $childIdx, $parentPage];
                    $pageNum = $parentPage->children[$childIdx + 1];

                    while (true) {
                        $page = $this->getCachedPage($pageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $stack[] = [$pageNum, 0, $page];
                        $pageNum = $page->children[1];
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

    /**
     * Range reverse yielding [key, rowId] pairs for internal use.
     */
    private function rangeReverseWithKeys(?string $start, ?string $end): \Generator
    {
        $pageNum = $this->rootPage;
        $stack = [];

        // Navigate to last relevant leaf
        while (true) {
            $page = $this->getCachedPage($pageNum);
            if ($page instanceof BTreeLeafPage) {
                $leaf = $page;
                break;
            }

            // Find rightmost child that could contain keys <= end
            $childIdx = $page->childCount - 1;
            if ($end !== null) {
                for ($i = count($page->keys) - 1; $i >= 0; $i--) {
                    if (strcmp($page->keys[$i], $end) <= 0) {
                        $childIdx = $i + 1;
                        break;
                    }
                    $childIdx = $i;
                }
            }

            $stack[] = [$pageNum, $childIdx, $page];
            $pageNum = $page->children[$childIdx + 1];
        }

        while (true) {
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
                    $leaf->release();
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($j = $rowIdCount; $j >= 1; $j--) {
                    yield [$key, $entry[$j]];
                }
            }

            $leaf->release();

            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $parentPage] = array_pop($stack);

                if ($childIdx > 0) {
                    $childIdx--;
                    $stack[] = [$parentNum, $childIdx, $parentPage];
                    $pageNum = $parentPage->children[$childIdx + 1];

                    while (true) {
                        $page = $this->getCachedPage($pageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $lastChild = $page->childCount - 1;
                        $stack[] = [$pageNum, $lastChild, $page];
                        $pageNum = $page->children[$lastChild + 1];
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
    // Range queries
    // =========================================================================

    private function rangeForward(?string $start, ?string $end): \Generator
    {
        $startKey = $start ?? "\x00";
        $pageNum = $this->rootPage;
        $stack = [];

        // Navigate to first relevant leaf
        while (true) {
            $page = $this->getCachedPage($pageNum);
            if ($page instanceof BTreeLeafPage) {
                $leaf = $page;
                break;
            }

            $childIdx = $this->findChildIndex($page->keys, $startKey);
            $stack[] = [$pageNum, $childIdx, $page];
            $pageNum = $page->children[$childIdx + 1];
        }

        // Iterate through leaves (flat format: [key, id1, id2, ...])
        while (true) {
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
                    $leaf->release();
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($i = 1; $i <= $rowIdCount; $i++) {
                    yield $entry[$i];
                }
            }

            // Release current leaf before getting next
            $leaf->release();

            // Find next leaf via backtracking
            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $parentPage] = array_pop($stack);

                if ($childIdx + 1 < $parentPage->childCount) {
                    $childIdx++;
                    $stack[] = [$parentNum, $childIdx, $parentPage];
                    $pageNum = $parentPage->children[$childIdx + 1];

                    // Navigate down to leftmost leaf
                    while (true) {
                        $page = $this->getCachedPage($pageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $stack[] = [$pageNum, 0, $page];
                        $pageNum = $page->children[1];
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
        $pageNum = $this->rootPage;
        $stack = [];

        // Navigate to last relevant leaf
        while (true) {
            $page = $this->getCachedPage($pageNum);
            if ($page instanceof BTreeLeafPage) {
                $leaf = $page;
                break;
            }

            // Find rightmost child that could contain keys <= end
            $childIdx = $page->childCount - 1;
            if ($end !== null) {
                for ($i = count($page->keys) - 1; $i >= 0; $i--) {
                    if (strcmp($page->keys[$i], $end) <= 0) {
                        $childIdx = $i + 1;
                        break;
                    }
                    $childIdx = $i;
                }
            }

            $stack[] = [$pageNum, $childIdx, $page];
            $pageNum = $page->children[$childIdx + 1];
        }

        // Iterate through leaves in reverse (flat format: [key, id1, id2, ...])
        while (true) {
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
                    $leaf->release();
                    return;
                }

                $rowIdCount = count($entry) - 1;
                for ($j = $rowIdCount; $j >= 1; $j--) {
                    yield $entry[$j];
                }
            }

            // Release current leaf before getting next
            $leaf->release();

            // Find previous leaf via backtracking
            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $parentPage] = array_pop($stack);

                if ($childIdx > 0) {
                    $childIdx--;
                    $stack[] = [$parentNum, $childIdx, $parentPage];
                    $pageNum = $parentPage->children[$childIdx + 1];

                    // Navigate down to rightmost leaf
                    while (true) {
                        $page = $this->getCachedPage($pageNum);
                        if ($page instanceof BTreeLeafPage) {
                            $leaf = $page;
                            break;
                        }

                        $lastChild = $page->childCount - 1;
                        $stack[] = [$pageNum, $lastChild, $page];
                        $pageNum = $page->children[$lastChild + 1];
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

        $page = $this->getCachedPage($pageNum);

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

        // Internal node - recursively rewrite children first
        $newChildren = [];
        foreach ($page->children as $child) {
            $newChildren[] = $this->rewritePages($temp, $child, $pageMap, $newNextPage);
        }

        // Create new internal page with updated children
        $newNode = BTreeInternalPage::fromArrays($newChildren, $page->keys);

        $newPageNum = $newNextPage++;
        $pageMap[$pageNum] = $newPageNum;

        $offset = $newPageNum * self::PAGE_SIZE;
        fseek($temp, $offset);
        fwrite($temp, pack('a' . self::PAGE_SIZE, $newNode->asString()));
        $newNode->release();

        return $newPageNum;
    }
}
