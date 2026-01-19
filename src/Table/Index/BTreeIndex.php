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
    /** @var int[] Header metadata: offsets (0..n) + rowIdCounts (n+1..2n), 0-based */
    public array $meta = [];
    /** @var array<array<int, string|int>> Cached entries for scans, 0-based */
    public array $entries = [];
    /** Whether entries array is valid for current page data */
    public bool $entriesBuilt = false;

    public static function fromRaw(string $data): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }
        $instance->data = $data;
        $n = \ord($data[1]) | (\ord($data[2]) << 8);
        $instance->count = $n;
        // Single unpack: n+1 offsets + n rowIdCounts = 2n+1 uint16 values
        // unpack returns 1-based, array_values converts to 0-based
        $instance->meta = $n > 0 ? \array_values(\unpack('v' . (2 * $n + 1), $data, 3)) : [];
        $instance->entriesBuilt = false;
        return $instance;
    }

    /**
     * Build and cache all entries for efficient scan iteration.
     * Entries are 0-based: entries[0], entries[1], ...
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
            $pos = $meta[$i];
            $rowIdCount = $meta[$n + 1 + $i];
            $keyLen = $meta[$i + 1] - $pos - ($rowIdCount << 3);
            $this->entries[$i] = [\substr($data, $pos + ($rowIdCount << 3), $keyLen), ...\unpack('P' . $rowIdCount, $data, $pos)];
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

        $n = $this->count;
        $pos = $this->meta[$i];
        $rowIdCount = $this->meta[$n + 1 + $i];
        $keyLen = $this->meta[$i + 1] - $pos - ($rowIdCount << 3);
        return \substr($this->data, $pos + ($rowIdCount << 3), $keyLen);
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
     * Entries and meta are 0-based.
     */
    public function asString(): string
    {
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
     */
    public function rebuildMeta(): void
    {
        $n = $this->count;
        if ($n === 0) {
            return;
        }

        // Build meta: offsets (0..n) + rowIdCounts (n+1..2n), 0-based
        // Header size: type(1) + count(2) + offsets((n+1) * 2) + rowIdCounts(n * 2)
        $headerSize = 3 + ($n + 1) * 2 + $n * 2;
        $offset = $headerSize;

        for ($i = 0; $i < $n; $i++) {
            $entry = $this->entries[$i];
            $this->meta[$i] = $offset;
            $rowIdCount = \count($entry) - 1;
            $this->meta[$n + 1 + $i] = $rowIdCount;
            $offset += $rowIdCount * 8 + \strlen($entry[0]);
        }
        $this->meta[$n] = $offset; // end marker
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
    public int $childCount;
    /** @var int[] Child page numbers - 0-based: children[0..n-1] */
    public array $children = [];
    /** @var string[] Separator keys - 0-based: keys[0..n-2] (one less than children) */
    public array $keys = [];

    public static function fromRaw(string $data): self
    {
        $instance = new self();

        $n = \ord($data[1]) | (\ord($data[2]) << 8);
        $childCount = $n + 1;
        $instance->childCount = $childCount;

        // unpack returns 1-based, array_values converts to 0-based
        $instance->children = \array_values(\unpack('V' . $childCount, $data, 3));

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
        $instance->childCount = \count($children);
        $instance->children = $children;
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
        $this->childCount = \count($children);
        $this->children = $children;
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
        ];
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
     * Traverses tree and splits leaves that exceed PAGE_SIZE.
     */
    private function splitOversizedPages(): void
    {
        // Keep splitting until no more oversized pages
        while ($this->splitOversizedPagesOnce()) {
            // Loop continues until no splits needed
        }
    }

    /**
     * Single pass to find and split one oversized page.
     * @return bool True if a split was performed
     */
    private function splitOversizedPagesOnce(): bool
    {
        if ($this->currentRoot === null) {
            return false;
        }

        // Check root first
        if ($this->currentRoot instanceof BTreeLeafPage) {
            if ($this->currentRoot->calculateSize() > self::PAGE_SIZE) {
                $this->splitLeafAtPath([null, -1]);
                return true;
            }
            return false;
        }

        // Traverse tree looking for oversized leaves
        return $this->findAndSplitOversized($this->currentRoot, [null]);
    }

    /**
     * Recursively search for oversized leaves and split the first one found.
     * Only checks pages in unwrittenPages - disk pages are already sized correctly.
     * @param BTreeInternalPage $node Current internal node
     * @param array $pathSoFar Path from root to this node
     * @return bool True if a split was performed
     */
    private function findAndSplitOversized(BTreeInternalPage $node, array $pathSoFar): bool
    {
        for ($i = 0; $i < $node->childCount; $i++) {
            $childNum = $node->children[$i];

            // Only check pages in the overlay - disk pages are already correct size
            if (!isset($this->unwrittenPages[$childNum])) {
                continue;
            }

            $child = $this->unwrittenPages[$childNum];

            // Extend path with this child (0-based childIdx)
            $childPath = $pathSoFar;
            $childPath[] = $i; // childIdx (0-based)
            $childPath[] = $childNum; // pageNum

            if ($child instanceof BTreeLeafPage) {
                // Check if oversized
                if ($child->calculateSize() > self::PAGE_SIZE) {
                    $childPath[] = -1;
                    $this->splitLeafAtPath($childPath);
                    return true;
                }
            } else {
                // Recurse into internal node
                if ($this->findAndSplitOversized($child, $childPath)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Split a leaf at the given path.
     * @param array $path Path to leaf: [pageNum, childIdx, pageNum, childIdx, ..., leafPageNum, -1]
     */
    private function splitLeafAtPath(array $path): void
    {
        $pathLen = \count($path);
        $leafPageNum = $path[$pathLen - 2];

        // Get leaf
        if ($leafPageNum === null) {
            $leaf = $this->currentRoot;
        } else {
            $leaf = $this->getPageForWrite($leafPageNum);
        }

        $leaf->buildEntries();
        $n = $leaf->count;

        // Check if we need to split rowIds within a single entry
        if ($n === 1) {
            $entry = $leaf->entries[0];
            $entryKey = $entry[0];
            $rowIdCount = \count($entry) - 1;
            $mid = ($rowIdCount >> 1) + 1;

            $leftEntry = [$entryKey];
            $rightEntry = [$entryKey];
            for ($i = 1; $i <= $rowIdCount; $i++) {
                if ($i < $mid) {
                    $leftEntry[] = $entry[$i];
                } else {
                    $rightEntry[] = $entry[$i];
                }
            }

            // Update left page with single entry
            $leaf->entries[0] = $leftEntry;
            $leaf->count = 1;
            $leaf->rebuildMeta();
            $leftNum = $leafPageNum ?? $this->allocatePage($leaf);

            // Create right page with single entry
            $rightLeaf = BTreeLeafPage::fromPool();
            $rightLeaf->entries[0] = $rightEntry;
            $rightLeaf->count = 1;
            $rightLeaf->entriesBuilt = true;
            $rightLeaf->rebuildMeta();
            $rightNum = $this->allocatePage($rightLeaf);

            $promoteKey = $entryKey;
        } else {
            $mid = $n >> 1;

            // Create right page - copy entries directly
            $rightLeaf = BTreeLeafPage::fromPool();
            $rightCount = $n - $mid;
            for ($i = 0; $i < $rightCount; $i++) {
                $rightLeaf->entries[$i] = $leaf->entries[$mid + $i];
            }
            $rightLeaf->count = $rightCount;
            $rightLeaf->entriesBuilt = true;
            $rightLeaf->rebuildMeta();
            $rightNum = $this->allocatePage($rightLeaf);

            $promoteKey = $leaf->entries[$mid][0];

            // Update left page - just truncate
            $leaf->count = $mid;
            $leaf->rebuildMeta();
            $leftNum = $leafPageNum ?? $this->allocatePage($leaf);
        }

        $this->propagateSplit($path, $pathLen - 4, $leftNum, $rightNum, $promoteKey);
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

        // Binary search within leaf using getKeyAt() - no rowId unpacking for probes
        while (true) {
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

            // Yield matching entries - only unpack rowIds for actual matches (0-based)
            for ($i = $lo; $i < $n; $i++) {
                $cmp = strcmp($leaf->getKeyAt($i), $key);
                if ($cmp > 0) {
                    if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
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
            if ($leafPageNum !== null) $this->releaseLeaf($leaf, $leafPageNum);
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

                    // Check if first entry matches (duplicates can span pages)
                    if ($leaf->count > 0) {
                        $firstEntry = $leaf->getEntry(0);
                        if ($firstEntry[0] === $key) {
                            $found = true;
                            break;
                        }
                    }
                    $this->releaseLeaf($leaf, $leafPageNum);
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
        }
        $leaf->buildEntries();
        $n = $leaf->count;

        // Find position (0-based) via binary search
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
        $pos = $lo;

        if ($pos < $n && $leaf->entries[$pos][0] === $key) {
            // Key exists - append rowId in place
            $leaf->entries[$pos][] = $rowId;
            $leaf->rebuildMeta();
        } else {
            // New key - shift entries and insert
            // Shift from end to make room at $pos
            for ($i = $n; $i > $pos; $i--) {
                $leaf->entries[$i] = $leaf->entries[$i - 1];
            }
            $leaf->entries[$pos] = [$key, $rowId];
            $leaf->count = ++$n;
            $leaf->rebuildMeta();
        }

        // Check if page fits - if so, just update parent pointers
        if ($leaf->calculateSize() <= self::PAGE_SIZE) {
            if ($leafPageNum !== null) {
                $this->updatePath($path, $pathLen - 4, $leafPageNum);
            }
            return;
        }

        // Split required
        if ($n === 1) {
            // Single entry with too many rowIds - split the rowIds
            $entry = $leaf->entries[0];
            $entryKey = $entry[0];
            $rowIdCount = \count($entry) - 1;
            $mid = ($rowIdCount >> 1) + 1;

            $leftEntry = [$entryKey];
            $rightEntry = [$entryKey];
            for ($i = 1; $i <= $rowIdCount; $i++) {
                if ($i < $mid) {
                    $leftEntry[] = $entry[$i];
                } else {
                    $rightEntry[] = $entry[$i];
                }
            }

            // Update left page with single entry
            $leaf->entries[0] = $leftEntry;
            $leaf->count = 1;
            $leaf->rebuildMeta();
            $leftNum = $leafPageNum ?? $this->allocatePage($leaf);

            // Create right page with single entry
            $rightLeaf = BTreeLeafPage::fromPool();
            $rightLeaf->entries[0] = $rightEntry;
            $rightLeaf->count = 1;
            $rightLeaf->entriesBuilt = true;
            $rightLeaf->rebuildMeta();
            $rightNum = $this->allocatePage($rightLeaf);

            $promoteKey = $entryKey;
        } else {
            $mid = $n >> 1;

            // Create right page - copy entries directly
            $rightLeaf = BTreeLeafPage::fromPool();
            $rightCount = $n - $mid;
            for ($i = 0; $i < $rightCount; $i++) {
                $rightLeaf->entries[$i] = $leaf->entries[$mid + $i];
            }
            $rightLeaf->count = $rightCount;
            $rightLeaf->entriesBuilt = true;
            $rightLeaf->rebuildMeta();
            $rightNum = $this->allocatePage($rightLeaf);

            $promoteKey = $leaf->entries[$mid][0];

            // Update left page - just truncate
            $leaf->count = $mid;
            $leaf->rebuildMeta();
            $leftNum = $leafPageNum ?? $this->allocatePage($leaf);
        }

        $this->propagateSplit($path, $pathLen - 4, $leftNum, $rightNum, $promoteKey);
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

            // Replace child pointer directly (0-based ArrayObject)
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
