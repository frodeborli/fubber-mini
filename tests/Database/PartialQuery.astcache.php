<?php
/**
 * Regression tests: the SQL parser's AST cache must never be poisoned
 *
 * SqlParser::parseCached() keeps a reference to every AST it returns, so an
 * AST handed to a PartialQuery is ALWAYS shared - even on a cache miss.
 * PartialQuery::ensureAST() previously marked a freshly parsed AST as
 * privately owned, so the FIRST query to compose on a given SQL string
 * mutated the cached AST in place. Every later query with that same SQL
 * string in the same process then started from the poisoned AST.
 *
 * The failure was invisible in ordinary tests: later mutators saw
 * $wasCached = true and deep-cloned correctly, so the cache froze at
 * whatever the first mutator happened to write, and suites only failed
 * order-dependently (which is exactly how the bug survived).
 *
 * These tests assert the invariant directly: composing a query must never
 * be observable by a later query built from the same SQL string. This
 * matters most for long-running processes - the Fiber-based runtimes Mini
 * targets - where the parser cache lives for the life of the worker.
 *
 * Each test uses a distinct SQL string so that it is the first mutator of
 * that string within this process, which is the only condition under which
 * the bug reproduces.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;

$test = new class extends Test {

    protected function setUp(): void
    {
        \mini\bootstrap();
        \mini\db()->exec('DROP TABLE IF EXISTS astcache');
        \mini\db()->exec('CREATE TABLE astcache (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
        \mini\db()->exec("INSERT INTO astcache (id, name, status) VALUES
            (1,'a','active'),(2,'b','active'),(3,'c','inactive'),
            (4,'d','active'),(5,'e','inactive')");
    }

    public function testLimitDoesNotLeakIntoLaterQuery(): void
    {
        $sql = 'SELECT * FROM astcache WHERE id > 0';

        // First-ever composition on this SQL string mutates its AST
        $limited = \mini\db()->query($sql)->limit(2);
        $this->assertStringContainsString('LIMIT', (string) $limited);

        // A fresh query from the same string must be unaffected
        $fresh = \mini\db()->query($sql);
        $this->assertStringNotContainsString('LIMIT', (string) $fresh);
        $this->assertCount(5, iterator_to_array($fresh));
    }

    public function testOffsetDoesNotLeakIntoLaterQuery(): void
    {
        $sql = 'SELECT * FROM astcache ORDER BY id';

        // Asserted on the rendered SQL rather than by executing: offset()
        // without limit() renders a bare OFFSET, which SQLite rejects.
        // That is a separate defect - this test is about cache isolation.
        $offset = \mini\db()->query($sql)->offset(3);
        $this->assertStringContainsString('OFFSET', (string) $offset);

        $fresh = \mini\db()->query($sql);
        $this->assertStringNotContainsString('OFFSET', (string) $fresh);
        $this->assertCount(5, iterator_to_array($fresh));
    }

    public function testLimitOffsetWindowDoesNotLeakIntoLaterQuery(): void
    {
        $sql = 'SELECT * FROM astcache WHERE id < 6';

        $window = \mini\db()->query($sql)->limit(10)->offset(3);
        $this->assertCount(2, iterator_to_array($window));

        $fresh = \mini\db()->query($sql);
        $this->assertCount(5, iterator_to_array($fresh));
    }

    public function testFilterDoesNotLeakIntoLaterQuery(): void
    {
        $sql = 'SELECT id, name, status FROM astcache';

        $filtered = \mini\db()->query($sql)->eq('status', 'inactive');
        $this->assertCount(2, iterator_to_array($filtered));

        // The added predicate must not persist into a new query
        $fresh = \mini\db()->query($sql);
        $this->assertCount(5, iterator_to_array($fresh));
    }

    public function testBoundParamsDoNotLeakIntoLaterQuery(): void
    {
        // Binding params mutates the AST too - the first binder must not
        // freeze its own literal values into the shared cache.
        $sql = 'SELECT * FROM astcache WHERE status = ?';

        $active = \mini\db()->query($sql, ['active']);
        $this->assertCount(3, iterator_to_array($active));

        $inactive = \mini\db()->query($sql, ['inactive']);
        $this->assertCount(2, iterator_to_array($inactive));

        // And again, to prove neither call poisoned the other
        $activeAgain = \mini\db()->query($sql, ['active']);
        $this->assertCount(3, iterator_to_array($activeAgain));
    }

    public function testRepeatedCompositionIsIndependent(): void
    {
        $sql = 'SELECT * FROM astcache WHERE id >= 1';

        $a = \mini\db()->query($sql)->limit(1);
        $b = \mini\db()->query($sql)->limit(4);
        $c = \mini\db()->query($sql);

        $this->assertCount(1, iterator_to_array($a));
        $this->assertCount(4, iterator_to_array($b));
        $this->assertCount(5, iterator_to_array($c));
    }
};

exit($test->run());
