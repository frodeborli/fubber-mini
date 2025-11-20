<?php
namespace mini\Mini\ApcuDrivers;

use mini\Mini;

class ApcuDriverFactory {

    private static ?ApcuDriverInterface $instance = null;

    public static function getDriver(): ApcuDriverInterface {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (extension_loaded('swoole')) {
            $size = (int)($_ENV['MINI_APCU_SWOOLE_SIZE'] ?? 4096);
            $valueSize = (int)($_ENV['MINI_APCU_SWOOLE_VALUE_SIZE'] ?? 4096);
            return self::$instance = new SwooleTableApcuDriver($size, $valueSize);
        } elseif (extension_loaded('pdo_sqlite')) {
            $path = $_ENV['MINI_APCU_SQLITE_PATH'] ?? null;

            if ($path === null) {
                $appRoot = Mini::$mini->root;
                $hash = substr(md5($appRoot), 0, 8);

                if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
                    $path = "/dev/shm/apcu_mini_{$hash}.sqlite";
                } else {
                    $path = sys_get_temp_dir() . "/apcu_mini_{$hash}.sqlite";
                }
            }

            return self::$instance = new PDOSqlite3ApcuDriver($path);
        }
        return self::$instance = new ArrayApcuDriver();
    }
}