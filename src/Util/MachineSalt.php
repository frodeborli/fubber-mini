<?php

namespace mini\Util;

/**
 * Generate a stable, machine-specific salt for cryptographic operations
 *
 * Combines system fingerprint with a persistent random salt for zero-config security.
 * The salt remains stable across requests and PHP restarts but is unique per machine.
 *
 * Used as fallback when MINI_SALT environment variable is not set.
 */
class MachineSalt
{
    /**
     * Get the machine salt (cached in memory after first call)
     *
     * @return string 64-character hex string (SHA-256 hash)
     */
    public static function get(): string
    {
        static $salt;
        if ($salt) {
            return $salt;
        }

        $fingerprint = self::getSystemFingerprint();
        $cached = self::getCachedSalt();
        $salt = hash('sha256', $fingerprint . $cached);

        return $salt;
    }

    /**
     * Generate system fingerprint from stable machine characteristics
     *
     * @return string SHA-256 hash of system identifiers
     */
    private static function getSystemFingerprint(): string
    {
        $parts = [
            @file_get_contents('/etc/machine-id') ?: '',
            php_uname('n'),  // Hostname
            php_uname('m'),  // Machine type
            php_uname('v'),  // Version info
            PHP_BINARY,      // PHP binary path
            phpversion(),    // PHP version
            __DIR__,         // Framework installation path
            getmyuid(),      // Process owner UID (per-user uniqueness)
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Get or create persistent random salt in temp directory
     *
     * @return string 64-character hex string
     */
    private static function getCachedSalt(): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mini_framework_salt.txt';

        if (!file_exists($file)) {
            $salt = bin2hex(random_bytes(32));
            @file_put_contents($file, $salt);
            return $salt;
        }

        return trim(@file_get_contents($file));
    }
}
