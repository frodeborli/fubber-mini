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
     * Entries are list [null, e1, e2, ...] for array_is_list().
     */
    public function buildEntries(): void
    {
        if ($this->entriesBuilt) {
            return;
        }
        $n = $this->count;
        $meta = $this->meta;
        $data = $this->data;
        $this->entries = [null]; // Start with null at [0]
        for ($i = 1; $i <= $n; $i++) {
            $pos = $meta[$i];
            $rowIdCount = $meta[$n + 1 + $i];
            $keyLen = $meta[$i + 1] - $pos - ($rowIdCount << 3);
            $entry = \unpack('P' . $rowIdCount, $data, $pos);
            $entry[0] = \substr($data, $pos + ($rowIdCount << 3), $keyLen);
            $this->entries[] = $entry; // Append to list
        }
        $this->entriesBuilt = true;
    }

    /**
     * Get entries as list with null at [0] (for modification in insert/delete).
     * @return array<int, array<int, string|int>|null> List: [null, [key, id1, ...], ...]
     */
    public function toArray(): array
    {
        $this->buildEntries();
        // Return copy of the list (already in correct format)
        return \array_slice($this->entries, 0, $this->count + 1);
    }

    /**
     * Get just the key at 1-based index (for binary search comparisons).
     * Avoids unpacking rowIds - much faster for probes.
     */
    public function getKeyAt(int $i): string
    {
        // Pages from fromEntries()/setEntries() have entries already built (1-based)
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
     * Get single entry by 1-based index (for yielding rowIds after match).
     * @return array<int, string|int> Flat: [0 => key, 1 => rowId1, ...]
     */
    public function getEntry(int $i): array
    {
        // Pages from fromEntries()/setEntries() have entries already built (1-based)
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
     * Entries and meta are 1-based.
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
        for ($i = 1; $i <= $n; $i++) {
            $rowIdCounts[] = $meta[$n + 1 + $i];
        }

        // Build entry data: rowIds + key for each entry (entries are 1-based)
        $entryParts = [];
        for ($i = 1; $i <= $n; $i++) {
            $entry = $entries[$i];
            $rowIdCount = $rowIdCounts[$i - 1]; // rowIdCounts is 0-based here
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
     * @param array $entries List [null, [key, rowId1, ...], ...]
     */
    public static function fromEntries(array $entries): self
    {
        if (self::$poolCount > 0) {
            $instance = self::$pool[--self::$poolCount];
        } else {
            $instance = new self();
        }
        $instance->setEntries($entries);
        return $instance;
    }

    /**
     * Update entries in place (for overlay modification).
     * Rebuilds meta from entries.
     * @param array $entries List [null, [key, rowId1, ...], ...]
     */
    public function setEntries(array $entries): void
    {
        $n = \count($entries) - 1; // Subtract 1 for null at [0]
        $this->entries = $entries;
        $this->count = $n;
        $this->entriesBuilt = true;

        if ($n === 0) {
            $this->meta = [];
            return;
        }

        // Build meta: offsets (1..n+1) + rowIdCounts (n+2..2n+1), 1-based
        // Header size: type(1) + count(2) + offsets((n+1) * 2) + rowIdCounts(n * 2)
        $headerSize = 3 + ($n + 1) * 2 + $n * 2;
        $meta = [];
        $offset = $headerSize;

        for ($i = 1; $i <= $n; $i++) {
            $entry = $entries[$i];
            $meta[$i] = $offset; // offset at 1-based index
            $rowIdCount = \count($entry) - 1;
            $meta[$n + 1 + $i] = $rowIdCount; // rowIdCount at n+1+i (so n+2 to 2n+1)
            $offset += $rowIdCount * 8 + \strlen($entry[0]);
        }
        $meta[$n + 1] = $offset; // end marker

        $this->meta = $meta;
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
 *
 * @internal
 */
final class BTreeInternalPage
{
    /** Number of children */
    public int $childCount;
    /** @var array<int, int> Child page numbers - list with null at [0], children at [1..n] */
    public array $children;
    /** @var string[] Separator keys - list with null at [0], keys at [1..n] */
    public array $keys;

    public static function fromRaw(string $data): self
    {
        $instance = new self();

        $n = \ord($data[1]) | (\ord($data[2]) << 8);
        $childCount = $n + 1;
        $instance->childCount = $childCount;

        // unpack returns 1-based, convert to list [null, c1, c2, ...] for array_is_list()
        $unpacked = \unpack('V' . $childCount, $data, 3);
        $instance->children = [null];
        for ($i = 1; $i <= $childCount; $i++) {
            $instance->children[] = $unpacked[$i];
        }

        if ($n === 0) {
            $instance->keys = [null];
        } else {
            // Read n+1 offsets (last is end marker) in one unpack call
            $offsetsStart = 3 + $childCount * 4;
            $offsets = \unpack('v' . ($n + 1), $data, $offsetsStart);

            // Read keys as list [null, k1, k2, ...] for array_is_list()
            $instance->keys = [null];
            for ($i = 1; $i <= $n; $i++) {
                $instance->keys[] = \substr($data, $offsets[$i], $offsets[$i + 1] - $offsets[$i]);
            }
        }

        return $instance;
    }

    /**
     * Get an internal page from pool (or create new) and set children/keys.
     * @param int[] $children 0-based input array
     * @param string[] $keys 0-based input array
     */
    public static function fromArrays(array $children, array $keys): self
    {
        $instance = new self();
        // Convert to list [null, c1, c2, ...] for array_is_list()
        $instance->children = [null, ...$children];
        $instance->childCount = \count($children);
        $instance->keys = [null, ...$keys];
        return $instance;
    }

    /**
     * Update children and keys in place (for overlay modification).
     * @param int[] $children 0-based input array
     * @param string[] $keys 0-based input array
     */
    public function setChildrenAndKeys(array $children, array $keys): void
    {
        // Convert to list [null, c1, c2, ...] for array_is_list()
        $this->children = [null, ...$children];
        $this->childCount = \count($children);
        $this->keys = [null, ...$keys];
    }

    /**
     * Serialize to binary page format.
     * Children and keys are lists with null at [0].
     */
    public function asString(): string
    {
        $n = $this->childCount - 1; // key count = child count - 1
        $c = $this->childCount;

        // Header size: type(1) + count(2) + children((n+1)*4) + offsets((n+1)*2)
        $headerSize = 3 + $c * 4 + $c * 2;

        // Build key data and calculate offsets (skip null at keys[0])
        $keyData = '';
        $offsets = [];
        $offset = $headerSize;
        for ($i = 1; $i <= $n; $i++) {
            $offsets[] = $offset;
            $key = $this->keys[$i];
            $keyData .= $key;
            $offset += \strlen($key);
        }
        $offsets[] = $offset; // End marker

        // Pack children from [1..c] (skip null at [0])
        $childrenData = \array_slice($this->children, 1);
        return \pack('CvV' . $c . 'v' . $c, BTreeIndex::PAGE_INTERNAL, $n, ...$childrenData, ...$offsets) . $keyData;
    }

    public function __debugInfo(): array
    {
        return [
            'childCount' => $this->childCount,
            'children' => $this->children,
            'children_keys' => array_keys($this->children),
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

        // Navigate to leaf containing the key
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
                if ($childIdx < $parentPage->childCount) {
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
                        $stack[$stackTop++] = 1;
                        $stack[$stackTop++] = $page;
                        $leafPageNum = $page->children[1];
                    }

                    // Check if first entry matches (duplicates can span pages)
                    if ($leaf->count > 0) {
                        $firstEntry = $leaf->getEntry(1);
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
        $this->currentRoot = BTreeLeafPage::fromEntries([null]); // List format: [null] = empty
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
     * Returns 1-based index into $children array.
     * Keys array is 1-based.
     */
    private function findChildIndex(array $keys, int $keyCount, string $key): int
    {
        // Binary search for first key >= $key (keys are 1-based)
        $lo = 1;
        $hi = $keyCount + 1;
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($keys[$mid], $key) < 0) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo; // 1-based child index
    }

    // =========================================================================
    // Tree traversal
    // =========================================================================

    /**
     * Find path from root to leaf for given key.
     *
     * Returns flat array: [pageNum, childIdx, pageNum, childIdx, ...]
     * - pageNum: null for root, otherwise actual page number
     * - childIdx: -1 for leaf, otherwise which child was followed (1-based)
     *
     * Example paths:
     * - [null, -1] - root is a leaf
     * - [null, 2, 5, -1] - root is internal, followed child[2], leaf is page 5
     * - [null, 1, 3, 1, 7, -1] - two internal levels before leaf
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

            // Find child index via binary search (keys are 1-based)
            // Finds first key > target, result is 1-based child index
            $keyCount = $page->childCount - 1;
            $lo = 1;
            $hi = $keyCount + 1;
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($page->keys[$mid], $key) <= 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $path[] = $pageNum;
            $path[] = $lo; // 1-based child index

            // Descend to child - children array is 1-based
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
            $leaf = $this->getPageForWrite($leafPageNum); // $leafPageNum updated to new page number
        }
        $entries = $leaf->toArray(); // list: [null, e1, e2, ...]
        $n = count($entries) - 1; // Subtract 1 for null at [0]

        // Find position (1-based) and insert/update
        $pos = $this->findInsertPosition($entries, $n, $key);

        if ($pos <= $n && $entries[$pos][0] === $key) {
            // Key exists - append rowId (flat format: [key, id1, id2, ...])
            $entries[$pos][] = $rowId;
        } else {
            // New key - insert at position (list format: [null, e1, e2, ...])
            $newEntries = [null];
            for ($i = 1; $i < $pos; $i++) {
                $newEntries[] = $entries[$i];
            }
            $newEntries[] = [$key, $rowId];
            for ($i = $pos; $i <= $n; $i++) {
                $newEntries[] = $entries[$i];
            }
            $entries = $newEntries;
            $n++;
        }

        // Update entries in place and check if page still fits
        $leaf->setEntries($entries);

        if (strlen($leaf->asString()) <= self::PAGE_SIZE) {
            // Fits - page already modified in overlay
            if ($leafPageNum !== null) {
                // Update parent pointers to new page number
                $this->updatePath($path, $pathLen - 4, $leafPageNum);
            }
        } else {
            // Split required - check if we need to split rowIds within a single entry
            if ($n === 1) {
                // Single entry with too many rowIds - split the rowIds
                $entry = $entries[1];
                $entryKey = $entry[0];
                $rowIdCount = count($entry) - 1;
                $mid = ($rowIdCount >> 1) + 1; // Split rowIds in half

                $leftEntry = [$entryKey];
                $rightEntry = [$entryKey];
                for ($i = 1; $i <= $rowIdCount; $i++) {
                    if ($i < $mid) {
                        $leftEntry[] = $entry[$i];
                    } else {
                        $rightEntry[] = $entry[$i];
                    }
                }
                $leftEntries = [null, $leftEntry];
                $rightEntries = [null, $rightEntry];
            } else {
                $mid = $n >> 1;
                // Split into list format [null, e1, e2, ...]
                $leftEntries = [null];
                $rightEntries = [null];
                for ($i = 1; $i <= $mid; $i++) {
                    $leftEntries[] = $entries[$i];
                }
                for ($i = $mid + 1; $i <= $n; $i++) {
                    $rightEntries[] = $entries[$i];
                }
            }

            // Reuse left page (already in overlay), create new right page
            $leaf->setEntries($leftEntries);
            $leftNum = $leafPageNum ?? $this->allocatePage($leaf);

            $rightLeaf = BTreeLeafPage::fromEntries($rightEntries);
            $rightNum = $this->allocatePage($rightLeaf);

            // Promote first key of right leaf (1-based)
            $promoteKey = $rightEntries[1][0];

            $this->propagateSplit($path, $pathLen - 4, $leftNum, $rightNum, $promoteKey);
        }
    }

    /**
     * Find insert position in 1-based entries array.
     * Returns 1-based position where key should be inserted.
     */
    private function findInsertPosition(array $entries, int $n, string $key): int
    {
        $lo = 1;
        $hi = $n + 1;
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

        // Get parent from path (flat: pageNum, childIndex - childIndex is 1-based)
        $parentPageNum = $path[$pathIdx];
        $childIndex = $path[$pathIdx + 1]; // 1-based

        // Get parent for modification
        if ($parentPageNum === null) {
            $parent = $this->currentRoot;
        } else {
            $parent = $this->getPageForWrite($parentPageNum); // $parentPageNum updated
        }

        // Insert new child and key (list format: [null, c1, c2, ...])
        $oldChildCount = $parent->childCount;
        $oldKeyCount = $oldChildCount - 1;
        $newChildren = [null];
        $newKeys = [null];

        // Copy children up to split point, replacing split child with left
        for ($i = 1; $i < $childIndex; $i++) {
            $newChildren[] = $parent->children[$i];
        }
        $newChildren[] = $leftChild;
        $newChildren[] = $rightChild;
        for ($i = $childIndex + 1; $i <= $oldChildCount; $i++) {
            $newChildren[] = $parent->children[$i];
        }

        // Copy keys, inserting promoteKey at position childIndex
        for ($i = 1; $i < $childIndex; $i++) {
            $newKeys[] = $parent->keys[$i];
        }
        $newKeys[] = $promoteKey;
        for ($i = $childIndex; $i <= $oldKeyCount; $i++) {
            $newKeys[] = $parent->keys[$i];
        }

        // Update parent with list format arrays
        $parent->children = $newChildren;
        $parent->childCount = $oldChildCount + 1;
        $parent->keys = $newKeys;

        if (strlen($parent->asString()) <= self::PAGE_SIZE) {
            // Fits - page already modified in overlay
            if ($parentPageNum !== null) {
                // Update grandparent pointers to new page number
                $this->updatePath($path, $pathIdx - 2, $parentPageNum);
            }
        } else {
            // Split internal node
            $newKeyCount = $oldKeyCount + 1;
            $mid = $newKeyCount >> 1; // 1-based mid key

            // Left: keys 1..mid-1, children 1..mid (list format: [null, c1, c2, ...])
            // Right: keys mid+1..n, children mid+1..n+1
            $leftChildren = [null];
            $leftKeys = [null];
            for ($i = 1; $i <= $mid; $i++) {
                $leftChildren[] = $newChildren[$i];
            }
            for ($i = 1; $i < $mid; $i++) {
                $leftKeys[] = $newKeys[$i];
            }

            $rightChildren = [null];
            $rightKeys = [null];
            for ($i = $mid + 1; $i <= $oldChildCount + 1; $i++) {
                $rightChildren[] = $newChildren[$i];
            }
            for ($i = $mid + 1; $i <= $newKeyCount; $i++) {
                $rightKeys[] = $newKeys[$i];
            }

            $splitPromoteKey = $newKeys[$mid];

            // Reuse left page (already in overlay), create new right page
            $parent->children = $leftChildren;
            $parent->childCount = count($leftChildren) - 1; // Subtract 1 for null at [0]
            $parent->keys = $leftKeys;
            $leftNum = $parentPageNum ?? $this->allocatePage($parent);

            $rightPage = new BTreeInternalPage();
            $rightPage->children = $rightChildren;
            $rightPage->childCount = count($rightChildren) - 1; // Subtract 1 for null at [0]
            $rightPage->keys = $rightKeys;
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
            $childIndex = $path[$pathIdx + 1]; // 1-based

            // Get parent for modification
            if ($parentPageNum === null) {
                $parent = $this->currentRoot;
            } else {
                $parent = $this->getPageForWrite($parentPageNum); // $parentPageNum updated
            }

            // Replace child pointer directly (1-based array)
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
            $leaf = $this->getPageForWrite($leafPageNum); // $leafPageNum updated
        }
        $entries = $leaf->toArray(); // list: [null, e1, e2, ...]
        $n = count($entries) - 1; // Subtract 1 for null at [0]

        // Find key and remove rowId (1-based entries, each entry: [key, id1, id2, ...])
        $found = false;
        $removeIdx = 0;
        for ($i = 1; $i <= $n; $i++) {
            $entry = $entries[$i];
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

        // Remove entry if marked (rebuild list format [null, e1, e2, ...])
        if ($removeIdx > 0) {
            $newEntries = [null];
            for ($i = 1; $i <= $n; $i++) {
                if ($i !== $removeIdx) {
                    $newEntries[] = $entries[$i];
                }
            }
            $entries = $newEntries;
        }

        // Update leaf in place
        $leaf->setEntries($entries);

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

        // Navigate to first relevant leaf (childIdx is 1-based)
        while (!($page instanceof BTreeLeafPage)) {
            $childIdx = $this->findChildIndex($page->keys, $page->childCount - 1, $startKey);
            $leafPageNum = $page->children[$childIdx];
            $stack[$stackTop++] = $leafPageNum;
            $stack[$stackTop++] = $childIdx;
            $stack[$stackTop++] = $page;
            $page = $this->getPage($leafPageNum);
        }
        $leaf = $page;

        // Iterate through leaves (entries are 1-based: [1 => [key, id1, ...], ...])
        while (true) {
            $leaf->buildEntries();
            $entries = $leaf->entries;
            $entryCount = $leaf->count;

            for ($e = 1; $e <= $entryCount; $e++) {
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

            // Find next leaf via backtracking (childIdx is 1-based)
            $found = false;
            while ($stackTop > 0) {
                $parentPage = $stack[--$stackTop];
                $childIdx = $stack[--$stackTop];
                $parentNum = $stack[--$stackTop];

                if ($childIdx < $parentPage->childCount) {
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
                        $stack[$stackTop++] = 1;
                        $stack[$stackTop++] = $page;
                        $leafPageNum = $page->children[1];
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

        // Navigate to last relevant leaf (childIdx is 1-based)
        while (!($page instanceof BTreeLeafPage)) {
            // Find rightmost child that could contain keys <= end
            $childIdx = $page->childCount; // Start with rightmost (1-based)
            if ($end !== null) {
                $keyCount = $page->childCount - 1;
                for ($i = $keyCount; $i >= 1; $i--) {
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

        // Iterate through leaves in reverse (entries are 1-based)
        while (true) {
            $leaf->buildEntries();
            $entries = $leaf->entries;
            $entryCount = $leaf->count;

            for ($e = $entryCount; $e >= 1; $e--) {
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

            // Find previous leaf via backtracking (childIdx is 1-based)
            $found = false;
            while ($stackTop > 0) {
                $parentPage = $stack[--$stackTop];
                $childIdx = $stack[--$stackTop];
                $parentNum = $stack[--$stackTop];

                if ($childIdx > 1) {
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

                        $lastChild = $page->childCount; // 1-based last child
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

        // Internal node - recursively rewrite children first (children are list [null, c1, c2, ...])
        $newChildren = [];
        for ($i = 1; $i <= $page->childCount; $i++) {
            $newChildren[] = $this->rewritePages($temp, $page->children[$i], $pageMap, $newNextPage);
        }

        // Create new internal page with updated children (keys are list [null, k1, k2, ...], extract 0-based)
        $keys0 = [];
        for ($i = 1; $i < $page->childCount; $i++) { // n keys = n+1 children - 1
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
