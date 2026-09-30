<?php
/**
 * PDODatabase under phasync: statements borrow connections from the process's pool
 *
 * The invariants: coroutines never share a connection (one's uncommitted transaction is
 * invisible to another); a transaction keeps one connection for its coroutine, whose own
 * statements use it; no more connections than MINI_DATABASE_POOL_SIZE exist, so a statement
 * waits while all are held; lastInsertId() is the calling coroutine's.
 *
 * Needs phasync (a dev dependency) and a database file several connections can open.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\DatabaseInterface;
use mini\Mini;
use mini\Test;

$test = new class extends Test {

    private string $file;

    protected function canRun(): bool
    {
        return \class_exists(\phasync\Util\Pool::class);
    }

    protected function skipReason(): string
    {
        return 'phasync is not installed';
    }

    protected function setUp(): void
    {
        $this->file = \sys_get_temp_dir() . '/mini-pooled-' . \getmypid() . '.sqlite3';
        @\unlink($this->file);
        $_ENV['MINI_DATABASE_URL']       = 'sqlite:///' . $this->file;
        $_ENV['MINI_DATABASE_POOL_SIZE'] = '2';
        \mini\bootstrap();
        $pdo = new PDO('sqlite:' . $this->file);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    private function db(): DatabaseInterface
    {
        return Mini::$mini->get(DatabaseInterface::class);
    }

    public function testAnUncommittedTransactionIsItsCoroutinesAlone(): void
    {
        $seen = \phasync::run(function () {
            $db   = $this->db();
            $seen = [];
            $a    = \phasync::go(function () use ($db, &$seen) {
                $db->transaction(function () use ($db, &$seen) {
                    $db->exec("INSERT INTO t (name) VALUES ('uncommitted')");
                    $seen['a inside'] = (int) $db->queryField("SELECT COUNT(*) FROM t WHERE name = 'uncommitted'");
                    \phasync::sleep(0.05);
                });
            });
            \phasync::sleep(0.01);
            $seen['b meanwhile'] = (int) $db->queryField("SELECT COUNT(*) FROM t WHERE name = 'uncommitted'");
            \phasync::await($a);
            $seen['b after'] = (int) $db->queryField("SELECT COUNT(*) FROM t WHERE name = 'uncommitted'");

            return $seen;
        });
        $this->assertSame(['a inside' => 1, 'b meanwhile' => 0, 'b after' => 1], $seen);
    }

    public function testAStatementWaitsWhileEveryConnectionIsHeld(): void
    {
        $log = \phasync::run(function () {
            $db  = $this->db();
            $log = [];
            foreach (['first', 'second'] as $name) {
                \phasync::go(function () use ($db, $name, &$log) {
                    $db->transaction(function () use ($name, &$log) {
                        \phasync::sleep(0.1);
                        $log[] = "$name committed";
                    });
                });
            }
            \phasync::sleep(0.01);
            $db->queryField('SELECT 1'); // both connections are in transactions: waits
            $log[] = 'query ran';

            return $log;
        });
        // It waited for a connection to come free: after one transaction committed at least
        $this->assertSame('query ran', \end($log));
        $this->assertStringEndsWith('committed', $log[0]);
    }

    public function testLastInsertIdIsTheCallingCoroutines(): void
    {
        $ids = \phasync::run(function () {
            $db     = $this->db();
            $fibers = [];
            foreach (['x', 'y'] as $name) {
                $fibers[$name] = \phasync::go(function () use ($db, $name) {
                    $db->exec('INSERT INTO t (name) VALUES (?)', [$name]);
                    \phasync::sleep(0.02); // the other inserts meanwhile
                    $id = $db->lastInsertId();

                    return [$id, $db->queryField('SELECT name FROM t WHERE id = ?', [$id])];
                });
            }

            return \array_map(static fn ($f) => \phasync::await($f)[1], $fibers);
        });
        $this->assertSame(['x' => 'x', 'y' => 'y'], $ids);
    }

    public function testInsertReturnsItsOwnId(): void
    {
        $name = \phasync::run(function () {
            $db = $this->db();
            $id = $db->insert('t', ['name' => 'inserted']);

            return $db->queryField('SELECT name FROM t WHERE id = ?', [$id]);
        });
        $this->assertSame('inserted', $name);
    }

    public function testQueryResultsCanBeIteratedWhileRunningOtherStatements(): void
    {
        $names = \phasync::run(function () {
            $db = $this->db();
            $db->exec("INSERT INTO t (name) VALUES ('one'), ('two'), ('three')");
            $names = [];
            foreach ($db->query("SELECT id, name FROM t WHERE name IN ('one', 'two', 'three') ORDER BY id") as $row) {
                // A statement per row: must not wait for the connection the iteration holds
                $names[] = $db->queryField('SELECT name FROM t WHERE id = ?', [$row->id]);
            }

            return $names;
        });
        $this->assertSame(['one', 'two', 'three'], $names);
    }

    public function testGetPdoInATransactionIsTheTransactionsConnection(): void
    {
        $count = \phasync::run(function () {
            $db = $this->db();

            return $db->transaction(function () use ($db) {
                $db->exec("INSERT INTO t (name) VALUES ('via getPdo')");

                return (int) $db->getPdo()->query("SELECT COUNT(*) FROM t WHERE name = 'via getPdo'")->fetchColumn();
            });
        });
        $this->assertSame(1, $count);
    }
};

exit($test->run());
