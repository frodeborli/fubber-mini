<?php
namespace mini\Mini\ApcuDrivers;

use PDO;
use PDOException;

class PDOSqlite3ApcuDriver implements ApcuDriverInterface
{
    use ApcuDriverTrait;

    private PDO $pdo;

    /**
     * @param string $path Path to SQLite file. On Linux, /dev/shm/... gives
     *                     tmpfs-backed "in-memory" speed with persistence
     *                     across worker processes.
     */
    public function __construct(string $path)
    {
        $dsn = 'sqlite:' . $path;
        $this->pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->initSchema();
        $this->configurePragmas();
    }

    private function initSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS cache (
                 key     TEXT PRIMARY KEY,
                 payload BLOB NOT NULL
             )'
        );
        // Optional index is redundant with PRIMARY KEY
    }

    /**
     * Tuned for speed; tweak as needed.
     */
    private function configurePragmas(): void
    {
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        $this->pdo->exec('PRAGMA locking_mode = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    /* --------------------------------------------------------------------
     * LOW-LEVEL BACKEND PRIMITIVES FOR ApcuDriverTrait
     * ------------------------------------------------------------------ */

    /**
     * _fetch(string $key, bool &$found = null): ?string
     */
    protected function _fetch(string $key, bool &$found = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM cache WHERE key = :key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

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
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO cache (key, payload) VALUES (:key, :payload)'
        );

        $stmt->execute([
            ':key'     => $key,
            ':payload' => $payload,
        ]);

        // INSERT OR IGNORE: rowCount() == 1 means insert happened, 0 = existed
        return $stmt->rowCount() === 1;
    }

    /**
     * _store(string $key, string $payload, int $ttl): bool
     */
    protected function _store(string $key, string $payload, int $ttl): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cache (key, payload)
             VALUES (:key, :payload)
             ON CONFLICT(key) DO UPDATE SET payload = excluded.payload'
        );

        return $stmt->execute([
            ':key'     => $key,
            ':payload' => $payload,
        ]);
    }

    /**
     * _delete(string $key): bool
     */
    protected function _delete(string $key): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cache WHERE key = :key');
        $stmt->execute([':key' => $key]);

        return $stmt->rowCount() > 0;
    }

    /* --------------------------------------------------------------------
     * ApcuDriverInterface methods not provided by the trait
     * ------------------------------------------------------------------ */

    public function info(bool $limited = false): array|false
    {
        // Just return something minimal and cheap.
        $count = (int)$this->pdo
            ->query('SELECT COUNT(*) AS c FROM cache')
            ->fetch()['c'];

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
        $this->pdo->exec('DELETE FROM cache');
        return true;
    }

    public function enabled(): bool
    {
        return extension_loaded('pdo_sqlite') || extension_loaded('sqlite3');
    }
}
