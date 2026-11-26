<?php
/**
 * The SQLite APCu polyfill under phasync: coroutines of one process use it at once
 *
 * The invariant: concurrent operations never share a connection. ext-phasync refuses a
 * statement on a connection another coroutine's statement still holds ("Concurrent operations
 * on the same resource are not supported"); with one connection per process, a burst of
 * requests (a page of thumbnails) failed with that LogicException now and then: while another
 * process (another worker) holds the file's write lock, a statement waits, and its coroutine
 * yields, still holding the connection. Here a connection of its own holds the lock now and then.
 *
 * Needs phasync with its extension (a dev dependency): run it as
 *   vendor/bin/phasync tests/Mini/ApcuSqliteConcurrent.php
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Mini\ApcuDrivers\PDOSqlite3ApcuDriver;
use mini\Test;

$test = new class extends Test {

    private string $file;

    protected function canRun(): bool
    {
        return \class_exists(\phasync::class) && \extension_loaded('phasync');
    }

    protected function skipReason(): string
    {
        return 'needs phasync and its extension (vendor/bin/phasync)';
    }

    protected function setUp(): void
    {
        $this->file = \sys_get_temp_dir() . '/mini-apcu-concurrent-' . \getmypid() . '.sqlite';
        @\unlink($this->file);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $s) {
            @\unlink($this->file . $s);
        }
    }

    public function testCoroutinesUseTheCacheAtOnce(): void
    {
        $driver = new PDOSqlite3ApcuDriver($this->file);
        $errors = [];
        $sum    = \phasync::run(function () use ($driver, &$errors) {
            $all  = [];
            $busy = true;
            $lock = \phasync::go(function () use (&$busy) {
                $other = new \PDO('sqlite:' . $this->file);
                while ($busy) {
                    $other->exec('BEGIN IMMEDIATE');
                    \phasync::sleep(0.01);
                    $other->exec('COMMIT');
                    \phasync::sleep(0.005);
                }
            });
            for ($c = 0; $c < 32; $c++) {
                $all[] = \phasync::go(function () use ($driver, $c, &$errors) {
                    $n = 0;
                    try {
                        for ($i = 0; $i < 40; $i++) {
                            $n += $driver->entry("k" . ($i % 5), fn () => 1, 60);
                            $driver->store("c$c.$i", $i);
                            $n += $driver->fetch("c$c.$i") === $i ? 0 : 1000;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = \get_class($e) . ': ' . $e->getMessage();
                    }

                    return $n;
                });
            }
            $sum = 0;
            foreach ($all as $f) {
                $sum += \phasync::await($f);
            }
            $busy = false;
            \phasync::await($lock);

            return $sum;
        });
        $this->assertSame([], \array_unique($errors));
        $this->assertSame(32 * 40, $sum);
    }
};

exit($test->run());
