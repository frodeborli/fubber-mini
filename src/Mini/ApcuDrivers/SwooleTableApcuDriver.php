<?php
namespace mini\Mini\ApcuDrivers;

use Swoole\Table;
use Swoole\Lock;

class SwooleTableApcuDriver implements ApcuDriverInterface
{
    use ApcuDriverTrait;

    /** @var Table */
    protected Table $table;

    /** @var Lock global mutex used only to implement SETNX semantics for _add() */
    protected Lock $addLock;

    /**
     * @param int $size       Number of rows in the table (must be power of two in Swoole <= 4.x).
     * @param int $valueSize  Max payload size in bytes (must fit your serialized values + TTL metadata).
     */
    public function __construct(int $size = 1024, int $valueSize = 4096)
    {
        $table = new Table($size);
        $table->column('payload', Table::TYPE_STRING, $valueSize);
        $table->create();

        $this->table   = $table;
        $this->addLock = new Lock(SWOOLE_MUTEX);
    }

    /* --------------------------------------------------------------------
     * LOW-LEVEL BACKEND PRIMITIVES FOR ApcuDriverTrait
     * ------------------------------------------------------------------ */

    /**
     * Fetch raw payload from Swoole\Table.
     */
    protected function _fetch(string $key, bool &$found = null): ?string
    {
        // Table::get() returns array or false; using field shortcut variant
        $payload = $this->table->get($key, 'payload');
        if ($payload === false || $payload === null) {
            $found = false;
            return null;
        }

        $found = true;
        return $payload;
    }

    /**
     * Atomic "add if not exists" (SETNX) using a coarse mutex.
     *
     * We deliberately do NOT lock in _store/_delete, because the trait
     * provides per-key locking for the operations that require it.
     */
    protected function _add(string $key, string $payload, int $ttl): bool
    {
        $this->addLock->lock();
        try {
            if ($this->table->exists($key)) {
                return false;
            }
            // Swoole\Table has no TTL; trait handles logical TTL and
            // backend TTL is effectively ignored for entries here.
            return $this->table->set($key, ['payload' => $payload]);
        } finally {
            $this->addLock->unlock();
        }
    }

    /**
     * Unconditional overwrite (SET).
     */
    protected function _store(string $key, string $payload, int $ttl): bool
    {
        return $this->table->set($key, ['payload' => $payload]);
    }

    /**
     * Delete a row.
     */
    protected function _delete(string $key): bool
    {
        return $this->table->del($key);
    }

    /* --------------------------------------------------------------------
     * GARBAGE COLLECTION
     * ------------------------------------------------------------------ */

    /**
     * Probabilistic GC: 1 in 10,000 chance to clean expired entries.
     *
     * Swoole\Table has no native TTL, so we scan for logically expired entries.
     */
    protected function maybeGarbageCollect(): void
    {
        if (mt_rand(0, 9999) !== 0) {
            return;
        }

        // Scan table and delete expired entries
        foreach ($this->table as $key => $row) {
            $expired = false;
            $expiresAt = null;
            $this->unpackValue($row['payload'], $expired, $expiresAt);

            if ($expired) {
                $this->table->del($key);
            }
        }
    }

    /* --------------------------------------------------------------------
     * REQUIRED ApcuDriverInterface METHODS NOT PROVIDED BY THE TRAIT
     * ------------------------------------------------------------------ */

    /**
     * apcu_cache_info(): here we just return something minimal and honest.
     */
    public function info(bool $limited = false): array|false
    {
        $entries = 0;
        foreach ($this->table as $k => $row) {
            $entries++;
        }

        return [
            'num_entries' => $entries,
            'limited'     => $limited,
            'driver'      => 'swoole_table',
        ];
    }

    /**
     * apcu_sma_info(): Swoole\Table doesn’t expose allocator details,
     * so we just return a minimal stub.
     */
    public function sma_info(bool $limited = false): array|false
    {
        return [
            'available_memory' => null,
            'used_memory'      => null,
            'num_seg'          => 1,
            'seg_size'         => null,
            'limited'          => $limited,
            'driver'           => 'swoole_table',
        ];
    }

    /**
     * apcu_clear_cache(): wipe the table.
     */
    public function clear_cache(): bool
    {
        // Swoole\Table is Traversable: we can iterate and delete.
        foreach ($this->table as $key => $row) {
            $this->table->del($key);
        }
        return true;
    }

    /**
     * apcu_enabled(): this driver is enabled if Swoole is available.
     */
    public function enabled(): bool
    {
        return extension_loaded('swoole');
    }
}
