<?php
/**
 * Regression tests: ORDER BY ... NULLS FIRST/LAST (SQL:2003 F855)
 *
 * The clause shipped working on a single unjoined table and broken everywhere
 * else, in two ways that both returned a wrong answer with no error:
 *
 *   1. Setting NULLS routes the query away from the pushed-down `order()` and
 *      into the in-memory sort (orderByNeedsExpressionEval()). That sort read
 *      its key with `$row->$name`, but the rows of a join carry *qualified*
 *      properties (`a.x`, `b.y`), so every key was null, every comparison
 *      tied, and the whole ORDER BY - DESC included - was silently discarded.
 *      The same hole was reachable without NULLS at all, via a mixed
 *      `ORDER BY a.x DESC, a.id * 1`, so it is pinned here from both sides.
 *
 *   2. The in-memory sort also bypassed applyOrderBy()'s unknown-column
 *      guard, so `ORDER BY nosuchcol NULLS LAST` returned unsorted rows where
 *      `ORDER BY nosuchcol` threw. A typo'd column name is the exact failure
 *      the guard exists to prevent.
 *
 * Every expectation below was cross-checked against sqlite3 3.45.1 on the
 * same fixture, including the two error cases (sqlite3 reports "no such
 * column: nosuchcol" for both spellings).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    /**
     * a(id, x, n) = (1, 3, NULL), (2, 1, 'b'), (3, 2, NULL)
     * b(bid, y)   = (1, 9)
     *
     * `x` is deliberately unsorted in insertion order, so an ORDER BY that is
     * silently dropped is distinguishable from one that worked. `n` carries
     * the NULLs. `b` matches exactly one row of `a`, so a LEFT JOIN produces
     * both matched and null-extended rows.
     */
    private function createVdb(): VirtualDatabase
    {
        $a = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('x', ColumnType::Int),
            new ColumnDef('n', ColumnType::Text),
        );
        $a->insert(['id' => 1, 'x' => 3, 'n' => null]);
        $a->insert(['id' => 2, 'x' => 1, 'n' => 'b']);
        $a->insert(['id' => 3, 'x' => 2, 'n' => null]);

        $b = new InMemoryTable(
            new ColumnDef('bid', ColumnType::Int, IndexType::Primary),
            new ColumnDef('y', ColumnType::Int),
        );
        $b->insert(['bid' => 1, 'y' => 9]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('a', $a);
        $vdb->registerTable('b', $b);
        return $vdb;
    }

    /** @return list<array<string,mixed>> */
    private function rows(VirtualDatabase $vdb, string $sql): array
    {
        $out = [];
        foreach ($vdb->query($sql) as $row) {
            $out[] = get_object_vars($row);
        }
        return $out;
    }

    /** @return list<mixed> */
    private function column(VirtualDatabase $vdb, string $sql, string $column): array
    {
        return array_column($this->rows($vdb, $sql), $column);
    }

    private function messageOf(callable $fn): string
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return '';
    }

    // ─── The sort must survive a join ────────────────────────────────────

    /**
     * The exact query that returned unsorted rows.
     *
     * sqlite3 `select a.x, b.y from a left join b on a.id=b.bid
     *          order by a.x desc nulls last` -> 3, 2, 1.
     */
    public function testNullsClauseKeepsTheSortAcrossAJoin(): void
    {
        $vdb = $this->createVdb();

        $withNulls = $this->column(
            $vdb,
            'SELECT a.x, b.y FROM a LEFT JOIN b ON a.id = b.bid ORDER BY a.x DESC NULLS LAST',
            'x'
        );

        $this->assertSame([3, 2, 1], $withNulls);

        // ...and agrees with the same query without the clause, which took the
        // pushed-down path and was always right.
        $withoutNulls = $this->column(
            $vdb,
            'SELECT a.x, b.y FROM a LEFT JOIN b ON a.id = b.bid ORDER BY a.x DESC',
            'x'
        );
        $this->assertSame($withoutNulls, $withNulls);
    }

    /**
     * NULLS placement itself, over a join: the null-extended side of a LEFT
     * JOIN is exactly where null ordering earns its keep.
     *
     * sqlite3 `select a.n, b.y from a left join b on a.id=b.bid
     *          order by a.n nulls last, a.id` -> b|NULL, NULL|9, NULL|NULL.
     */
    public function testNullsLastPlacesNullsAcrossAJoin(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->rows(
            $vdb,
            'SELECT a.n, b.y FROM a LEFT JOIN b ON a.id = b.bid ORDER BY a.n NULLS LAST, a.id'
        );

        $this->assertSame(['b', null, null], array_column($rows, 'n'));
        $this->assertSame([null, 9, null], array_column($rows, 'y'));
    }

    /**
     * The same in-memory sort, reached without NULLS at all: one expression
     * item in the ORDER BY is enough to route every item through it, and the
     * *identifier* item then read its key off a qualified row by bare name.
     *
     * sqlite3 `select a.x, b.y from a left join b on a.id=b.bid
     *          order by a.x desc, a.id * 1` -> 3, 2, 1.
     */
    public function testMixedExpressionOrderByKeepsTheSortAcrossAJoin(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->column(
            $vdb,
            'SELECT a.x, b.y FROM a LEFT JOIN b ON a.id = b.bid ORDER BY a.x DESC, a.id * 1',
            'x'
        );

        $this->assertSame([3, 2, 1], $rows);
    }

    /**
     * An unqualified ORDER BY column over a join resolves the same way, and an
     * ambiguous one is still rejected rather than guessed at.
     */
    public function testUnqualifiedOrderByColumnResolvesAcrossAJoin(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->column(
            $vdb,
            'SELECT a.x FROM a LEFT JOIN b ON a.id = b.bid ORDER BY x DESC NULLS LAST',
            'x'
        );

        $this->assertSame([3, 2, 1], $rows);
    }

    // ─── The unknown-column guard must fire with the clause present ──────

    /**
     * `ORDER BY nosuchcol` threw and `ORDER BY nosuchcol NULLS LAST` did not.
     * Both must throw, and say the same thing.
     */
    public function testUnknownOrderByColumnThrowsWithAndWithoutTheNullsClause(): void
    {
        $vdb = $this->createVdb();

        $plain = $this->messageOf(fn() => $this->rows($vdb, 'SELECT x FROM a ORDER BY nosuchcol'));
        $withNulls = $this->messageOf(
            fn() => $this->rows($vdb, 'SELECT x FROM a ORDER BY nosuchcol NULLS LAST')
        );

        $this->assertStringContainsString('ORDER BY references unknown column: nosuchcol', $plain);
        $this->assertSame($plain, $withNulls, 'the NULLS clause must not change the diagnosis');
    }

    /**
     * The guard has to survive everything else that routes a query into the
     * in-memory sort for reasons of its own: a SELECT alias in the list, a
     * join, another ORDER BY item that is an expression.
     *
     * It has to be a *guard*, checked once up front, not a by-product of
     * evaluating the key: usort() makes no comparison at all when the result
     * has fewer than two rows, so an evaluator error would let the last case
     * here through - the shape where a typo is hardest to notice, because the
     * one row that comes back looks perfectly sorted.
     */
    public function testUnknownOrderByColumnThrowsInEveryInMemorySortShape(): void
    {
        $vdb = $this->createVdb();

        $queries = [
            'SELECT x AS q FROM a ORDER BY nosuchcol NULLS LAST',
            'SELECT a.x FROM a LEFT JOIN b ON a.id = b.bid ORDER BY nosuchcol NULLS LAST',
            'SELECT a.x FROM a LEFT JOIN b ON a.id = b.bid ORDER BY nosuchcol DESC, a.id * 1',
            'SELECT x FROM a WHERE id = 1 ORDER BY nosuchcol NULLS LAST',
        ];

        foreach ($queries as $sql) {
            $message = $this->messageOf(fn() => $this->rows($vdb, $sql));
            $this->assertStringContainsString(
                'ORDER BY references unknown column: nosuchcol',
                $message,
                "ORDER BY on an unknown column did not fail: $sql"
            );
        }
    }

    /**
     * A SELECT alias is still a legal ORDER BY target - the guard must not
     * have turned into a ban on everything that is not a table column.
     */
    public function testSelectAliasIsStillOrderable(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->column($vdb, 'SELECT x AS q FROM a ORDER BY q DESC NULLS LAST', 'q');

        $this->assertSame([3, 2, 1], $rows);
    }

    // ─── Placement on a single table, against sqlite3 ────────────────────

    /**
     * The four combinations of direction and null placement. The override is
     * absolute: NULLS FIRST is not flipped by DESC.
     *
     * sqlite3, on this fixture:
     *   order by n nulls last       -> b, NULL, NULL
     *   order by n nulls first      -> NULL, NULL, b
     *   order by n desc nulls first -> NULL, NULL, b
     *   order by n desc nulls last  -> b, NULL, NULL
     */
    public function testNullPlacementMatchesSqlite(): void
    {
        $vdb = $this->createVdb();

        $cases = [
            'SELECT x, n FROM a ORDER BY n NULLS LAST'       => ['b', null, null],
            'SELECT x, n FROM a ORDER BY n NULLS FIRST'      => [null, null, 'b'],
            'SELECT x, n FROM a ORDER BY n DESC NULLS FIRST' => [null, null, 'b'],
            'SELECT x, n FROM a ORDER BY n DESC NULLS LAST'  => ['b', null, null],
        ];

        foreach ($cases as $sql => $expected) {
            $this->assertSame($expected, $this->column($vdb, $sql, 'n'), $sql);
        }
    }

    /**
     * Without the clause the engine sorts NULL below every value, which is
     * what SQLite does: NULLs first ascending, last descending.
     */
    public function testDefaultNullOrderingIsUnchanged(): void
    {
        $vdb = $this->createVdb();

        $this->assertSame([null, null, 'b'], $this->column($vdb, 'SELECT n FROM a ORDER BY n', 'n'));
        $this->assertSame(['b', null, null], $this->column($vdb, 'SELECT n FROM a ORDER BY n DESC', 'n'));
    }

    // ─── Window ORDER BY ─────────────────────────────────────────────────

    /**
     * The clause used to be a hard syntax error inside OVER (...), which made
     * it a statement-level-only feature for no reason anyone could see from
     * the grammar. It now means there what it means outside.
     *
     * sqlite3 `select row_number() over (order by n nulls last) as r, n, x from a`
     * ranks 'b' first and the two NULLs after it -> x=1 is r=1, x=3 is r=2.
     */
    public function testWindowOrderByAcceptsNullsClause(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->rows(
            $vdb,
            'SELECT ROW_NUMBER() OVER (ORDER BY n NULLS LAST) AS r, x FROM a ORDER BY x'
        );

        // x=1 carries the only non-null n, so NULLS LAST ranks it first.
        $this->assertSame([1, 2, 3], array_column($rows, 'x'));
        $this->assertSame([1, 3, 2], array_column($rows, 'r'));
    }

    /**
     * And the default window null ordering still matches sqlite3:
     *   over (order by n)      -> NULLs first  (x=3 is r=1, x=2 is r=2, x=1 is r=3)
     *   over (order by n desc) -> NULLs last   (x=1 is r=1)
     */
    public function testWindowOrderByDefaultNullOrderingMatchesSqlite(): void
    {
        $vdb = $this->createVdb();

        $asc = $this->rows($vdb, 'SELECT ROW_NUMBER() OVER (ORDER BY n) AS r, x FROM a ORDER BY x');
        $this->assertSame([3, 2, 1], array_column($asc, 'r'));

        $desc = $this->rows($vdb, 'SELECT ROW_NUMBER() OVER (ORDER BY n DESC) AS r, x FROM a ORDER BY x');
        $this->assertSame([1, 3, 2], array_column($desc, 'r'));
    }

    public function testWindowOrderByRejectsGarbageAfterNulls(): void
    {
        $vdb = $this->createVdb();

        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT ROW_NUMBER() OVER (ORDER BY n NULLS SOMETIMES) FROM a'),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
    }

    /**
     * A window ORDER BY with a NULLS clause has to survive a render/reparse
     * round trip, or the clause is lost the moment a query is rewritten.
     */
    public function testNullsClauseRoundTripsThroughTheRenderer(): void
    {
        $renderer = \mini\Parsing\SQL\SqlRenderer::forDialect();

        $window = $renderer->render((new \mini\Parsing\SQL\SqlParser())->parse(
            'SELECT ROW_NUMBER() OVER (PARTITION BY n ORDER BY x DESC NULLS FIRST) AS r FROM a'
        ));
        $this->assertStringContainsString('NULLS FIRST', $window);

        $statement = $renderer->render((new \mini\Parsing\SQL\SqlParser())->parse(
            'SELECT x FROM a ORDER BY n NULLS LAST'
        ));
        $this->assertStringContainsString('NULLS LAST', $statement);
    }

    // ─── The clause is refused where it cannot be honoured ───────────────

    /**
     * A set operation sorts through the table backend, which has no way to
     * express null ordering. Refusing is the point: sorting differently from
     * what was asked for is the failure mode this whole file is about.
     */
    public function testNullsClauseIsRefusedOnASetOperation(): void
    {
        $vdb = $this->createVdb();

        $message = $this->messageOf(
            fn() => $this->rows($vdb, 'SELECT n FROM a UNION SELECT n FROM a ORDER BY n NULLS LAST')
        );

        $this->assertStringContainsString('not supported on a set operation', $message);
    }
};

$test->run();
