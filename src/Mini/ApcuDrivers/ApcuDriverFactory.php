<?php
namespace mini\Mini\ApcuDrivers;

use mini\Mini;

class ApcuDriverFactory {

    private static ?ApcuDriverInterface $instance = null;

    private static function isPathWritable(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (file_exists($path)) {
            return is_writable($path);
        }

        $dir = dirname($path);
        return is_dir($dir) && is_writable($dir);
    }

    public static function getDriver(): ApcuDriverInterface {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (extension_loaded('swoole')) {
            $size = (int)($_ENV['MINI_APCU_SWOOLE_SIZE'] ?? 4096);
            $valueSize = (int)($_ENV['MINI_APCU_SWOOLE_VALUE_SIZE'] ?? 4096);
            return self::$instance = new SwooleTableApcuDriver($size, $valueSize);
        } elseif (extension_loaded('pdo_sqlite')) {
            $appRoot = Mini::$mini->root;
            $uid = function_exists('posix_getuid') ? (string)posix_getuid() : (string)getmyuid();
            $hash = substr(md5($appRoot . '|' . $uid), 0, 8);
            $paths = [];
            $envPath = $_ENV['MINI_APCU_SQLITE_PATH'] ?? null;

            if ($envPath !== null) {
                $paths[] = $envPath;
            } else {
                if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
                    $paths[] = "/dev/shm/apcu_mini_{$hash}.sqlite";
                }
                $paths[] = sys_get_temp_dir() . "/apcu_mini_{$hash}.sqlite";
            }

            foreach ($paths as $path) {
                if (!self::isPathWritable($path)) {
                    continue;
                }
                try {
                    return self::$instance = new PDOSqlite3ApcuDriver($path);
                } catch (\Throwable $e) {
                    // If the SQLite backend cannot initialize, fall back.
                }
            }
        }
        return self::$instance = new ArrayApcuDriver();
    }
}
