<?php
/**
 * Execution semantics for row values, IS DISTINCT FROM, null ordering and VALUES
 *
 * Every expected result in this file was cross-checked against sqlite3, which
 * implements all four (row values since 3.15, IS DISTINCT FROM, and NULLS
 * FIRST/LAST since 3.30).
 *
 * The NULL cases are the point of the exercise:
 *
 * - Row comparison is *not* the AND of its element comparisons. `(1, NULL) =
 *   (9, 2)` is FALSE, not UNKNOWN, because one pair is definitely unequal;
 *   `(1, NULL) = (1, 2)` is UNKNOWN. The ordering operators are lexicographic
 *   and stop at the first NULL that could still decide the answer.
 * - `IS DISTINCT FROM` is the one comparison that is never UNKNOWN, which is
 *   exactly why none of the VirtualDatabase pushdown paths may touch it: they
 *   all assume "compared against NULL" means "matches nothing".
 * - Null ordering: this engine sorts NULL below every value (SQLite's choice),
 *   so the default is NULLS FIRST ascending and NULLS LAST descending. The
 *   explicit clause overrides that and is *not* flipped by DESC.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\VirtualDatabase;
use mini\Table\ColumnDef;
use mini\Table\InMemoryTable;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Test;

$test = new class extends Test {

    private VirtualDatabase $vdb;

    protected function setUp(): void
    {
        // Contacts, with NULLs in every nullable column and one row that is
        // NULL in two columns at once.
        $contacts = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('email', ColumnType::Text),
        );
        $contacts->insert(['id' => 1, 'name' => 'Alice', 'email' => 'alice@test.com']);
        $contacts->insert(['id' => 2, 'name' => 'Bob', 'email' => null]);
        $contacts->insert(['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@test.com']);
        $contacts->insert(['id' => 4, 'name' => null, 'email' => null]);
        $contacts->insert(['id' => 5, 'name' => null, 'email' => 'unknown@test.com']);

        // Numeric fixture for lexicographic ordering and null ordering
        $pairs = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('a', ColumnType::Int),
            new ColumnDef('b', ColumnType::Text),
        );
        $pairs->insert(['id' => 1, 'a' => 1, 'b' => 'x']);
        $pairs->insert(['id' => 2, 'a' => 1, 'b' => 'y']);
        $pairs->insert(['id' => 3, 'a' => 2, 'b' => 'x']);
        $pairs->insert(['id' => 4, 'a' => null, 'b' => 'x']);
        $pairs->insert(['id' => 5, 'a' => 2, 'b' => null]);

        // Falsy values next to NULL. PHP's loose `==` equates null with 0 and
        // with '', so any implementation of IS DISTINCT FROM that leans on `!=`
        // gets these rows wrong while still passing every test above.
        $flags = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('v', ColumnType::Text),
        );
        $flags->insert(['id' => 1, 'v' => 0]);
        $flags->insert(['id' => 2, 'v' => null]);
        $flags->insert(['id' => 3, 'v' => 1]);
        $flags->insert(['id' => 4, 'v' => '']);

        $this->vdb = new VirtualDatabase();
        $this->vdb->registerTable('contacts', $contacts);
        $this->vdb->registerTable('pairs', $pairs);
        $this->vdb->registerTable('flags', $flags);
    }

    /** @return list<mixed> the first column of every result row */
    private function col(string $sql, array $params = []): array
    {
        $out = [];
        foreach ($this->vdb->query($sql, $params) as $row) {
            $values = get_object_vars($row);
            $out[] = reset($values);
        }
        return $out;
    }

    // =====================================================================
    // Row value constructors
    // =====================================================================

    public function testRowValueEquality(): void
    {
        $this->assertSame([1], $this->col("SELECT id FROM pairs WHERE (a, b) = (1, 'x')"));
    }

    /**
     * Row 4 is (NULL, 'x'): one pair UNKNOWN, the other equal, so the whole
     * comparison is UNKNOWN and the row belongs to neither = nor <>.
     * Row 5 is (2, NULL): the first pair is definitely unequal, so <> is TRUE
     * regardless of the NULL.
     */
    public function testRowValueInequalityUnderThreeValuedLogic(): void
    {
        $this->assertSame([2, 3, 5], $this->col("SELECT id FROM pairs WHERE (a, b) <> (1, 'x')"));
    }

    /**
     * The decisive pair *after* the NULL one.
     *
     * This is the case that separates the standard rule from the naive one.
     * Comparing against (1, 'y'):
     *
     * - row 4 is (NULL, 'x'): the first pair is UNKNOWN, but the second is
     *   definitely unequal, so `<>` is TRUE. An implementation that returns
     *   UNKNOWN at the first NULL pair drops this row.
     * - row 5 is (2, NULL): the first pair already decides, so the trailing
     *   NULL is irrelevant.
     *
     * Verified against sqlite3: rows 1, 3, 4, 5.
     */
    public function testRowValueInequalityDecidedAfterANullElement(): void
    {
        $this->assertSame(
            [1, 3, 4, 5],
            $this->col("SELECT id FROM pairs WHERE (a, b) <> (1, 'y')")
        );
        // The mirror: only the all-equal row matches =, everything else is
        // FALSE or UNKNOWN.
        $this->assertSame([2], $this->col("SELECT id FROM pairs WHERE (a, b) = (1, 'y')"));
    }

    public function testRowValueIn(): void
    {
        $this->assertSame([1, 3], $this->col("SELECT id FROM pairs WHERE (a, b) IN ((1, 'x'), (2, 'x'))"));
    }

    public function testRowValueNotIn(): void
    {
        $this->assertSame([2, 3, 5], $this->col("SELECT id FROM pairs WHERE (a, b) NOT IN ((1, 'x'))"));
    }

    public function testRowValueInSubquery(): void
    {
        $this->assertSame(
            [3],
            $this->col('SELECT id FROM pairs WHERE (a, b) IN (SELECT a, b FROM pairs WHERE id = 3)')
        );
    }

    /** Lexicographic: the first differing element decides, a NULL before it makes it UNKNOWN */
    public function testRowValueOrderingIsLexicographic(): void
    {
        $this->assertSame([1, 2], $this->col("SELECT id FROM pairs WHERE (a, b) < (2, 'x')"));
        $this->assertSame([3], $this->col("SELECT id FROM pairs WHERE (a, b) >= (2, 'x')"));
    }

    public function testRowValuePlaceholdersBind(): void
    {
        $this->assertSame(
            [1],
            $this->col('SELECT id FROM pairs WHERE (a, b) = (?, ?)', [1, 'x'])
        );
    }

    public function testRowValueDegreeMismatchThrows(): void
    {
        $this->assertThrows(
            fn() => $this->col("SELECT id FROM pairs WHERE (a, b) = (1, 'x', 2)"),
            \RuntimeException::class
        );
    }

    /** A row value is not a scalar - using it as one is an error, not a silent truncation */
    public function testRowValueOutsideAComparisonThrows(): void
    {
        $this->assertThrows(
            fn() => $this->col('SELECT (a, b) FROM pairs'),
            \RuntimeException::class
        );
    }

    // =====================================================================
    // IS [NOT] DISTINCT FROM
    // =====================================================================

    public function testIsDistinctFromTreatsNullAsAValue(): void
    {
        // email <> 'alice@test.com' would drop rows 2 and 4 (UNKNOWN); DISTINCT
        // FROM keeps them, because NULL is distinct from any non-NULL value.
        $this->assertSame(
            [2, 3, 4, 5],
            $this->col("SELECT id FROM contacts WHERE email IS DISTINCT FROM 'alice@test.com'")
        );
    }

    public function testIsNotDistinctFromNullFindsTheNullRows(): void
    {
        $this->assertSame(
            [2, 4],
            $this->col('SELECT id FROM contacts WHERE email IS NOT DISTINCT FROM NULL')
        );
    }

    public function testIsDistinctFromNullFindsTheNonNullRows(): void
    {
        $this->assertSame(
            [1, 3, 5],
            $this->col('SELECT id FROM contacts WHERE email IS DISTINCT FROM NULL')
        );
    }

    /** NULL IS DISTINCT FROM NULL is FALSE; NULL IS DISTINCT FROM 1 is TRUE */
    public function testNullAgainstNullIsNotDistinct(): void
    {
        $this->assertSame([0], $this->col('SELECT NULL IS DISTINCT FROM NULL AS d'));
        $this->assertSame([1], $this->col('SELECT NULL IS DISTINCT FROM 1 AS d'));
        $this->assertSame([1], $this->col('SELECT NULL IS NOT DISTINCT FROM NULL AS d'));
        $this->assertSame([0], $this->col('SELECT 1 IS DISTINCT FROM 1 AS d'));
    }

    /** The result is never UNKNOWN, so NOT is a true negation */
    public function testNotIsDistinctFrom(): void
    {
        $this->assertSame(
            [1],
            $this->col("SELECT id FROM contacts WHERE NOT (email IS DISTINCT FROM 'alice@test.com')")
        );
    }

    /** Two NULL columns compared to each other: distinct only where exactly one is NULL */
    public function testIsDistinctFromBetweenTwoNullableColumns(): void
    {
        $this->assertSame(
            [4],
            $this->col('SELECT id FROM contacts WHERE name IS NOT DISTINCT FROM email')
        );
    }

    /**
     * NULL versus the falsy values, where PHP's loose comparison disagrees with SQL.
     *
     * `null == 0` and `null == ''` are both TRUE in PHP, so an implementation
     * written as `$a != $b` reports NULL as *not* distinct from 0 — the exact
     * inversion of the SQL rule. Every expectation here was checked against
     * sqlite3.
     */
    public function testIsDistinctFromOverFalsyValuesAndNull(): void
    {
        // NULL (row 2) is distinct from 0; so are 1 and ''.
        $this->assertSame([2, 3, 4], $this->col('SELECT id FROM flags WHERE v IS DISTINCT FROM 0'));

        // ...and only the literal 0 is *not* distinct from 0.
        $this->assertSame([1], $this->col('SELECT id FROM flags WHERE v IS NOT DISTINCT FROM 0'));

        // Every non-NULL value is distinct from NULL, including 0 and ''.
        $this->assertSame([1, 3, 4], $this->col('SELECT id FROM flags WHERE v IS DISTINCT FROM NULL'));
        $this->assertSame([2], $this->col('SELECT id FROM flags WHERE v IS NOT DISTINCT FROM NULL'));

        // Scalar form, away from any table or pushdown path.
        $this->assertSame([1], $this->col('SELECT 0 IS DISTINCT FROM NULL AS d'));
        $this->assertSame([0], $this->col('SELECT 0 IS NOT DISTINCT FROM NULL AS d'));
        $this->assertSame([1], $this->col("SELECT '' IS DISTINCT FROM NULL AS d"));
    }

    /** Exercises the OR path, which builds table predicates and must decline this one */
    public function testIsDistinctFromInsideOr(): void
    {
        $this->assertSame(
            [1, 2, 4],
            $this->col("SELECT id FROM contacts WHERE email IS NOT DISTINCT FROM NULL OR name = 'Alice'")
        );
    }

    /**
     * Deliberate boundary: the join engine is a nested loop driven by bind
     * predicates, and neither a NULL-safe comparison nor a row value can be
     * expressed as one. Both must fail loudly rather than quietly join on
     * something else - the same way `ON a.x = 5` already does.
     */
    public function testUnsupportedJoinConditionsFailLoudly(): void
    {
        $this->assertThrows(
            fn() => $this->col(
                'SELECT contacts.id FROM contacts JOIN pairs ON contacts.name IS NOT DISTINCT FROM pairs.b'
            ),
            \RuntimeException::class
        );
        $this->assertThrows(
            fn() => $this->col(
                'SELECT contacts.id FROM contacts JOIN pairs ON (contacts.id, contacts.name) = (pairs.id, pairs.b)'
            ),
            \RuntimeException::class
        );
    }

    /** The pre-existing IS NULL spelling is unaffected */
    public function testIsNullStillWorks(): void
    {
        $this->assertSame([2, 4], $this->col('SELECT id FROM contacts WHERE email IS NULL'));
        $this->assertSame([1, 3, 5], $this->col('SELECT id FROM contacts WHERE email IS NOT NULL'));
    }

    // =====================================================================
    // ORDER BY ... NULLS FIRST / NULLS LAST
    // =====================================================================

    /**
     * Documents the engine's default: NULL sorts below every value, so it comes
     * first ascending and last descending - the same as SQLite, and unchanged
     * by this work.
     */
    public function testDefaultNullOrderingPutsNullsLowest(): void
    {
        $this->assertSame([null, 1, 1, 2, 2], $this->col('SELECT a FROM pairs ORDER BY a'));
        $this->assertSame([2, 2, 1, null], $this->col('SELECT a FROM pairs WHERE id <> 2 ORDER BY a DESC'));
    }

    public function testNullsLastOverridesTheAscendingDefault(): void
    {
        $this->assertSame([1, 2, 2, null], $this->col('SELECT a FROM pairs WHERE id <> 2 ORDER BY a NULLS LAST'));
    }

    public function testNullsFirstIsNotFlippedByDesc(): void
    {
        $this->assertSame(
            [null, 2, 2, 1],
            $this->col('SELECT a FROM pairs WHERE id <> 2 ORDER BY a DESC NULLS FIRST')
        );
        $this->assertSame(
            [2, 2, 1, null],
            $this->col('SELECT a FROM pairs WHERE id <> 2 ORDER BY a DESC NULLS LAST')
        );
    }

    /** Null ordering applies to the sort key, and later keys still break ties */
    public function testNullsLastWithASecondSortKey(): void
    {
        $this->assertSame(
            // b: rows 1/3/4 are 'x', row 2 is 'y', row 5 is NULL and goes last.
            // Within the 'x' group the second key decides, and it keeps its own
            // (default) null ordering: DESC puts row 4's NULL last.
            [3, 1, 4, 2, 5],
            $this->col('SELECT id FROM pairs ORDER BY b NULLS LAST, a DESC')
        );
    }

    /** ORDER BY on a SELECT alias goes down the expression-sort path */
    public function testNullsLastOnASelectAlias(): void
    {
        $this->assertSame(
            [1, 2, 2, null],
            $this->col('SELECT a * 1 AS aa FROM pairs WHERE id <> 2 ORDER BY aa NULLS LAST')
        );
    }

    /** ...and ORDER BY over a GROUP BY goes down the aggregate-sort path */
    public function testNullsLastOverAnAggregate(): void
    {
        $this->assertSame(
            // Bob's MAX(email) is NULL and sorts last; the NULL-name group has
            // a non-NULL max, so it sorts by value like any other group.
            ['Alice', 'Charlie', null, 'Bob'],
            $this->col('SELECT name, MAX(email) AS m FROM contacts GROUP BY name ORDER BY m NULLS LAST')
        );
    }

    /**
     * A TableInterface order spec cannot express null ordering, and the
     * set-operation path has no in-memory fallback. Say so rather than sorting
     * differently from what was asked for.
     */
    public function testNullOrderingOnASetOperationFailsLoudly(): void
    {
        $this->assertThrows(
            fn() => $this->col('SELECT a FROM pairs UNION ALL SELECT a FROM pairs ORDER BY a NULLS LAST'),
            \RuntimeException::class
        );

        // The documented workaround produces the right answer
        $this->assertSame(
            [1, 1, 2, 2, null, null],
            $this->col(
                'SELECT a FROM (SELECT a FROM pairs WHERE id IN (1, 3, 4)'
                . ' UNION ALL SELECT a FROM pairs WHERE id IN (1, 3, 4)) AS u ORDER BY a NULLS LAST'
            )
        );
    }

    // =====================================================================
    // VALUES as a table constructor
    // =====================================================================

    public function testValuesAsADerivedTableWithDefaultColumnNames(): void
    {
        $rows = iterator_to_array($this->vdb->query("SELECT * FROM (VALUES (1, 'a'), (2, 'b')) AS v"));
        $this->assertCount(2, $rows);
        $this->assertSame(['column1' => 1, 'column2' => 'a'], (array) $rows[0]);
        $this->assertSame(['column1' => 2, 'column2' => 'b'], (array) $rows[1]);
    }

    public function testValuesWithADerivedColumnList(): void
    {
        $rows = iterator_to_array($this->vdb->query("SELECT * FROM (VALUES (1, 'a'), (2, 'b')) AS v(x, y)"));
        $this->assertSame(['x' => 1, 'y' => 'a'], (array) $rows[0]);
        $this->assertSame(['b'], $this->col("SELECT y FROM (VALUES (1, 'a'), (2, 'b')) AS v(x, y) WHERE x = 2"));
    }

    /** A table value constructor keeps duplicates - it is UNION ALL, not UNION */
    public function testValuesKeepsDuplicateRows(): void
    {
        $this->assertSame([1, 1, 2], $this->col('SELECT x FROM (VALUES (1), (1), (2)) AS v(x)'));
    }

    public function testValuesAsAStandaloneQuery(): void
    {
        $rows = iterator_to_array($this->vdb->query("VALUES (1, 'a'), (2, 'b')"));
        $this->assertCount(2, $rows);
        $this->assertSame(['column1' => 1, 'column2' => 'a'], (array) $rows[0]);
    }

    public function testValuesAsACteBody(): void
    {
        $this->assertSame(
            ['a', 'b'],
            $this->col("WITH v(x, y) AS (VALUES (1, 'a'), (2, 'b')) SELECT y FROM v ORDER BY x")
        );
    }

    public function testValuesJoinedAgainstARealTable(): void
    {
        $this->assertSame(
            [1, 2, 3, 5],
            $this->col('SELECT pairs.id FROM pairs JOIN (VALUES (1), (2)) AS v(k) ON pairs.a = v.k ORDER BY pairs.id')
        );
    }

    public function testValuesInsideAnInSubquery(): void
    {
        $this->assertSame(
            [1, 3],
            $this->col('SELECT id FROM contacts WHERE id IN (SELECT column1 FROM (VALUES (1), (3)) AS v)')
        );
    }

    /** VALUES is a query expression, so it is accepted directly by IN */
    public function testValuesDirectlyInsideIn(): void
    {
        $this->assertSame([1, 3], $this->col('SELECT id FROM contacts WHERE id IN (VALUES (1), (3))'));
        $this->assertSame(
            [1],
            $this->col("SELECT id FROM pairs WHERE (a, b) IN (VALUES (1, 'x'))")
        );
    }

    /**
     * Caught at parse time, with a position, rather than downstream by the
     * UNION arity check the desugared form would otherwise hit.
     */
    public function testRaggedValuesRowsAreRejected(): void
    {
        try {
            $this->col("SELECT * FROM (VALUES (1, 'a'), (2)) AS v");
            $this->fail('Expected a ragged VALUES constructor to be rejected');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\mini\Parsing\SQL\SqlSyntaxException::class, $e);
            $this->assertStringContainsString('same number of columns', $e->getMessage());
        }
    }
};

exit($test->run());
