<?php
namespace mini\Mini\ApcuDrivers;

/**
 * ApcuDriverTrait
 *
 * Implements APCu-like per-key operations (add/store/fetch/exists/cas/inc/dec/entry)
 * using four low-level primitives that the backend MUST provide:
 *
 *   - _fetch(string $key, bool &$found = null): ?string
 *   - _add(string $key, string $payload, int $ttl): bool      // SETNX
 *   - _store(string $key, string $payload, int $ttl): bool    // SET (overwrite)
 *   - _delete(string $key): bool
 *
 * The trait owns the serialization format and TTL semantics. The backend uses TTL
 * only for coarse eviction; the source of truth for expiry is stored in the payload.
 *
 * Limitations:
 *   - No hit counters or detailed stats; if you need those, use real APCu.
 *   - info()/sma_info()/clear_cache()/enabled() are left to the driver.
 */
trait ApcuDriverTrait
{
    /* --------------------------------------------------------------------
     * LOW-LEVEL BACKEND PRIMITIVES (MUST IMPLEMENT)
     * ------------------------------------------------------------------ */

    /**
     * Fetch a raw payload for a key.
     *
     * @param string $key
     * @param bool|null $found Set to true if the key exists in the backend.
     * @return string|null Raw payload or null if not found.
     */
    abstract protected function _fetch(string $key, bool &$found = null): ?string;

    /**
     * Add a raw payload if the key does not exist (SETNX semantics).
     *
     * @param string $key
     * @param string $payload
     * @param int    $ttl Backend TTL in seconds (0 = no expiry).
     */
    abstract protected function _add(string $key, string $payload, int $ttl): bool;

    /**
     * Store (overwrite) a raw payload (SET semantics).
     *
     * @param string $key
     * @param string $payload
     * @param int    $ttl Backend TTL in seconds (0 = no expiry).
     */
    abstract protected function _store(string $key, string $payload, int $ttl): bool;

    /**
     * Delete a key from the backend.
     */
    abstract protected function _delete(string $key): bool;

    /* --------------------------------------------------------------------
     * INTERNAL ENCODING / TTL HELPERS
     * ------------------------------------------------------------------ */

