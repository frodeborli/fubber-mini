<?php
/**
 * PDODatabase under phasync: one connection per scope
 *
 * The invariants: each \Scope (a request, under Swerve) has a connection of its own, opened when it
 * first needs one; the statements of one scope use that one; another scope never sees it (its
 * temporary tables, its uncommitted transaction); a connection goes when its scope does; work in a
 * scope of its own beside a request (a background coroutine, a service) has a connection of its own.
 *
 * Needs phasync (a dev dependency, for its \Scope) and a database file several connections can open.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\DatabaseInterface;
use mini\Mini;
use mini\Test;

$test = new class extends Test {

    private string $file;

    protected function canRun(): bool
    {
        return \class_exists(\phasync::class) && \class_exists(\Scope::class);
    }

    protected function skipReason(): string
    {
        return 'phasync (and its Scope) is not installed';
    }

    protected function setUp(): void
    {
        $this->file = \sys_get_temp_dir() . '/mini-scoped-' . \getmypid() . '.sqlite3';
        @\unlink($this->file);
        $_ENV['MINI_DATABASE_URL'] = 'sqlite:///' . $this->file;
        \mini\bootstrap();
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    private function db(): DatabaseInterface
    {
        return Mini::$mini->get(DatabaseInterface::class);
    }

    /** A temporary table: it belongs to the connection that made it */
    private function sees(DatabaseInterface $db, string $name): bool
    {
        return (int) $db->queryField("SELECT COUNT(*) FROM sqlite_temp_master WHERE name = ?", [$name]) > 0;
    }

    public function testAScopeKeepsItsConnection(): void
    {
        $seen = \phasync::run(fn () => (new \Scope())->run(function () {
            $this->db()->exec('CREATE TEMP TABLE mine (x INTEGER)');

            return $this->sees($this->db(), 'mine');
        }));
        $this->assertTrue($seen, "a scope's second statement did not see what its first made");
    }

    public function testAnotherScopeHasAnotherConnection(): void
    {
        $seen = \phasync::run(function () {
            (new \Scope())->run(fn () => $this->db()->exec('CREATE TEMP TABLE theirs (x INTEGER)'));

            return (new \Scope())->run(fn () => $this->sees($this->db(), 'theirs'));
        });
        $this->assertFalse($seen, "a scope saw another scope's connection");
    }

    public function testAnUncommittedTransactionIsItsScopesAlone(): void
    {
        $seen = \phasync::run(function () {
            $seen = [];
            // (closures, not arrow functions: those would capture $seen by value)
            $a = \phasync::go(function () use (&$seen) {
                (new \Scope())->run(function () use (&$seen) {
                    $this->db()->transaction(function () use (&$seen) {
                        $this->db()->exec("INSERT INTO t (name) VALUES ('uncommitted')");
                        $seen['a inside'] = (int) $this->db()->queryField("SELECT COUNT(*) FROM t WHERE name = 'uncommitted'");
                        \phasync::sleep(0.05);
                    });
                });
            });
            $b = \phasync::go(function () use (&$seen) {
                (new \Scope())->run(function () use (&$seen) {
                    \phasync::sleep(0.01);
                    $seen['b meanwhile'] = (int) $this->db()->queryField("SELECT COUNT(*) FROM t WHERE name = 'uncommitted'");
                });
            });
            \phasync::await($a);
            \phasync::await($b);

            return $seen;
        });
        $this->assertSame(1, $seen['a inside']);
        $this->assertSame(0, $seen['b meanwhile']);
    }

    public function testAConnectionGoesWithItsScope(): void
    {
        $gone = \phasync::run(function () {
            $ref = (new \Scope())->run(fn () => \WeakReference::create($this->db()->getPdo()));
            \gc_collect_cycles();

            return $ref->get() === null;
        });
        $this->assertTrue($gone, "a scope's connection outlived its scope");
    }

    public function testAFailedTransactionLeavesNothingOpen(): void
    {
        $after = \phasync::run(fn () => (new \Scope())->run(function () {
            try {
                $this->db()->transaction(function () {
                    $this->db()->exec("INSERT INTO t (name) VALUES ('rolled back')");
                    throw new \RuntimeException('fails');
                });
            } catch (\RuntimeException) {
            }

            // (the same connection, not in a transaction any more: one can begin)
            return $this->db()->transaction(fn () => (int) $this->db()->queryField("SELECT COUNT(*) FROM t WHERE name = 'rolled back'"));
        }));
        $this->assertSame(0, $after);
    }

    public function testInsertReturnsItsOwnId(): void
    {
        [$a, $b] = \phasync::run(fn () => (new \Scope())->run(function () {
            return [$this->db()->insert('t', ['name' => 'x']), $this->db()->insert('t', ['name' => 'y'])];
        }));
        $this->assertTrue((int) $b === (int) $a + 1, "ids $a, $b");
    }

    public function testATransactionThatReadsThenWritesIsNotRefused(): void
    {
        // (begun deferred, a transaction that read and then writes is refused at once, "database is locked" with no
        // wait, when another connection wrote in between; begun IMMEDIATE it holds the write lock from the start, and
        // the other connection waits for it instead)
        $r = \phasync::run(function () {
            $out = ['a' => null, 'b' => null];
            $a = \phasync::go(function () use (&$out) {
                (new \Scope())->run(function () use (&$out) {
                    try {
                        $this->db()->transaction(function () {
                            $this->db()->queryField('SELECT COUNT(*) FROM t');
                            \phasync::sleep(0.2);   // (b writes meanwhile, or waits to)
                            $this->db()->exec("INSERT INTO t (name) VALUES ('a')");
                        });
                        $out['a'] = 'committed';
                    } catch (\Throwable $e) {
                        $out['a'] = $e->getMessage();
                    }
                });
            });
            $b = \phasync::go(function () use (&$out) {
                (new \Scope())->run(function () use (&$out) {
                    \phasync::sleep(0.05);
                    try {
                        $this->db()->exec('PRAGMA busy_timeout = 3000');
                        $this->db()->exec("INSERT INTO t (name) VALUES ('b')");
                        $out['b'] = 'committed';
                    } catch (\Throwable $e) {
                        $out['b'] = $e->getMessage();
                    }
                });
            });
            \phasync::await($a);
            \phasync::await($b);

            return $out;
        });
        $this->assertSame(['a' => 'committed', 'b' => 'committed'], $r);
    }
};

exit($test->run());
