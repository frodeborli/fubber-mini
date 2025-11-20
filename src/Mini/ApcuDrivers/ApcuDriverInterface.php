<?php
namespace mini\Mini\ApcuDrivers;

interface ApcuDriverInterface {
    /**
     * apcu_add(string $key, mixed $var, int $ttl = 0): bool
     * apcu_add(array $values, mixed $unused = NULL, int $ttl = 0): array
     */
    public function add(string|array $key, mixed $var = null, int $ttl = 0): array|bool;

    /**
     * apcu_cache_info(bool $limited = false): array|false
     */
    public function info(bool $limited = false): array|false;

    /**
     * apcu_cas(string $key, int $old, int $new): bool
     * 
     * @param string $key 
     * @param int $old 
     * @param int $new 
     * @return bool 
     */
    public function cas(string $key, int $old, int $new): bool;

    /**
     * apcu_clear_cache(): bool
     * 
     * @return bool 
     */
    public function clear_cache(): bool;

    /**
     * apcu_dec(string $key, int $step = 1, bool &$success = ?, int $ttl = 0): int|false
     * @param string $key 
     * @param int $step 
     * @return mixed 
     */
    public function dec(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false;

    /**
     * apcu_delete(mixed $key): mixed
     * 
     * @param string|string[] $key A key used to store the value as a string for a single key, or as an array of strings for several keys.
     * @return mixed If key is an array, an indexed array of the keys is returned. Otherwise true is returned on success, or false on failure.
     */
    public function delete(mixed $key): mixed;

    /**
     * apcu_enabled(): bool
     * 
     * @return bool 
     */
    public function enabled(): bool;

    /**
     * apcu_entry(string $key, callable $callback, int $ttl = 0): mixed
     * 
     * @param string $key 
     * @param callable $callback 
     * @param int $ttl 
     * @return mixed 
     */
    public function entry(string $key, callable $callback, int $ttl = 0): mixed;

    /**
     * apcu_exists(string|array $keys): bool|array
     * 
     * @param string|array $keys 
     * @return array|bool 
     */
    public function exists(string|array $keys): array|bool;

    /**
     * apcu_fetch(mixed $key, bool &$success = ?): mixed
     * 
     * @param mixed $key 
     * @param bool|null &$success 
     * @return mixed 
     */
    public function fetch(mixed $key, bool &$success = null): mixed;

    /**
     * apcu_inc(string $key, int $step = 1, bool &$success = ?, int $ttl = 0): int|false
     * 
     * @param string $key 
     * @param int $step 
     * @param bool|null &$success 
     * @param int $ttl 
     * @return int|false 
     */
    public function inc(string $key, int $step = 1, bool &$success = null, int $ttl = 0): int|false;

    /**
     * apcu_key_info(string $key): ?array
     * 
     * @param string $key 
     * @return null|array 
     */
    public function key_info(string $key): ?array;

    /**
     * apcu_sma_info(bool $limited = false): array|false
     * 
     * @param bool $limited 
     * @return array|false 
     */
    public function sma_info(bool $limited = false): array|false;

    /**
     * apcu_store(string $key, mixed $var, int $ttl = 0): bool
     * apcu_store(array $values, mixed $unused = NULL, int $ttl = 0): array
     *
     * @param string|array $keys
     * @param mixed|null $var
     * @param int $ttl
     * @return bool|array
     */
    public function store(string|array $keys, mixed $var = null, int $ttl = 0): bool|array;
}