    /**
     * Encode a value and its expiry into a payload string.
     *
     * @param mixed    $value
     * @param int|null $expiresAt Unix timestamp or null for no expiry.
     */
    protected function packValue(mixed $value, ?int $expiresAt): string
    {
        return serialize([
            'v'          => $value,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Decode payload into value + expiry.
     *
     * @param string     $raw
     * @param bool       $expired Set true if logically expired.
     * @param int|null   $expiresAt
     * @return mixed     The stored value (or null if invalid).
     */
    protected function unpackValue(string $raw, bool &$expired, ?int &$expiresAt): mixed
    {
        $expired   = false;
        $expiresAt = null;

        $data = @unserialize($raw);
        if (!is_array($data) || !array_key_exists('v', $data)) {
            $expired = true;
            return null;
        }

        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt <= time()) {
            $expired = true;
            return null;
        }

        return $data['v'];
    }

    /**
     * Compute logical expiry from TTL.
     */
    protected function computeExpiresAt(int $ttl): ?int
    {
        return $ttl > 0 ? time() + $ttl : null;
    }

    /**
     * Convert logical expiry to backend TTL.
     */
    protected function backendTtl(?int $expiresAt): int
    {
        if ($expiresAt === null) {
            return 0;
        }

        $remaining = $expiresAt - time();
        return $remaining > 0 ? $remaining : 1;
    }

    /* --------------------------------------------------------------------
     * SIMPLE LOCKING USING _add()
     * ------------------------------------------------------------------ */

    protected function lockKey(string $key): string
    {
        return "\0lock:" . $key;
    }

    protected function acquireLock(string $key, int $lockTtl = 5): void
    {
        $lockKey  = $this->lockKey($key);
        $attempts = 0;

        while (true) {
            if ($this->_add($lockKey, '1', $lockTtl)) {
                return;
            }

            usleep(1000); // 1 ms
            if (++$attempts > 5000) {
                throw new \RuntimeException("APCu polyfill lock timeout for key '$key'");
            }
        }
    }

    protected function releaseLock(string $key): void
    {
        $this->_delete($this->lockKey($key));
    }

    protected function withLock(string $key, callable $fn)
    {
        $this->acquireLock($key);
        try {
            return $fn();
        } finally {
            $this->releaseLock($key);
        }
    }

    /* --------------------------------------------------------------------
     * PROBABILISTIC GARBAGE COLLECTION
     * ------------------------------------------------------------------ */

    /**
     * Probabilistic garbage collection - randomly triggers on write operations.
     *
     * Probability: 1 in 10,000 (0.01%)
     * Override in concrete drivers if they need different GC behavior.
     */
    protected function maybeGarbageCollect(): void
    {
        // Disabled by default - drivers should implement if needed
    }

    /* --------------------------------------------------------------------
     * PUBLIC APCU-LIKE OPERATIONS
     * ------------------------------------------------------------------ */

    /** apcu_fetch */
    public function fetch(mixed $key, bool &$success = null): mixed
    {
        if (is_array($key)) {
            $out     = [];
            $success = true;
            foreach ($key as $k) {
                $s = false;
                $v = $this->fetch($k, $s);
                if ($s) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }

        $success = false;
        $found   = false;
        $raw     = $this->_fetch($key, $found);
        if (!$found || $raw === null) {
            return false;
        }

        $expired   = false;
        $expiresAt = null;
        $value     = $this->unpackValue($raw, $expired, $expiresAt);

        if ($expired) {
            $this->_delete($key);
            return false;
        }

        $success = true;
        return $value;
    }

    /** apcu_add */
    public function add(string|array $key, mixed $var = null, int $ttl = 0): array|bool
    {
        if (is_array($key)) {
            $errors = [];
            foreach ($key as $k => $v) {
                if (!$this->add($k, $v, $ttl)) {
                    $errors[] = $k;
                }
            }
            return $errors ?: true;
        }

        $expiresAt  = $this->computeExpiresAt($ttl);
        $payload    = $this->packValue($var, $expiresAt);
        $backendTtl = $this->backendTtl($expiresAt);

        return $this->_add($key, $payload, $backendTtl);
    }

    /** apcu_store */
    public function store(string|array $keys, mixed $var = null, int $ttl = 0): bool|array
    {
        if (is_array($keys)) {
            $errors = [];
            foreach ($keys as $k => $v) {
                if (!$this->store($k, $v, $ttl)) {
                    $errors[] = $k;
                }
            }
            return $errors ?: true;
        }

        $expiresAt  = $this->computeExpiresAt($ttl);
        $payload    = $this->packValue($var, $expiresAt);
        $backendTtl = $this->backendTtl($expiresAt);

        $result = $this->_store($keys, $payload, $backendTtl);

        // Probabilistic GC on writes
        $this->maybeGarbageCollect();

        return $result;
    }

    /** apcu_delete */
    public function delete(mixed $key): mixed
    {
        if (is_array($key)) {
            $failed = [];
            foreach ($key as $k) {
                if (!$this->_delete($k)) {
                    $failed[] = $k;
                }
            }
            return $failed;
        }

        return $this->_delete($key);
    }

    /** apcu_exists */
    public function exists(string|array $keys): array|bool
    {
        if (is_array($keys)) {
            $out = [];
            foreach ($keys as $k) {
                $s = false;
                $this->fetch($k, $s); // obeys TTL
                if ($s) {
                    $out[$k] = true;
                }
            }
            return $out;
        }

        $s = false;
        $this->fetch($keys, $s);
        return $s;
    }

    /** apcu_entry */
    public function entry(string $key, callable $callback, int $ttl = 0): mixed
    {
        return $this->withLock($key, function () use ($key, $callback, $ttl) {
            $s   = false;
            $val = $this->fetch($key, $s);
            if ($s) {
                return $val;
            }

            $val       = $callback();
            $expiresAt = $this->computeExpiresAt($ttl);
            $payload   = $this->packValue($val, $expiresAt);
            $backendTtl = $this->backendTtl($expiresAt);

            $this->_store($key, $payload, $backendTtl);
            return $val;
        });
    }

    /** apcu_cas */
    public function cas(string $key, int $old, int $new): bool
    {
        return $this->withLock($key, function () use ($key, $old, $new) {
            $found = false;
            $raw   = $this->_fetch($key, $found);
            if (!$found || $raw === null) {
                return false;
            }

            $expired   = false;
            $expiresAt = null;
            $value     = $this->unpackValue($raw, $expired, $expiresAt);

            if ($expired) {
                $this->_delete($key);
                return false;
            }

            if (!is_int($value) || $value !== $old) {
                return false;
            }

            // Preserve TTL (expiresAt) exactly
            $payload    = $this->packValue($new, $expiresAt);
            $backendTtl = $this->backendTtl($expiresAt);

            return $this->_store($key, $payload, $backendTtl);
        });
    }

    /** apcu_inc */
    public function inc(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false
    {
        return $this->withLock($key, function () use ($key, $step, $ttl, &$success) {
            $success = false;

            $found = false;
            $raw   = $this->_fetch($key, $found);

            if (!$found || $raw === null) {
                // missing → create new with provided TTL
                $value     = $step;
                $expiresAt = $this->computeExpiresAt($ttl);
                $payload   = $this->packValue($value, $expiresAt);
                $backendTtl = $this->backendTtl($expiresAt);

                $success = $this->_store($key, $payload, $backendTtl);
                return $success ? $value : false;
            }

            $expired   = false;
            $expiresAt = null;
            $current   = $this->unpackValue($raw, $expired, $expiresAt);

            if ($expired) {
                $this->_delete($key);
                // treat as missing and create with provided TTL
                $value     = $step;
                $expiresAt = $this->computeExpiresAt($ttl);
                $payload   = $this->packValue($value, $expiresAt);
                $backendTtl = $this->backendTtl($expiresAt);

                $success = $this->_store($key, $payload, $backendTtl);
                return $success ? $value : false;
            }

            if (!is_int($current)) {
                return false;
            }

            $value      = $current + $step;
            // IMPORTANT: preserve existing expiresAt
            $payload    = $this->packValue($value, $expiresAt);
            $backendTtl = $this->backendTtl($expiresAt);

            $success = $this->_store($key, $payload, $backendTtl);
            return $success ? $value : false;
        });
    }

    /** apcu_dec */
    public function dec(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false
    {
        return $this->inc($key, -$step, $success, $ttl);
    }

    /** apcu_key_info (minimal) */
    public function key_info(string $key): ?array
    {
        $found = false;
        $raw   = $this->_fetch($key, $found);
        if (!$found || $raw === null) {
            return null;
        }

        $expired   = false;
        $expiresAt = null;
        $value     = $this->unpackValue($raw, $expired, $expiresAt);
        if ($expired) {
            $this->_delete($key);
            return null;
        }

        return [
            'key'        => $key,
            'value_type' => gettype($value),
            'ttl'        => $expiresAt !== null ? max(0, $expiresAt - time()) : 0,
        ];
    }
}
