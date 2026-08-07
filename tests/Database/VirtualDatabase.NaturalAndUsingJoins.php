<?php
/**
 * Regression tests: NATURAL JOIN and JOIN ... USING (SQL:2003 7.7 <joined table>)
 *
 * Both spellings used to die in the parser with "Expected ON after JOIN".
 * They need no new evaluation semantics - a USING join is an equi-join on the
 * named columns, and a NATURAL join is a USING join over every column the two
 * operands have in common - but they do need the *column merge*: a join column
 * is exposed once, not once per operand, and its value is the coalesce of both
 * sides (which only shows for the outer variants, where one side is
 * null-extended).
 *
 * Every expected result below was cross-checked against sqlite3 3.45.1. There
 * are two deliberate deviations, and both are pinned by tests:
 *
 * 1. A NATURAL JOIN with no columns in common is a silent cartesian product in
 *    SQLite and the standard, and an error here. The join keys of a NATURAL
 *    JOIN are invisible in the query text, so the cross product arrives with no
 *    syntactic warning at all - Mini fails fast and tells the caller to write
 *    CROSS JOIN if that is what they meant.
 *
 * 2. A merged join column has no qualified spelling. SQLite and PostgreSQL
 *    keep `users.name` and `orders.name` addressable next to the merged
 *    `name`, each yielding its own operand's value; this engine rejects both.
 *    See the block of tests under "A merged join column has no qualified
 *    spelling" for why answering with the coalesced value instead is a silent
 *    wrong answer, and what the guard has to cover.
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
     * users(id, name) / orders(id, user_id, name)
     *
     * `name` is the only useful common column; `id` is common too but means
     * something different on each side, which is exactly the trap NATURAL JOIN
     * is famous for - and which the NATURAL tests below pin down.
     */
    private function createVdb(): VirtualDatabase
    {
        $users = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $users->insert(['id' => 1, 'name' => 'Alice']);
        $users->insert(['id' => 2, 'name' => 'Bob']);

        $orders = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('name', ColumnType::Text),
        );
        $orders->insert(['id' => 10, 'user_id' => 1, 'name' => 'Alice']);
        $orders->insert(['id' => 11, 'user_id' => 2, 'name' => 'Zed']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $users);
        $vdb->registerTable('orders', $orders);
        return $vdb;
    }

    /**
     * emp/dept/sal - a clean star to exercise chained and aggregated joins.
     */
    private function createStarVdb(): VirtualDatabase
    {
        $dept = new InMemoryTable(
            new ColumnDef('dept_id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('dept', ColumnType::Text),
        );
        $dept->insert(['dept_id' => 1, 'dept' => 'Eng']);
        $dept->insert(['dept_id' => 2, 'dept' => 'Sales']);

        $emp = new InMemoryTable(
            new ColumnDef('emp_id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('dept_id', ColumnType::Int),
            new ColumnDef('name', ColumnType::Text),
        );
        $emp->insert(['emp_id' => 1, 'dept_id' => 1, 'name' => 'Alice']);
        $emp->insert(['emp_id' => 2, 'dept_id' => 1, 'name' => 'Bob']);
        $emp->insert(['emp_id' => 3, 'dept_id' => 2, 'name' => 'Cara']);

        $sal = new InMemoryTable(
            new ColumnDef('emp_id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('amount', ColumnType::Int),
        );
        $sal->insert(['emp_id' => 1, 'amount' => 100]);
        $sal->insert(['emp_id' => 2, 'amount' => 200]);
        $sal->insert(['emp_id' => 3, 'amount' => 300]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('dept', $dept);
        $vdb->registerTable('emp', $emp);
        $vdb->registerTable('sal', $sal);
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

    // ─── USING ───────────────────────────────────────────────────────────

    public function testUsingJoinsOnTheNamedColumn(): void
    {
        $vdb = $this->createVdb();

        // sqlite3: `select * from users join orders using(name)` -> 1 row
        $rows = $this->rows($vdb, 'SELECT * FROM users JOIN orders USING (name)');

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['users.id']);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame(10, $rows[0]['orders.id']);
    }

    public function testUsingColumnIsExposedOnce(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->rows($vdb, 'SELECT * FROM users JOIN orders USING (name)');

        // sqlite3 exposes id, name, id, user_id - four columns, `name` once.
        // The merged column belongs to neither operand and carries the bare
        // name, which is also the label sqlite3 prints for it.
        $this->assertSame(
            ['users.id', 'name', 'orders.id', 'orders.user_id'],
            array_keys($rows[0])
        );
        $this->assertFalse(
            array_key_exists('orders.name', $rows[0]),
            'the right operand copy of a USING column must not be exposed'
        );
        $this->assertFalse(
            array_key_exists('users.name', $rows[0]),
            'the left operand copy of a USING column must not be exposed either'
        );
    }

    public function testUsingColumnIsUnambiguousUnqualified(): void
    {
        $vdb = $this->createVdb();

        // Without the merge this is "Ambiguous column name: name"
        $rows = $this->rows($vdb, 'SELECT name FROM users u JOIN orders o USING (name)');

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
    }

    public function testUsingColumnIsUnambiguousInWhere(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->rows(
            $vdb,
            "SELECT u.id FROM users u JOIN orders o USING (name) WHERE name = 'Alice'"
        );

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testUsingWithSeveralColumns(): void
    {
        $vdb = $this->createStarVdb();

        // emp and sal share only emp_id, so USING(emp_id) and the two-column
        // form below must agree with the equivalent ON join.
        $rows = $this->rows(
            $vdb,
            'SELECT * FROM emp JOIN dept USING (dept_id) ORDER BY emp_id'
        );
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['emp.emp_id', 'dept_id', 'emp.name', 'dept.dept'],
            array_keys($rows[0])
        );

        // Two-column USING: joining emp to itself on both keys keeps 3 rows
        $rows = $this->rows(
            $vdb,
            'SELECT COUNT(*) AS c FROM emp JOIN emp e2 USING (emp_id, dept_id)'
        );
        $this->assertSame(3, $rows[0]['c']);
    }

    public function testUsingChainsAcrossThreeTables(): void
    {
        $vdb = $this->createStarVdb();

        // sqlite3: 1|1|Alice|Eng|100 / 2|1|Bob|Eng|200 / 3|2|Cara|Sales|300
        $rows = $this->rows(
            $vdb,
            'SELECT * FROM emp JOIN dept USING (dept_id) JOIN sal USING (emp_id) ORDER BY emp_id'
        );

        $this->assertCount(3, $rows);
        // sqlite3 labels these emp_id|dept_id|name|dept|amount
        $this->assertSame(
            ['emp_id', 'dept_id', 'emp.name', 'dept.dept', 'sal.amount'],
            array_keys($rows[0])
        );
        $this->assertSame(100, $rows[0]['sal.amount']);
        $this->assertSame('Sales', $rows[2]['dept.dept']);
    }

    public function testUsingWithDerivedTable(): void
    {
        $vdb = $this->createStarVdb();

        $rows = $this->rows(
            $vdb,
            'SELECT * FROM emp JOIN (SELECT dept_id, dept FROM dept) d USING (dept_id) ORDER BY emp_id'
        );

        $this->assertCount(3, $rows);
        $this->assertSame(['emp.emp_id', 'dept_id', 'emp.name', 'd.dept'], array_keys($rows[0]));
    }

    public function testUsingWithAggregateAndGroupBy(): void
    {
        $vdb = $this->createStarVdb();

        // sqlite3: Eng|2|300 / Sales|1|300
        $rows = $this->rows(
            $vdb,
            'SELECT dept, COUNT(*) AS n, SUM(amount) AS total '
            . 'FROM emp JOIN dept USING (dept_id) JOIN sal USING (emp_id) '
            . 'GROUP BY dept ORDER BY dept'
        );

        $this->assertCount(2, $rows);
        $this->assertSame(['dept' => 'Eng', 'n' => 2, 'total' => 300], $rows[0]);
        $this->assertSame(['dept' => 'Sales', 'n' => 1, 'total' => 300], $rows[1]);
    }

    // ─── USING with the outer variants ───────────────────────────────────

    public function testLeftJoinUsingKeepsUnmatchedLeftRows(): void
    {
        $vdb = $this->createVdb();

        // sqlite3: 1|Alice|10|1 and 2|Bob|NULL|NULL
        $rows = $this->rows($vdb, 'SELECT * FROM users LEFT JOIN orders USING (name)');

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertNull($rows[1]['orders.id']);
    }

    public function testRightJoinUsingCoalescesTheJoinColumn(): void
    {
        $vdb = $this->createVdb();

        // sqlite3: 1|Alice|10|1 and NULL|Zed|11|2 - the join column of the
        // unmatched right row carries the RIGHT operand's value, which is
        // exactly why the merged column cannot be filed under either
        // operand's name.
        $rows = $this->rows($vdb, 'SELECT * FROM users RIGHT JOIN orders USING (name)');

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertNull($rows[1]['users.id']);
        $this->assertSame('Zed', $rows[1]['name']);
        $this->assertSame(11, $rows[1]['orders.id']);
    }

    public function testFullJoinUsingCoalescesBothDirections(): void
    {
        $vdb = $this->createVdb();

        // sqlite3: 1|Alice|10|1 / 2|Bob|NULL|NULL / NULL|Zed|11|2
        $rows = $this->rows($vdb, 'SELECT * FROM users FULL JOIN orders USING (name)');

        $this->assertCount(3, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
        $this->assertNull($rows[1]['orders.id']);
        $this->assertSame('Zed', $rows[2]['name']);
        $this->assertNull($rows[2]['users.id']);
    }

    // ─── A merged join column has no qualified spelling ───────────────────
    //
    // These are the tests that hold down the deviation from SQLite and
    // PostgreSQL. There, `users.name` and `orders.name` stay addressable next
    // to the merged `name` and yield each operand's *own* value - which on an
    // unmatched row of an outer join is NULL on the null-extended side, not
    // the coalesced value. This engine builds join rows out of qualified
    // column names and cannot carry a column that `SELECT *` must not show,
    // so the qualified spellings are rejected instead of answered with the
    // coalesce. Every clause must reject them the same way: an identifier
    // that lies in one clause and throws in another is worse than either.

    /**
     * The exact query that used to answer 'Bob' where sqlite3 answers NULL.
     */
    public function testQualifiedRightSideOfAMergedColumnIsRejectedInSelect(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT users.id, orders.name FROM users LEFT JOIN orders USING (name)');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("Column 'orders.name' is not available", $message);
        $this->assertStringContainsString("'name'", $message);
    }

    /**
     * The standard unmatched-row idiom must not silently invert.
     *
     * sqlite3 reports matched/unmatched here; answering 'matched' for both
     * rows - which is what coalescing into the merged column does - is the
     * kind of wrong answer that never gets noticed.
     */
    public function testQualifiedMergedColumnIsRejectedInsideCase(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows(
                $vdb,
                "SELECT CASE WHEN orders.name IS NULL THEN 'unmatched' ELSE 'matched' END AS state "
                . 'FROM users LEFT JOIN orders USING (name)'
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("Column 'orders.name' is not available", $message);
    }

    /**
     * Nested in a function call in WHERE: sqlite3 returns zero rows for this
     * (orders.name is NULL on the unmatched row), the merged value returns one.
     */
    public function testQualifiedMergedColumnIsRejectedInWhereExpression(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows(
                $vdb,
                "SELECT users.id FROM users LEFT JOIN orders USING (name) WHERE UPPER(orders.name) = 'BOB'"
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("Column 'orders.name' is not available", $message);
    }

    /**
     * Symmetrical: after a RIGHT JOIN it is the LEFT operand that is
     * null-extended, so `users.name` is the one sqlite3 answers NULL for.
     */
    public function testQualifiedLeftSideOfAMergedColumnIsRejectedAfterRightJoin(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT users.name FROM users RIGHT JOIN orders USING (name)');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("Column 'users.name' is not available", $message);
    }

    /**
     * Same identifier, every clause, same failure - including the two clauses
     * that resolve columns through the table backend rather than the row
     * evaluator (WHERE pushdown throws LogicException on its own, ORDER BY
     * throws its own RuntimeException) and the two that run after grouping.
     */
    public function testQualifiedMergedColumnIsRejectedUniformlyInEveryClause(): void
    {
        $vdb = $this->createVdb();

        $queries = [
            'SELECT orders.name FROM users LEFT JOIN orders USING (name)',
            'SELECT users.id FROM users LEFT JOIN orders USING (name) WHERE orders.name IS NULL',
            'SELECT COUNT(*) AS c FROM users LEFT JOIN orders USING (name) GROUP BY orders.name',
            'SELECT COUNT(*) AS c FROM users LEFT JOIN orders USING (name) '
                . "GROUP BY name HAVING MAX(orders.name) = 'Bob'",
            'SELECT users.id FROM users LEFT JOIN orders USING (name) ORDER BY orders.name',
            'SELECT users.id FROM users LEFT JOIN orders USING (name) '
                . 'WHERE EXISTS (SELECT 1 FROM orders o2 WHERE o2.id = orders.name)',
        ];

        foreach ($queries as $sql) {
            $message = '';
            try {
                $this->rows($vdb, $sql);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertStringContainsString(
                "Column 'orders.name' is not available",
                $message,
                "clause did not reject the merged column: $sql"
            );
        }
    }

    /**
     * Unquoted SQL identifiers are case-insensitive, and so is the rejection.
     *
     * A case-sensitive ban list is a bypass, not a nuisance: `ORDERS.name`
     * would slip past it and then resolve to the merged column by unqualified
     * name, which is the silent wrong answer the ban exists to prevent.
     */
    public function testQualifiedMergedColumnIsRejectedRegardlessOfCase(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT users.id, ORDERS.name FROM users LEFT JOIN orders USING (name)');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString("Column 'ORDERS.name' is not available", $message);
    }

    /**
     * The rejection is scoped to the join that merged the column: a subquery
     * with an `orders` of its own still resolves `orders.name` against it.
     */
    public function testSubqueryRebindingTheQualifierIsUnaffected(): void
    {
        $vdb = $this->createVdb();

        $rows = $this->rows(
            $vdb,
            'SELECT users.id FROM users LEFT JOIN orders USING (name) '
            . "WHERE (SELECT COUNT(*) FROM orders WHERE orders.name = 'Alice') > 0 ORDER BY users.id"
        );

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    /**
     * A non-merged column of either operand keeps its qualified spelling, so
     * the unmatched-row idiom is still expressible - just on a column that
     * belongs to exactly one side.
     */
    public function testNonMergedColumnsKeepTheirQualifiedNames(): void
    {
        $vdb = $this->createVdb();

        // sqlite3: `select users.id, case when orders.id is null then ...`
        //   -> 1|matched / 2|unmatched
        $rows = $this->rows(
            $vdb,
            "SELECT users.id AS uid, CASE WHEN orders.id IS NULL THEN 'unmatched' ELSE 'matched' END AS state "
            . 'FROM users LEFT JOIN orders USING (name) ORDER BY users.id'
        );

        $this->assertSame(
            [['uid' => 1, 'state' => 'matched'], ['uid' => 2, 'state' => 'unmatched']],
            $rows
        );
    }

    // ─── NATURAL ─────────────────────────────────────────────────────────

    public function testNaturalJoinUsesEveryCommonColumn(): void
    {
        $vdb = $this->createVdb();

        // users and orders share BOTH id and name, so the natural join is on
        // (id, name) and nothing matches. sqlite3 agrees: zero rows.
        $rows = $this->rows($vdb, 'SELECT * FROM users NATURAL JOIN orders');

        $this->assertCount(0, $rows);
    }

    public function testNaturalJoinMergesEveryCommonColumn(): void
    {
        $vdb = $this->createStarVdb();

        // sqlite3: 1|1|Alice|Eng|100 / 2|1|Bob|Eng|200 / 3|2|Cara|Sales|300
        $rows = $this->rows(
            $vdb,
            'SELECT * FROM emp NATURAL JOIN dept NATURAL JOIN sal ORDER BY emp_id'
        );

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['emp_id', 'dept_id', 'emp.name', 'dept.dept', 'sal.amount'],
            array_keys($rows[0])
        );
    }

    public function testNaturalJoinAgreesWithExplicitOnJoin(): void
    {
        $vdb = $this->createStarVdb();

        $natural = $this->rows(
            $vdb,
            'SELECT name, amount FROM emp NATURAL JOIN sal ORDER BY name'
        );
        $explicit = $this->rows(
            $vdb,
            'SELECT e.name AS name, s.amount AS amount FROM emp e JOIN sal s ON e.emp_id = s.emp_id ORDER BY e.name'
        );

        $this->assertSame($explicit, $natural);
    }

    public function testNaturalOuterJoin(): void
    {
        $vdb = $this->createVdb();

        // sqlite3 `select * from users natural left join orders`:
        //   1|Alice|NULL and 2|Bob|NULL (joined on id AND name, so no matches)
        $rows = $this->rows($vdb, 'SELECT * FROM users NATURAL LEFT JOIN orders ORDER BY id');

        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'name', 'orders.user_id'], array_keys($rows[0]));
        $this->assertNull($rows[0]['orders.user_id']);
        $this->assertNull($rows[1]['orders.user_id']);
    }

    public function testNaturalJoinWithNoCommonColumnsThrows(): void
    {
        $vdb = $this->createStarVdb();

        // dept(dept_id, dept) and sal(emp_id, amount) have nothing in common.
        // SQLite would silently return the 4-row cartesian product.
        //
        // The message matters as much as the throw: without the guard the
        // empty intersection falls through to the pre-existing
        // "INNER JOIN requires ON condition", which is a RuntimeException too.
        $message = '';
        try {
            $this->rows($vdb, 'SELECT * FROM dept NATURAL JOIN sal');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertStringContainsString('no columns in common', $message);
        $this->assertStringContainsString('CROSS JOIN', $message);
    }

    // ─── Errors and preserved spellings ──────────────────────────────────

    public function testUsingUnknownColumnThrows(): void
    {
        $vdb = $this->createVdb();

        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT * FROM users JOIN orders USING (nope)'),
            \RuntimeException::class
        );
    }

    public function testNaturalJoinRejectsOnAndUsing(): void
    {
        $vdb = $this->createVdb();

        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT * FROM users NATURAL JOIN orders ON users.id = orders.id'),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT * FROM users NATURAL JOIN orders USING (name)'),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT * FROM users NATURAL CROSS JOIN orders'),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
    }

    public function testQualifiedUsingColumnIsRejected(): void
    {
        $vdb = $this->createVdb();

        // SQL:2003 requires the USING list to be unqualified column names
        $message = '';
        try {
            $this->rows($vdb, 'SELECT * FROM users JOIN orders USING (users.name)');
        } catch (\mini\Parsing\SQL\SqlSyntaxException $e) {
            $message = $e->getMessage();
        }
        $this->assertStringContainsString('USING column names must be unqualified', $message);
    }

    public function testMissingJoinSpecificationStillThrows(): void
    {
        $vdb = $this->createVdb();

        $this->assertThrows(
            fn() => $this->rows($vdb, 'SELECT * FROM users JOIN orders'),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
    }

    public function testOnJoinsStillWork(): void
    {
        $vdb = $this->createVdb();

        // The existing spellings must be untouched by the new grammar
        $rows = $this->rows(
            $vdb,
            'SELECT * FROM users u JOIN orders o ON u.id = o.user_id ORDER BY u.id'
        );
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['u.id', 'u.name', 'o.id', 'o.user_id', 'o.name'],
            array_keys($rows[0])
        );

        $rows = $this->rows($vdb, 'SELECT * FROM users CROSS JOIN orders');
        $this->assertCount(4, $rows);
    }

    // ─── A CROSS JOIN takes no join specification ────────────────────────
    //
    // SQL:2003 7.7 gives <cross join> no <join specification>, and applyJoin()
    // agrees with it structurally: it builds a CrossJoinTable and returns
    // before it ever looks at the condition. The parser accepted both
    // spellings anyway, so `CROSS JOIN b USING (x)` and `CROSS JOIN b ON ...`
    // parsed, dropped the clause on the floor, and answered the cartesian
    // product to a query that asked for a join. sqlite3 answers 1 and 2 rows
    // respectively on this fixture; Mini answered 4 for both.
    //
    // Rejecting is deliberate rather than implementing it: SQLite treats CROSS
    // JOIN as an inner join with a planner hint, but a clean "write JOIN"
    // error costs the caller one word and cannot be misread.

    public function testCrossJoinWithUsingIsAParseError(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT * FROM users CROSS JOIN orders USING (name)');
        } catch (\mini\Parsing\SQL\SqlSyntaxException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('CROSS JOIN cannot have a USING clause', $message);
        $this->assertStringContainsString('JOIN ... USING', $message);
    }

    public function testCrossJoinWithOnIsAParseError(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT * FROM users CROSS JOIN orders ON users.id = orders.user_id');
        } catch (\mini\Parsing\SQL\SqlSyntaxException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('CROSS JOIN cannot have a ON clause', $message);
    }

    /**
     * The join the caller meant is still available one word away, and gives
     * the answer sqlite3 gives for `cross join ... using (name)`: 1 row.
     */
    public function testTheJoinTheCrossJoinMeantStillWorks(): void
    {
        $vdb = $this->createVdb();

        $this->assertCount(1, $this->rows($vdb, 'SELECT * FROM users JOIN orders USING (name)'));
        $this->assertCount(
            2,
            $this->rows($vdb, 'SELECT * FROM users JOIN orders ON users.id = orders.user_id')
        );
    }

    // ─── The merged-column ban is on the name, not on one spelling ───────

    /**
     * The round-1 silent wrong answer, still reachable after round 2.
     *
     * The ban list used to be keyed by the operands' *qualified* column names,
     * which are whatever the FROM clause introduced. Alias the operands and
     * the table names stop being on the list - and `users.name` then resolves
     * by its bare name straight onto the merged column, because
     * IdentifierNode::getName() drops the qualifier. sqlite3 rejects this
     * outright ("no such column: users.name"); Mini answered with the
     * coalesced value.
     */
    public function testTableNameQualifierIsRejectedWhenTheOperandIsAliased(): void
    {
        $vdb = $this->createVdb();

        foreach ([
            'SELECT users.name FROM users u LEFT JOIN orders o USING (name)',
            'SELECT orders.name FROM users u LEFT JOIN orders o USING (name)',
            "SELECT u.id FROM users u JOIN orders o USING (name) WHERE users.name = 'Alice'",
            'SELECT u.id FROM users u JOIN orders o USING (name) ORDER BY orders.name',
        ] as $sql) {
            $message = '';
            try {
                $this->rows($vdb, $sql);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertStringContainsString(
                'is not available after a NATURAL/USING join',
                $message,
                "a table-name qualifier slipped past the ban: $sql"
            );
        }
    }

    /**
     * Same hole from the other side: a qualifier that names nothing at all.
     * It used to resolve onto the merged column just as happily.
     */
    public function testUnknownQualifierOnAMergedNameIsRejected(): void
    {
        $vdb = $this->createVdb();

        $message = '';
        try {
            $this->rows($vdb, 'SELECT nosuchtable.name FROM users JOIN orders USING (name)');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString(
            "Column 'nosuchtable.name' is not available",
            $message
        );
    }

    public function testRoundTripsThroughTheRenderer(): void
    {
        $parser = new \mini\Parsing\SQL\SqlParser();
        $renderer = \mini\Parsing\SQL\SqlRenderer::forDialect();

        $sql = $renderer->render($parser->parse('SELECT * FROM users JOIN orders USING (name)'));
        $this->assertStringContainsString('USING (name)', $sql);

        $sql = $renderer->render($parser->parse('SELECT * FROM users NATURAL LEFT JOIN orders'));
        $this->assertStringContainsString('NATURAL LEFT JOIN', $sql);
    }
};

exit($test->run());
