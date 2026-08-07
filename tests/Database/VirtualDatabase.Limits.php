<?php
/**
 * Regression tests: mini\Database\Limits is wired into VirtualDatabase
 *
 * The engine runs *sensible* SQL over heterogeneous sources. A pathological
 * query must fail immediately with an error naming the limit and how to raise
 * it, rather than consume the PHP process - which under a Fiber-based runtime
 * takes every other coroutine in the worker with it.
 *
 * Before this, the boundaries existed but were unreachable magic numbers: the
 * join cap was a literal 8 duplicated across two copies of the routing logic,
 * the recursive-CTE cap a local `$maxIterations = 10000`, and maxSubqueryDepth
 * was enforced nowhere at all. `setMaxMaterializedRows()` was a second, separate
 * source of truth for the write cap.
 *
 * Each limit is asserted three ways: the boundary value is allowed, one past it
 * throws, and raising the limit makes the previously-rejected query run.
 *
 * Cross-checked against sqlite3: the recursive fixpoint queries below return
 * the same rows there
 * (`WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n+1 FROM c WHERE n < 5)`
 * yields 1..5). SQLite imposes no comparable caps - it is a native engine with
 * its own memory management - so the limits themselves are Mini's own contract,
 * not a portability claim.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\Limits;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    /** A VDB with t1..t12, each holding a single row id=1 */
    private function joinVdb(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();

        for ($i = 1; $i <= 12; $i++) {
            $t = new InMemoryTable(
                new ColumnDef('id', ColumnType::Int, IndexType::Primary),
                new ColumnDef('v', ColumnType::Int),
            );
            $t->insert(['id' => 1, 'v' => $i]);
            $vdb->registerTable("t$i", $t);
        }

        return $vdb;
    }

    /** A query joining $n tables; the WHERE selects the predicate-pushdown planner */
    private function joinSql(int $n): string
    {
        $sql = 'SELECT t1.v FROM t1';
        for ($i = 2; $i <= $n; $i++) {
            $sql .= " JOIN t$i ON t$i.id = t1.id";
        }
        return $sql . ' WHERE t1.id = 1';
    }

    /**
     * A query joining $n tables, one per join spelling that routes AWAY from the
     * predicate-pushdown planner and onto the plain nested-loop path.
     *
     * The cap used to sit inside the `pushdown` branch only, so all of these ran
     * uncapped - on the slower of the two paths. A 9-table LEFT JOIN with a WHERE
     * produced 1.6M rows in 20s under a cap that claimed 8.
     *
     * @return array<string,array{string,int}> label => [SQL, expected COUNT(*)]
     */
    private function unpushedJoinSqls(int $n): array
    {
        $inner = $left = $using = $natural = $cross = 'SELECT COUNT(*) AS c FROM t1';
        $comma = 'SELECT COUNT(*) AS c FROM t1';

        for ($i = 2; $i <= $n; $i++) {
            $inner   .= " JOIN t$i ON t$i.id = t1.id";        // no WHERE
            $left    .= " LEFT JOIN t$i ON t$i.id = t1.id";   // outer join
            $using   .= " JOIN t$i USING (id)";               // common-column join
            $natural .= " NATURAL JOIN t$i";
            $cross   .= " CROSS JOIN t$i";
            $comma   .= ", t$i";
        }

        // NATURAL JOIN over (id, v) equates *both* common columns, and every t$i
        // holds a distinct v, so it matches nothing - verified against sqlite3,
        // which also returns 0. The count is incidental here; what matters is
        // that the query is allowed to run at all.
        return [
            'inner join, no WHERE' => [$inner, 1],
            'left join + WHERE'    => [$left . ' WHERE t1.id = 1', 1],
            'using join + WHERE'   => [$using . ' WHERE id = 1', 1],
            'natural join + WHERE' => [$natural . ' WHERE id = 1', 0],
            'cross join'           => [$cross, 1],
            'comma-separated FROM' => [$comma, 1],
        ];
    }

    /** A SELECT wrapped in $levels derived tables, so the AST nests $levels + 1 deep */
    private function nestSql(int $levels): string
    {
        $sql = 'SELECT id, v FROM t1';
        for ($i = 1; $i <= $levels; $i++) {
            $sql = "SELECT id, v FROM ($sql) a$i";
        }
        return $sql;
    }

    private function writeVdb(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();
        $vdb->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n INTEGER)');
        $vdb->exec('INSERT INTO t (id, n) VALUES (1, 1), (2, 2), (3, 3)');
        return $vdb;
    }

    // ---------------------------------------------------------------- defaults

    public function testDefaultLimitsAreExposed(): void
    {
        $limits = (new VirtualDatabase())->getLimits();

        $this->assertSame(8, $limits->maxJoinedTables);
        $this->assertSame(8, $limits->maxSubqueryDepth);
        $this->assertSame(10000, $limits->maxRecursionIterations);
        $this->assertSame(1000000, $limits->maxBufferedWrites);
    }

    public function testLimitsRejectNonsenseValues(): void
    {
        $this->assertThrows(
            fn() => new Limits(maxJoinedTables: 0),
            \InvalidArgumentException::class
        );
        $this->assertThrows(
            fn() => new Limits(maxSubqueryDepth: -1),
            \InvalidArgumentException::class
        );
        $this->assertThrows(
            fn() => new Limits(maxRecursionIterations: 0),
            \InvalidArgumentException::class
        );
        $this->assertThrows(
            fn() => new Limits(maxBufferedWrites: 0),
            \InvalidArgumentException::class
        );

        // null is the documented way to disable the write cap, not an error
        $this->assertNull((new Limits(maxBufferedWrites: null))->maxBufferedWrites);
    }

    // --------------------------------------------------------- maxJoinedTables

    public function testJoinedTablesAtTheLimitIsAllowed(): void
    {
        $vdb = $this->joinVdb();
        $rows = iterator_to_array($vdb->query($this->joinSql(8)));
        $this->assertCount(1, $rows);
    }

    public function testJoinedTablesPastTheLimitThrowsActionableError(): void
    {
        $vdb = $this->joinVdb();

        try {
            iterator_to_array($vdb->query($this->joinSql(9)));
            $this->fail('Expected maxJoinedTables to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxJoinedTables', $e->getMessage());
            $this->assertStringContainsString('9 tables', $e->getMessage());
            $this->assertStringContainsString('limit of 8', $e->getMessage());
            $this->assertStringContainsString('setLimits', $e->getMessage());
        }
    }

    public function testRaisingMaxJoinedTablesPermitsTheQuery(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxJoinedTables: 9));

        $rows = iterator_to_array($vdb->query($this->joinSql(9)));
        $this->assertCount(1, $rows);
    }

    public function testLoweringMaxJoinedTablesRejectsAPreviouslyLegalQuery(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxJoinedTables: 2));

        $this->assertThrows(
            fn() => iterator_to_array($vdb->query($this->joinSql(3))),
            \RuntimeException::class
        );
    }

    public function testJoinedTablesLimitAppliesToEveryJoinStrategy(): void
    {
        $vdb = $this->joinVdb();

        foreach ($this->unpushedJoinSqls(9) as $label => [$sql, $_]) {
            try {
                iterator_to_array($vdb->query($sql));
                $this->fail("maxJoinedTables not enforced for: $label");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('maxJoinedTables', $e->getMessage(), $label);
                $this->assertStringContainsString('9 tables', $e->getMessage(), $label);
            }
        }
    }

    public function testJoinedTablesLimitIsCheckedBeforeAnyRowsAreRead(): void
    {
        // 12 single-row tables cross-joined is only 1 row, but the planner has to
        // build 12 nested loops to find that out. The cap must reject the query on
        // the AST, before execution - a check that only fires while streaming would
        // be no protection at all.
        $vdb = $this->joinVdb();

        $start = microtime(true);
        $this->assertThrows(
            fn() => iterator_to_array($vdb->query(
                'SELECT COUNT(*) AS c FROM t1' . implode('', array_map(
                    fn($i) => " CROSS JOIN t$i",
                    range(2, 12)
                ))
            )),
            \RuntimeException::class
        );
        $this->assertTrue(microtime(true) - $start < 1.0, 'limit check should be immediate');
    }

    public function testJoinedTablesAtTheLimitIsAllowedOnEveryJoinStrategy(): void
    {
        $vdb = $this->joinVdb();

        foreach ($this->unpushedJoinSqls(8) as $label => [$sql, $expected]) {
            $rows = iterator_to_array($vdb->query($sql));
            $this->assertSame($expected, $rows[0]->c, $label);
        }
    }

    public function testRaisingMaxJoinedTablesPermitsEveryJoinStrategy(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxJoinedTables: 9));

        foreach ($this->unpushedJoinSqls(9) as $label => [$sql, $expected]) {
            $rows = iterator_to_array($vdb->query($sql));
            $this->assertSame($expected, $rows[0]->c, $label);
        }
    }

    public function testJoinedTablesLimitAppliesInsideSubqueries(): void
    {
        $vdb = $this->joinVdb();

        $inner = 'SELECT t1.id FROM t1' . implode('', array_map(
            fn($i) => " JOIN t$i ON t$i.id = t1.id",
            range(2, 9)
        ));

        $this->assertThrows(
            fn() => iterator_to_array($vdb->query("SELECT v FROM t1 WHERE id IN ($inner)")),
            \RuntimeException::class
        );
    }

    // -------------------------------------------------------- maxSubqueryDepth

    public function testSubqueryNestingAtTheLimitIsAllowed(): void
    {
        $vdb = $this->joinVdb();

        // 7 derived tables around the base SELECT = 8 levels, exactly the limit
        $rows = iterator_to_array($vdb->query($this->nestSql(7)));
        $this->assertCount(1, $rows);
    }

    public function testSubqueryNestingPastTheLimitThrowsActionableError(): void
    {
        $vdb = $this->joinVdb();

        try {
            iterator_to_array($vdb->query($this->nestSql(8)));
            $this->fail('Expected maxSubqueryDepth to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxSubqueryDepth', $e->getMessage());
            $this->assertStringContainsString('9 levels deep', $e->getMessage());
            $this->assertStringContainsString('limit of 8', $e->getMessage());
            $this->assertStringContainsString('setLimits', $e->getMessage());
        }
    }

    public function testRaisingMaxSubqueryDepthPermitsTheQuery(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxSubqueryDepth: 9));

        $rows = iterator_to_array($vdb->query($this->nestSql(8)));
        $this->assertCount(1, $rows);
    }

    public function testSubqueryDepthCountsPredicateSubqueriesToo(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxSubqueryDepth: 2));

        // Depth 2: outer SELECT + one IN subquery
        $rows = iterator_to_array($vdb->query('SELECT v FROM t1 WHERE id IN (SELECT id FROM t2)'));
        $this->assertCount(1, $rows);

        // Depth 3
        $this->assertThrows(
            fn() => iterator_to_array($vdb->query(
                'SELECT v FROM t1 WHERE id IN (SELECT id FROM t2 WHERE id IN (SELECT id FROM t3))'
            )),
            \RuntimeException::class
        );
    }

    public function testSubqueryDepthCountsCteBodies(): void
    {
        $vdb = $this->joinVdb();
        $vdb->setLimits(new Limits(maxSubqueryDepth: 2));

        // WITH at depth 1, its CTE body at depth 2
        $rows = iterator_to_array($vdb->query('WITH c AS (SELECT id, v FROM t1) SELECT v FROM c'));
        $this->assertCount(1, $rows);

        // A derived table inside the CTE body puts it at depth 3
        $this->assertThrows(
            fn() => iterator_to_array($vdb->query(
                'WITH c AS (SELECT id, v FROM (SELECT id, v FROM t1) x) SELECT v FROM c'
            )),
            \RuntimeException::class
        );
    }

    public function testSubqueryDepthIsEnforcedOnWritesToo(): void
    {
        $vdb = $this->writeVdb();
        $vdb->setLimits(new Limits(maxSubqueryDepth: 2));

        try {
            $vdb->exec('DELETE FROM t WHERE id IN (SELECT id FROM (SELECT id FROM t) x)');
            $this->fail('Expected maxSubqueryDepth to be enforced for exec()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxSubqueryDepth', $e->getMessage());
        }

        // The statement was rejected before touching anything
        $this->assertCount(3, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    // -------------------------------------------------- maxRecursionIterations

    public function testRecursionAtTheLimitIsAllowed(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->setLimits(new Limits(maxRecursionIterations: 5));

        // Anchor {1}, then four productive iterations and a fifth that produces
        // nothing: the fixpoint lands exactly on the last permitted iteration.
        $rows = iterator_to_array($vdb->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT n FROM c'
        ));

        $this->assertSame([1, 2, 3, 4, 5], array_map(fn($r) => $r->n, $rows));
    }

    public function testRecursionPastTheLimitThrowsActionableError(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->setLimits(new Limits(maxRecursionIterations: 5));

        try {
            iterator_to_array($vdb->query(
                'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 6) SELECT n FROM c'
            ));
            $this->fail('Expected maxRecursionIterations to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxRecursionIterations', $e->getMessage());
            $this->assertStringContainsString('5 iterations', $e->getMessage());
            $this->assertStringContainsString('setLimits', $e->getMessage());
        }
    }

    public function testBreachMessagesNeverSuggestTheValueThatJustFailed(): void
    {
        // The recursion message used to interpolate the limit that had just been
        // exceeded: at maxRecursionIterations=5 it advised setting it to 5, which
        // fails identically. Advice that reproduces the error is not advice.
        $vdb = new VirtualDatabase();
        $vdb->setLimits(new Limits(maxRecursionIterations: 5));

        try {
            iterator_to_array($vdb->query(
                'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 6) SELECT n FROM c'
            ));
            $this->fail('Expected maxRecursionIterations to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxRecursionIterations: ...', $e->getMessage());
            $this->assertStringNotContainsString('maxRecursionIterations: 5', $e->getMessage());
        }

        // The join message does name a number - but one that works: the table
        // count, which is by construction above the limit that rejected it.
        $joinVdb = $this->joinVdb();
        try {
            iterator_to_array($joinVdb->query($this->joinSql(9)));
            $this->fail('Expected maxJoinedTables to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxJoinedTables: 9', $e->getMessage());
        }
        $joinVdb->setLimits(new Limits(maxJoinedTables: 9));
        $this->assertCount(1, iterator_to_array($joinVdb->query($this->joinSql(9))));
    }

    public function testRaisingMaxRecursionIterationsPermitsTheQuery(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->setLimits(new Limits(maxRecursionIterations: 20));

        $rows = iterator_to_array($vdb->query(
            'WITH RECURSIVE c(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 6) SELECT n FROM c'
        ));

        $this->assertSame([1, 2, 3, 4, 5, 6], array_map(fn($r) => $r->n, $rows));
    }

    // ------------------------------------------------------- maxBufferedWrites

    public function testMaxBufferedWritesIsEnforcedThroughLimits(): void
    {
        $vdb = $this->writeVdb();
        $vdb->setLimits(new Limits(maxBufferedWrites: 2));

        try {
            $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t');
            $this->fail('Expected maxBufferedWrites to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('maxBufferedWrites', $e->getMessage());
        }

        $this->assertCount(3, iterator_to_array($vdb->query('SELECT id FROM t')));
    }

    public function testMaxBufferedWritesAtTheLimitIsAllowed(): void
    {
        $vdb = $this->writeVdb();
        $vdb->setLimits(new Limits(maxBufferedWrites: 3));

        $this->assertSame(3, $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t'));
    }

    // ------------------------------------------------- one source of truth

    public function testSetMaxMaterializedRowsUpdatesTheLimitsObject(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->setMaxMaterializedRows(7);

        $this->assertSame(7, $vdb->getLimits()->maxBufferedWrites);

        // ...and leaves the other limits alone
        $this->assertSame(8, $vdb->getLimits()->maxJoinedTables);
        $this->assertSame(10000, $vdb->getLimits()->maxRecursionIterations);

        $vdb->setMaxMaterializedRows(null);
        $this->assertNull($vdb->getLimits()->maxBufferedWrites);
    }

    public function testSetMaxMaterializedRowsPreservesLimitsSetEarlier(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->setLimits(new Limits(maxJoinedTables: 3, maxSubqueryDepth: 4, maxRecursionIterations: 5));
        $vdb->setMaxMaterializedRows(9);

        $limits = $vdb->getLimits();
        $this->assertSame(3, $limits->maxJoinedTables);
        $this->assertSame(4, $limits->maxSubqueryDepth);
        $this->assertSame(5, $limits->maxRecursionIterations);
        $this->assertSame(9, $limits->maxBufferedWrites);
    }

    public function testSetLimitsIsVisibleThroughTheLegacyWriteCap(): void
    {
        $vdb = $this->writeVdb();
        $vdb->setMaxMaterializedRows(2);
        $vdb->setLimits(new Limits(maxBufferedWrites: null));

        // setLimits wins: there is one cap, not two
        $this->assertSame(3, $vdb->exec('INSERT INTO t (id, n) SELECT id + 100, n FROM t'));
    }
};

exit($test->run());
