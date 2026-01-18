<?php
namespace mini\Table\Index;

use Traversable;

/**
 * Append-only B-tree index with on-disk persistence.
 *
 * Binary file format using pack()/unpack():
 * - 64-byte header at offset 0
 * - 4KB pages (internal and leaf nodes)
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

    // Page types
    private const PAGE_INTERNAL = 0x01;
    private const PAGE_LEAF = 0x02;

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

    // Page cache (LRU) - stores parsed data, not raw bytes
    private const CACHE_MAX_PAGES = 128;
    private const CACHE_CHECK_INTERVAL = 1000; // Check for file changes every N reads

    /** @var array<int, array> Parsed page cache: pageNum => [type, parsedData] */
    private array $pageCache = [];

    /** @var int Counter for periodic cache invalidation check */
    private int $cacheReadCount = 0;

    /** @var int Sequence number when cache was last validated */
    private int $cacheSequence = 0;

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
                        // Empty tree - create first leaf
                        $leaf = $this->createLeafPage([[$key, [$rowId]]]);
                        $this->rootPage = $this->appendPage($leaf);
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
                $leaf = $this->createLeafPage([[$key, [$rowId]]]);
                $pageNum = $this->appendPage($leaf);
                $this->rootPage = $pageNum;
            } else {
                // Find path to leaf and insert
                $path = $this->findPath($key);
                $this->insertIntoTree($key, $rowId, $path);
            }

            $this->sequence++;
            $this->writeHeader();
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

            $this->sequence++;
            $this->writeHeader();
        });
    }

    public function eq(string $key): Traversable
    {
        // Check buffer first (if in transaction)
        $deletedIds = $this->deleteBuffer[$key] ?? [];

        // Yield from disk
        $this->readHeaderWithLock();

        if ($this->rootPage !== 0) {
            // Traverse to leaf
            $pageNum = $this->rootPage;
            while (true) {
                $type = $this->getPageType($pageNum);

                if ($type === self::PAGE_LEAF) {
                    $entries = $this->getLeafPage($pageNum);
                    foreach ($entries as [$entryKey, $rowIds]) {
                        if ($entryKey === $key) {
                            foreach ($rowIds as $id) {
                                if (!isset($deletedIds[$id])) {
                                    yield $id;
                                }
                            }
                            break;
                        }
                    }
                    break;
                }

                // Internal node - find child
                [$children, $keys] = $this->getInternalPage($pageNum);
                $childIdx = $this->findChildIndex($keys, $key);
                $pageNum = $children[$childIdx];
            }
        }

        // Yield from buffer (if in transaction)
        if (isset($this->buffer[$key])) {
            foreach (unpack('P*', $this->buffer[$key]) as $id) {
                if (!isset($deletedIds[$id])) {
                    yield $id;
                }
            }
        }
    }

    public function range(?string $start = null, ?string $end = null, bool $reverse = false): Traversable
    {
        $this->readHeaderWithLock();

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
                // Write header placeholder
                fwrite($temp, str_repeat("\0", self::HEADER_SIZE));

                // Rewrite all reachable pages
                $pageMap = []; // old page => new page
                $newNextPage = 1;

                $this->rewritePages($temp, $this->rootPage, $pageMap, $newNextPage);

                // Write final header
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
                $header .= str_repeat("\0", self::HEADER_SIZE - strlen($header));
                fwrite($temp, $header);

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
        $this->cacheSequence = $this->sequence;
    }

    private function readHeaderWithLock(): void
    {
        flock($this->lockFile, LOCK_SH);
        try {
            $this->readHeader();
        } finally {
            flock($this->lockFile, LOCK_UN);
        }
    }

    private function writeHeader(): void
    {
        $header = pack('VCCvPPP',
            self::MAGIC,
            self::VERSION,
            0, // reserved
            self::PAGE_SIZE,
            $this->rootPage,
            $this->nextPage,
            $this->sequence
        );
        $header .= str_repeat("\0", self::HEADER_SIZE - strlen($header));

        fseek($this->file, 0);
        fwrite($this->file, $header);
        fflush($this->file);

        // Update cache sequence and clear stale cached pages
        $this->pageCache = [];
        $this->cacheSequence = $this->sequence;
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
        $offset = self::HEADER_SIZE + ($pageNum - 1) * self::PAGE_SIZE;
        fseek($this->file, $offset);
        $data = fread($this->file, self::PAGE_SIZE);

        if (strlen($data) < self::PAGE_SIZE) {
            throw new \RuntimeException("Corrupted index: page $pageNum truncated");
        }

        return $data;
    }

    /**
     * Get page type (leaf or internal) with caching.
     */
    private function getPageType(int $pageNum): int
    {
        $cached = $this->getCachedPage($pageNum);
        return $cached[0];
    }

    /**
     * Get parsed leaf page with caching.
     * @return array<array{string, int[]}> [key, rowIds][]
     */
    private function getLeafPage(int $pageNum): array
    {
        $cached = $this->getCachedPage($pageNum);
        if ($cached[0] !== self::PAGE_LEAF) {
            throw new \RuntimeException("Page $pageNum is not a leaf");
        }
        return $cached[1];
    }

    /**
     * Get parsed internal page with caching.
     * @return array{int[], string[]} [children, keys]
     */
    private function getInternalPage(int $pageNum): array
    {
        $cached = $this->getCachedPage($pageNum);
        if ($cached[0] !== self::PAGE_INTERNAL) {
            throw new \RuntimeException("Page $pageNum is not internal");
        }
        return $cached[1];
    }

    /**
     * Get cached parsed page, reading and parsing if not cached.
     * @return array{int, mixed} [type, parsedData]
     */
    private function getCachedPage(int $pageNum): array
    {
        // Periodic check for file modifications by other processes
        if (++$this->cacheReadCount >= self::CACHE_CHECK_INTERVAL) {
            $this->cacheReadCount = 0;
            $this->checkCacheValidity();
        }

        // Check cache
        if (isset($this->pageCache[$pageNum])) {
            // Move to end for LRU (unset + set)
            $cached = $this->pageCache[$pageNum];
            unset($this->pageCache[$pageNum]);
            $this->pageCache[$pageNum] = $cached;
            return $cached;
        }

        // Read and parse
        $page = $this->readPageRaw($pageNum);
        $type = ord($page[0]);

        if ($type === self::PAGE_LEAF) {
            $parsed = [self::PAGE_LEAF, $this->parseLeafPage($page)];
        } else {
            $parsed = [self::PAGE_INTERNAL, $this->parseInternalPage($page)];
        }

        // Add to cache
        $this->pageCache[$pageNum] = $parsed;

        // Evict oldest if over limit
        if (count($this->pageCache) > self::CACHE_MAX_PAGES) {
            unset($this->pageCache[array_key_first($this->pageCache)]);
        }

        return $parsed;
    }

    /**
     * Check if file was modified by another process and invalidate cache if so.
     */
    private function checkCacheValidity(): void
    {
        flock($this->lockFile, LOCK_SH);
        try {
            fseek($this->file, 0);
            $data = fread($this->file, self::HEADER_SIZE);
            $header = unpack('Vmagic/Cversion/Creserved/vpageSize/ProotPage/PnextPage/Psequence', $data);

            if ($header['sequence'] !== $this->cacheSequence) {
                // File changed - invalidate cache and update state
                $this->pageCache = [];
                $this->rootPage = $header['rootPage'];
                $this->nextPage = $header['nextPage'];
                $this->sequence = $header['sequence'];
                $this->cacheSequence = $this->sequence;
            }
        } finally {
            flock($this->lockFile, LOCK_UN);
        }
    }

    private function appendPage(string $page): int
    {
        $pageNum = $this->nextPage++;
        $offset = self::HEADER_SIZE + ($pageNum - 1) * self::PAGE_SIZE;

        // Pad page to PAGE_SIZE
        if (strlen($page) < self::PAGE_SIZE) {
            $page .= str_repeat("\0", self::PAGE_SIZE - strlen($page));
        }

        fseek($this->file, $offset);
        fwrite($this->file, $page);
        fflush($this->file);

        return $pageNum;
    }

    // =========================================================================
    // Leaf page format
    // =========================================================================

    /**
     * Create a leaf page from entries.
     * @param array<array{string, int[]}> $entries [key, rowIds][]
     */
    private function createLeafPage(array $entries): string
    {
        $n = count($entries);

        // Build entry data and calculate offsets
        $entryData = '';
        $offsets = [];

        foreach ($entries as [$key, $rowIds]) {
            // Entry offset from page start (type + count + offsets)
            $offsets[] = 3 + $n * 2 + strlen($entryData);

            // Entry: keyLen (2) + key + rowIdCount (4) + rowIds (8 each)
            $entry = pack('v', strlen($key)) . $key;
            $entry .= pack('V', count($rowIds));
            foreach ($rowIds as $id) {
                $entry .= pack('P', $id);
            }
            $entryData .= $entry;
        }

        // Build page: type (1) + count (2) + offsets (n * 2) + entries
        $page = pack('Cv', self::PAGE_LEAF, $n);
        foreach ($offsets as $off) {
            $page .= pack('v', $off);
        }
        $page .= $entryData;

        return $page;
    }

    /**
     * Parse a leaf page.
     * @return array<array{string, int[]}> [key, rowIds][]
     */
    private function parseLeafPage(string $page): array
    {
        $header = unpack('Ctype/vcount', $page);
        $n = $header['count'];

        if ($n === 0) {
            return [];
        }

        // Read offsets
        $offsets = [];
        for ($i = 0; $i < $n; $i++) {
            $offsets[] = unpack('v', $page, 3 + $i * 2)[1];
        }

        // Read entries
        $entries = [];
        for ($i = 0; $i < $n; $i++) {
            $pos = $offsets[$i];
            $keyLen = unpack('v', $page, $pos)[1];
            $pos += 2;
            $key = substr($page, $pos, $keyLen);
            $pos += $keyLen;
            $rowIdCount = unpack('V', $page, $pos)[1];
            $pos += 4;

            $rowIds = [];
            for ($j = 0; $j < $rowIdCount; $j++) {
                $rowIds[] = unpack('P', $page, $pos)[1];
                $pos += 8;
            }

            $entries[] = [$key, $rowIds];
        }

        return $entries;
    }

    // =========================================================================
    // Internal page format
    // =========================================================================

    /**
     * Create an internal page.
     * @param int[] $children Child page numbers (n+1 children for n keys)
     * @param string[] $keys Separator keys
     */
    private function createInternalPage(array $children, array $keys): string
    {
        $n = count($keys);

        // Build key data and calculate offsets
        $keyData = '';
        $offsets = [];

        foreach ($keys as $key) {
            // Key offset from page start
            $offsets[] = 3 + ($n + 1) * 8 + $n * 2 + strlen($keyData);

            // Key entry: keyLen (2) + key
            $keyData .= pack('v', strlen($key)) . $key;
        }

        // Build page: type (1) + count (2) + children ((n+1) * 8) + offsets (n * 2) + keys
        $page = pack('Cv', self::PAGE_INTERNAL, $n);

        foreach ($children as $child) {
            $page .= pack('P', $child);
        }

        foreach ($offsets as $off) {
            $page .= pack('v', $off);
        }

        $page .= $keyData;

        return $page;
    }

    /**
     * Parse an internal page.
     * @return array{int[], string[]} [children, keys]
     */
    private function parseInternalPage(string $page): array
    {
        $header = unpack('Ctype/vcount', $page);
        $n = $header['count'];

        // Read children (n+1)
        $children = [];
        $pos = 3;
        for ($i = 0; $i <= $n; $i++) {
            $children[] = unpack('P', $page, $pos)[1];
            $pos += 8;
        }

        if ($n === 0) {
            return [$children, []];
        }

        // Read key offsets
        $offsets = [];
        for ($i = 0; $i < $n; $i++) {
            $offsets[] = unpack('v', $page, $pos)[1];
            $pos += 2;
        }

        // Read keys
        $keys = [];
        for ($i = 0; $i < $n; $i++) {
            $kpos = $offsets[$i];
            $keyLen = unpack('v', $page, $kpos)[1];
            $keys[] = substr($page, $kpos + 2, $keyLen);
        }

        return [$children, $keys];
    }

    /**
     * Find child index for given key in internal node.
     * Returns the index into $children array (not the page number).
     */
    private function findChildIndex(array $keys, string $key): int
    {
        // Binary search for first key > $key
        $lo = 0;
        $hi = count($keys);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (strcmp($keys[$mid], $key) <= 0) {
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
            $type = $this->getPageType($pageNum);

            if ($type === self::PAGE_LEAF) {
                $path[] = [$pageNum, -1];
                return $path;
            }

            [$children, $keys] = $this->getInternalPage($pageNum);

            // Find child index via binary search
            $lo = 0;
            $hi = count($keys);
            while ($lo < $hi) {
                $mid = ($lo + $hi) >> 1;
                if (strcmp($keys[$mid], $key) <= 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $path[] = [$pageNum, $lo];
            $pageNum = $children[$lo];
        }
    }

    // =========================================================================
    // Insert
    // =========================================================================

    private function insertIntoTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path
        [$leafPageNum, $_] = array_pop($path);
        $entries = $this->getLeafPage($leafPageNum);

        // Find position and insert/update
        $pos = $this->findInsertPosition($entries, $key);

        if ($pos < count($entries) && $entries[$pos][0] === $key) {
            // Key exists - append rowId
            $entries[$pos][1][] = $rowId;
        } else {
            // New key - insert at position
            array_splice($entries, $pos, 0, [[$key, [$rowId]]]);
        }

        // Check if leaf needs splitting
        $newLeaf = $this->createLeafPage($entries);

        if (strlen($newLeaf) <= self::PAGE_SIZE) {
            // Fits - just append new leaf and update parent
            $newLeafNum = $this->appendPage($newLeaf);
            $this->updatePath($path, $leafPageNum, $newLeafNum, null, null);
        } else {
            // Split required
            $mid = count($entries) >> 1;
            $leftEntries = array_slice($entries, 0, $mid);
            $rightEntries = array_slice($entries, $mid);

            $leftLeaf = $this->createLeafPage($leftEntries);
            $rightLeaf = $this->createLeafPage($rightEntries);

            $leftNum = $this->appendPage($leftLeaf);
            $rightNum = $this->appendPage($rightLeaf);

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

    private function propagateSplit(array $path, int $oldChild, int $leftChild, int $rightChild, string $promoteKey): void
    {
        if (empty($path)) {
            // Create new root
            $newRoot = $this->createInternalPage([$leftChild, $rightChild], [$promoteKey]);
            $this->rootPage = $this->appendPage($newRoot);
            return;
        }

        // Pop parent from path
        [$parentPageNum, $childIndex] = array_pop($path);
        [$children, $keys] = $this->getInternalPage($parentPageNum);

        // Replace old child with left, insert right after
        $children[$childIndex] = $leftChild;
        array_splice($children, $childIndex + 1, 0, [$rightChild]);
        array_splice($keys, $childIndex, 0, [$promoteKey]);

        // Check if internal node needs splitting
        $newParent = $this->createInternalPage($children, $keys);

        if (strlen($newParent) <= self::PAGE_SIZE) {
            // Fits
            $newParentNum = $this->appendPage($newParent);
            $this->updatePath($path, $parentPageNum, $newParentNum, null, null);
        } else {
            // Split internal node
            $mid = count($keys) >> 1;
            $leftKeys = array_slice($keys, 0, $mid);
            $rightKeys = array_slice($keys, $mid + 1);
            $leftChildren = array_slice($children, 0, $mid + 1);
            $rightChildren = array_slice($children, $mid + 1);
            $promoteKey = $keys[$mid];

            $leftPage = $this->createInternalPage($leftChildren, $leftKeys);
            $rightPage = $this->createInternalPage($rightChildren, $rightKeys);

            $leftNum = $this->appendPage($leftPage);
            $rightNum = $this->appendPage($rightPage);

            $this->propagateSplit($path, $parentPageNum, $leftNum, $rightNum, $promoteKey);
        }
    }

    private function updatePath(array $path, int $oldChild, int $newChild, ?int $extraChild, ?string $extraKey): void
    {
        if (empty($path)) {
            // We've reached the root level
            if ($extraChild === null) {
                $this->rootPage = $newChild;
            } else {
                // This shouldn't happen - splits are handled in propagateSplit
                $newRoot = $this->createInternalPage([$newChild, $extraChild], [$extraKey]);
                $this->rootPage = $this->appendPage($newRoot);
            }
            return;
        }

        // Pop parent
        [$parentPageNum, $childIndex] = array_pop($path);
        [$children, $keys] = $this->getInternalPage($parentPageNum);

        // Replace child pointer
        $children[$childIndex] = $newChild;

        if ($extraChild !== null) {
            array_splice($children, $childIndex + 1, 0, [$extraChild]);
            array_splice($keys, $childIndex, 0, [$extraKey]);
        }

        $newParent = $this->createInternalPage($children, $keys);
        $newParentNum = $this->appendPage($newParent);

        $this->updatePath($path, $parentPageNum, $newParentNum, null, null);
    }

    // =========================================================================
    // Delete
    // =========================================================================

    private function deleteFromTree(string $key, int $rowId, array $path): void
    {
        // Get leaf from path
        [$leafPageNum, $_] = array_pop($path);
        $entries = $this->getLeafPage($leafPageNum);

        // Find key
        $found = false;
        foreach ($entries as $i => [$entryKey, $rowIds]) {
            if ($entryKey === $key) {
                // Remove rowId
                $pos = array_search($rowId, $rowIds, true);
                if ($pos !== false) {
                    array_splice($entries[$i][1], $pos, 1);
                    $found = true;

                    // If no more rowIds, remove entire entry
                    if (empty($entries[$i][1])) {
                        array_splice($entries, $i, 1);
                    }
                }
                break;
            }
        }

        if (!$found) {
            return; // Key/rowId not found, nothing to do
        }

        // Create new leaf (even if empty - lazy cleanup via compact)
        $newLeaf = $this->createLeafPage($entries);
        $newLeafNum = $this->appendPage($newLeaf);

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
        $type = $this->getPageType($pageNum);

        if ($type === self::PAGE_LEAF) {
            foreach ($this->getLeafPage($pageNum) as [$key, $rowIds]) {
                $merged[$key] = array_merge($merged[$key] ?? [], $rowIds);
            }
            return;
        }

        // Internal node - recurse into children
        [$children, $_] = $this->getInternalPage($pageNum);
        foreach ($children as $child) {
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

        // Convert to entries format
        $entries = [];
        foreach ($data as $key => $rowIds) {
            $entries[] = [$key, $rowIds];
        }

        // Build tree bottom-up for optimal structure
        $this->rootPage = $this->bulkBuildTree($entries);
    }

    /**
     * Build balanced B-tree from sorted entries using bulk loading.
     * @param array<array{string, int[]}> $entries Sorted [key, rowIds][]
     * @return int Root page number
     */
    private function bulkBuildTree(array $entries): int
    {
        // Calculate max entries per leaf (approximate)
        // Leave some headroom for variable-length keys
        $maxPerLeaf = 50; // Conservative estimate

        // Create leaf pages
        $leafPages = [];
        $leafKeys = []; // First key of each leaf (for building internal nodes)

        for ($i = 0; $i < count($entries); $i += $maxPerLeaf) {
            $chunk = array_slice($entries, $i, $maxPerLeaf);

            // Check if chunk fits in a page, split if needed
            $page = $this->createLeafPage($chunk);
            while (strlen($page) > self::PAGE_SIZE && count($chunk) > 1) {
                $chunk = array_slice($chunk, 0, (int)(count($chunk) * 0.8));
                $page = $this->createLeafPage($chunk);
            }

            $pageNum = $this->appendPage($page);
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
                    $page = $this->createInternalPage($nodeChildren, $nodeKeys);
                    if (strlen($page) > self::PAGE_SIZE) {
                        // Back up one
                        array_pop($nodeChildren);
                        array_pop($nodeKeys);
                        $i--;
                        break;
                    }
                }

                $pageNum = $this->appendPage($this->createInternalPage($nodeChildren, $nodeKeys));
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
            $type = $this->getPageType($pageNum);
            if ($type === self::PAGE_LEAF) {
                break;
            }

            [$children, $keys] = $this->getInternalPage($pageNum);
            $childIdx = $this->findChildIndex($keys, $startKey);
            $stack[] = [$pageNum, $childIdx, $children, $keys];
            $pageNum = $children[$childIdx];
        }

        while (true) {
            $entries = $this->getLeafPage($pageNum);

            foreach ($entries as [$key, $rowIds]) {
                if ($start !== null && strcmp($key, $start) < 0) {
                    continue;
                }
                if ($end !== null && strcmp($key, $end) > 0) {
                    return;
                }

                foreach ($rowIds as $id) {
                    yield [$key, $id];
                }
            }

            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $children, $keys] = array_pop($stack);

                if ($childIdx + 1 < count($children)) {
                    $childIdx++;
                    $stack[] = [$parentNum, $childIdx, $children, $keys];
                    $pageNum = $children[$childIdx];

                    while (true) {
                        $type = $this->getPageType($pageNum);
                        if ($type === self::PAGE_LEAF) {
                            break;
                        }

                        [$children, $keys] = $this->getInternalPage($pageNum);
                        $stack[] = [$pageNum, 0, $children, $keys];
                        $pageNum = $children[0];
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
            $type = $this->getPageType($pageNum);
            if ($type === self::PAGE_LEAF) {
                break;
            }

            [$children, $keys] = $this->getInternalPage($pageNum);

            // Find rightmost child that could contain keys <= end
            $childIdx = count($children) - 1;
            if ($end !== null) {
                for ($i = count($keys) - 1; $i >= 0; $i--) {
                    if (strcmp($keys[$i], $end) <= 0) {
                        $childIdx = $i + 1;
                        break;
                    }
                    $childIdx = $i;
                }
            }

            $stack[] = [$pageNum, $childIdx, $children, $keys];
            $pageNum = $children[$childIdx];
        }

        while (true) {
            $entries = $this->getLeafPage($pageNum);

            for ($i = count($entries) - 1; $i >= 0; $i--) {
                [$key, $rowIds] = $entries[$i];

                if ($end !== null && strcmp($key, $end) > 0) {
                    continue;
                }
                if ($start !== null && strcmp($key, $start) < 0) {
                    return;
                }

                for ($j = count($rowIds) - 1; $j >= 0; $j--) {
                    yield [$key, $rowIds[$j]];
                }
            }

            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $children, $keys] = array_pop($stack);

                if ($childIdx > 0) {
                    $childIdx--;
                    $stack[] = [$parentNum, $childIdx, $children, $keys];
                    $pageNum = $children[$childIdx];

                    while (true) {
                        $type = $this->getPageType($pageNum);
                        if ($type === self::PAGE_LEAF) {
                            break;
                        }

                        [$children, $keys] = $this->getInternalPage($pageNum);
                        $lastChild = count($children) - 1;
                        $stack[] = [$pageNum, $lastChild, $children, $keys];
                        $pageNum = $children[$lastChild];
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
            $type = $this->getPageType($pageNum);
            if ($type === self::PAGE_LEAF) {
                break;
            }

            [$children, $keys] = $this->getInternalPage($pageNum);
            $childIdx = $this->findChildIndex($keys, $startKey);
            $stack[] = [$pageNum, $childIdx, $children, $keys];
            $pageNum = $children[$childIdx];
        }

        // Iterate through leaves
        while (true) {
            $entries = $this->getLeafPage($pageNum);

            foreach ($entries as [$key, $rowIds]) {
                if ($start !== null && strcmp($key, $start) < 0) {
                    continue;
                }
                if ($end !== null && strcmp($key, $end) > 0) {
                    return;
                }

                foreach ($rowIds as $id) {
                    yield $id;
                }
            }

            // Find next leaf via backtracking
            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $children, $keys] = array_pop($stack);

                if ($childIdx + 1 < count($children)) {
                    $childIdx++;
                    $stack[] = [$parentNum, $childIdx, $children, $keys];
                    $pageNum = $children[$childIdx];

                    // Navigate down to leftmost leaf
                    while (true) {
                        $type = $this->getPageType($pageNum);
                        if ($type === self::PAGE_LEAF) {
                            break;
                        }

                        [$children, $keys] = $this->getInternalPage($pageNum);
                        $stack[] = [$pageNum, 0, $children, $keys];
                        $pageNum = $children[0];
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
            $type = $this->getPageType($pageNum);
            if ($type === self::PAGE_LEAF) {
                break;
            }

            [$children, $keys] = $this->getInternalPage($pageNum);

            // Find rightmost child that could contain keys <= end
            $childIdx = count($children) - 1;
            if ($end !== null) {
                for ($i = count($keys) - 1; $i >= 0; $i--) {
                    if (strcmp($keys[$i], $end) <= 0) {
                        $childIdx = $i + 1;
                        break;
                    }
                    $childIdx = $i;
                }
            }

            $stack[] = [$pageNum, $childIdx, $children, $keys];
            $pageNum = $children[$childIdx];
        }

        // Iterate through leaves in reverse
        while (true) {
            $entries = $this->getLeafPage($pageNum);

            for ($i = count($entries) - 1; $i >= 0; $i--) {
                [$key, $rowIds] = $entries[$i];

                if ($end !== null && strcmp($key, $end) > 0) {
                    continue;
                }
                if ($start !== null && strcmp($key, $start) < 0) {
                    return;
                }

                for ($j = count($rowIds) - 1; $j >= 0; $j--) {
                    yield $rowIds[$j];
                }
            }

            // Find previous leaf via backtracking
            $found = false;
            while (!empty($stack)) {
                [$parentNum, $childIdx, $children, $keys] = array_pop($stack);

                if ($childIdx > 0) {
                    $childIdx--;
                    $stack[] = [$parentNum, $childIdx, $children, $keys];
                    $pageNum = $children[$childIdx];

                    // Navigate down to rightmost leaf
                    while (true) {
                        $type = $this->getPageType($pageNum);
                        if ($type === self::PAGE_LEAF) {
                            break;
                        }

                        [$children, $keys] = $this->getInternalPage($pageNum);
                        $lastChild = count($children) - 1;
                        $stack[] = [$pageNum, $lastChild, $children, $keys];
                        $pageNum = $children[$lastChild];
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

        $type = $this->getPageType($pageNum);

        if ($type === self::PAGE_LEAF) {
            // Leaf - copy raw bytes directly
            $page = $this->readPageRaw($pageNum);
            $newPageNum = $newNextPage++;
            $pageMap[$pageNum] = $newPageNum;

            $offset = self::HEADER_SIZE + ($newPageNum - 1) * self::PAGE_SIZE;
            fseek($temp, $offset);
            fwrite($temp, $page);

            return $newPageNum;
        }

        // Internal node - recursively rewrite children first
        [$children, $keys] = $this->getInternalPage($pageNum);

        $newChildren = [];
        foreach ($children as $child) {
            $newChildren[] = $this->rewritePages($temp, $child, $pageMap, $newNextPage);
        }

        // Create new internal page with updated children
        $newPage = $this->createInternalPage($newChildren, $keys);
        if (strlen($newPage) < self::PAGE_SIZE) {
            $newPage .= str_repeat("\0", self::PAGE_SIZE - strlen($newPage));
        }

        $newPageNum = $newNextPage++;
        $pageMap[$pageNum] = $newPageNum;

        $offset = self::HEADER_SIZE + ($newPageNum - 1) * self::PAGE_SIZE;
        fseek($temp, $offset);
        fwrite($temp, $newPage);

        return $newPageNum;
    }
}
