<?php
namespace mini\Mini\ApcuDrivers;

use PDO;
use PDOException;

class PDOSqlite3ApcuDriver implements ApcuDriverInterface
{
    use ApcuDriverTrait;

    /**
     * Idle connections. One process may run many requests at once (coroutines: phasync, Swoole,
     * Fibers), and a connection serves one operation at a time: ext-phasync refuses a second
     * coroutine's statement on a connection another coroutine's statement still holds
     * ("Concurrent operations on the same resource are not supported"). So every operation
     * borrows a connection of its own and gives it back; concurrent operations open more.
     *
     * @var list<PDO>
     */
    private array $idle = [];

    /**
     * @param string $path Path to SQLite file. On Linux, /dev/shm/... gives
     *                     tmpfs-backed "in-memory" speed with persistence
     *                     across worker processes.
     */
    public function __construct(private readonly string $path)
    {
        $this->with(fn (PDO $pdo) => $pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                 key     TEXT PRIMARY KEY,
                 payload BLOB NOT NULL
             )'
        ));
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Tuned for speed; tweak as needed.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = OFF');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA locking_mode = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    /**
     * Run $fn with a connection no other operation is using.
     *
     * @template T
     * @param \Closure(PDO): T $fn
     * @return T
     */
    private function with(\Closure $fn): mixed
    {
        $pdo = \array_pop($this->idle) ?? $this->connect();
        try {
            return $fn($pdo);
        } finally {
            if (\count($this->idle) < 8) {   // (a burst's extra connections close as it ends)
                $this->idle[] = $pdo;
            }
        }
    }

    /* --------------------------------------------------------------------
     * LOW-LEVEL BACKEND PRIMITIVES FOR ApcuDriverTrait
     * ------------------------------------------------------------------ */

    /**
     * _fetch(string $key, bool &$found = null): ?string
     */
    protected function _fetch(string $key, bool &$found = null): ?string
    {
        $row = $this->with(function (PDO $pdo) use ($key) {
            $stmt = $pdo->prepare('SELECT payload FROM cache WHERE key = :key');
            $stmt->execute([':key' => $key]);

            return $stmt->fetch();
        });

        if ($row === false) {
            $found = false;
            return null;
        }

        $found = true;
        return $row['payload'];
    }

    /**
     * _add(string $key, string $payload, int $ttl): bool
     *
     * TTL is ignored here; trait stores logical expiry inside payload.
     */
    protected function _add(string $key, string $payload, int $ttl): bool
    {
        return $this->with(function (PDO $pdo) use ($key, $payload) {
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO cache (key, payload) VALUES (:key, :payload)'
            );
            $stmt->execute([
                ':key'     => $key,
                ':payload' => $payload,
            ]);

            // INSERT OR IGNORE: rowCount() == 1 means insert happened, 0 = existed
            return $stmt->rowCount() === 1;
        });
    }

    /**
     * _store(string $key, string $payload, int $ttl): bool
     */
    protected function _store(string $key, string $payload, int $ttl): bool
    {
        return $this->with(fn (PDO $pdo) => $pdo->prepare(
            'INSERT INTO cache (key, payload)
             VALUES (:key, :payload)
             ON CONFLICT(key) DO UPDATE SET payload = excluded.payload'
        )->execute([
            ':key'     => $key,
            ':payload' => $payload,
        ]));
    }

    /**
     * _delete(string $key): bool
     */
    protected function _delete(string $key): bool
    {
        return $this->with(function (PDO $pdo) use ($key) {
            $stmt = $pdo->prepare('DELETE FROM cache WHERE key = :key');
            $stmt->execute([':key' => $key]);

            return $stmt->rowCount() > 0;
        });
    }

    /* --------------------------------------------------------------------
     * GARBAGE COLLECTION
     * ------------------------------------------------------------------ */

    /**
     * Probabilistic GC: 1 in 10,000 chance to clean expired entries.
     *
     * SQLite has no native TTL, so we need to periodically scan for expired
     * entries based on the logical expiry stored in the payload.
     */
    protected function maybeGarbageCollect(): void
    {
        if (mt_rand(0, 9999) !== 0) {
            return;
        }

        // Scan all entries and delete expired ones
        // This is expensive but runs rarely (0.01% of writes)
        try {
            $toDelete = [];
            foreach ($this->with(fn (PDO $pdo) => $pdo->query('SELECT key, payload FROM cache')->fetchAll()) as $row) {
                $expired = false;
                $expiresAt = null;
                $this->unpackValue($row['payload'], $expired, $expiresAt);

                if ($expired) {
                    $toDelete[] = $row['key'];
                }
            }

            if (!empty($toDelete)) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $this->with(fn (PDO $pdo) => $pdo->prepare("DELETE FROM cache WHERE key IN ($placeholders)")->execute($toDelete));
            }
        } catch (\PDOException $e) {
            // GC failure is not critical - ignore
        }
    }

    /* --------------------------------------------------------------------
     * ApcuDriverInterface methods not provided by the trait
     * ------------------------------------------------------------------ */

    public function info(bool $limited = false): array|false
    {
        // Just return something minimal and cheap.
        $count = (int)$this->with(fn (PDO $pdo) => $pdo->query('SELECT COUNT(*) AS c FROM cache')->fetch()['c']);

        return [
            'num_entries' => $count,
            'limited'     => $limited,
            'driver'      => 'sqlite',
        ];
    }

    public function sma_info(bool $limited = false): array|false
    {
        // SQLite doesn't expose allocator info in a useful way here.
        return [
            'available_memory' => null,
            'used_memory'      => null,
            'num_seg'          => 1,
            'seg_size'         => null,
            'limited'          => $limited,
            'driver'           => 'sqlite',
        ];
    }

    public function clear_cache(): bool
    {
        $this->with(fn (PDO $pdo) => $pdo->exec('DELETE FROM cache'));
        return true;
    }

    public function enabled(): bool
    {
        return extension_loaded('pdo_sqlite') || extension_loaded('sqlite3');
    }
}
