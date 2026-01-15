<?php
/**
 * Verify VirtualDatabase produces the same results as SQLite
 *
 * This test file creates identical data in both VirtualDatabase and SQLite,
 * then runs the same queries through PartialQuery and compares results.
 *
 * Focus: Window semantics (limit/offset narrowing), barrier, column narrowing
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private VirtualDatabase $vdb;
    private PDODatabase $sqlite;

    protected function setUp(): void
    {
        \mini\bootstrap();

        // Create SQLite table
        \mini\db()->exec('DROP TABLE IF EXISTS users');
        \mini\db()->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            age INTEGER NOT NULL,
            status TEXT NOT NULL
        )');

        // Insert test data into SQLite
        \mini\db()->exec("INSERT INTO users (id, name, age, status) VALUES
            (1, 'Alice', 30, 'active'),
            (2, 'Bob', 25, 'active'),
            (3, 'Charlie', 35, 'inactive'),
            (4, 'Diana', 28, 'active'),
            (5, 'Eve', 22, 'inactive'),
            (6, 'Frank', 40, 'active'),
            (7, 'Grace', 33, 'inactive'),
            (8, 'Henry', 27, 'active'),
            (9, 'Ivy', 31, 'active'),
            (10, 'Jack', 29, 'inactive')
        ");

        $this->sqlite = \mini\db();

        // Create VirtualDatabase with same data
        $this->vdb = new VirtualDatabase();
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('status', ColumnType::Text),
        );

        $table->insert(['id' => 1, 'name' => 'Alice', 'age' => 30, 'status' => 'active']);
        $table->insert(['id' => 2, 'name' => 'Bob', 'age' => 25, 'status' => 'active']);
        $table->insert(['id' => 3, 'name' => 'Charlie', 'age' => 35, 'status' => 'inactive']);
        $table->insert(['id' => 4, 'name' => 'Diana', 'age' => 28, 'status' => 'active']);
        $table->insert(['id' => 5, 'name' => 'Eve', 'age' => 22, 'status' => 'inactive']);
        $table->insert(['id' => 6, 'name' => 'Frank', 'age' => 40, 'status' => 'active']);
        $table->insert(['id' => 7, 'name' => 'Grace', 'age' => 33, 'status' => 'inactive']);
        $table->insert(['id' => 8, 'name' => 'Henry', 'age' => 27, 'status' => 'active']);
        $table->insert(['id' => 9, 'name' => 'Ivy', 'age' => 31, 'status' => 'active']);
        $table->insert(['id' => 10, 'name' => 'Jack', 'age' => 29, 'status' => 'inactive']);

        $this->vdb->registerTable('users', $table);
    }

    /**
     * Compare results from VDB and SQLite queries
     */
    private function compareResults(iterable $vdbResult, iterable $sqliteResult, string $description): void
    {
        $vdbRows = [];
        foreach ($vdbResult as $row) {
            $vdbRows[] = (array) $row;
        }

        $sqliteRows = [];
        foreach ($sqliteResult as $row) {
            $sqliteRows[] = (array) $row;
        }

        $this->assertEquals(
            count($sqliteRows),
            count($vdbRows),
            "$description: row count mismatch (sqlite=" . count($sqliteRows) . ", vdb=" . count($vdbRows) . ")"
        );

        // Compare rows - both should be in same order for ordered queries
        for ($i = 0; $i < count($sqliteRows); $i++) {
            $this->assertEquals(
                $sqliteRows[$i],
                $vdbRows[$i],
                "$description: row $i mismatch"
            );
        }
    }

    // =========================================================================
    // Basic LIMIT tests - verify baseline behavior matches
    // =========================================================================

    public function testBasicLimit(): void
    {
        $vdbResult = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 5');
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 5');

        $this->compareResults($vdbResult, $sqliteResult, 'Basic LIMIT 5');
    }

    public function testLimitWithOffset(): void
    {
        $vdbResult = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 3 OFFSET 2');
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 3 OFFSET 2');

        $this->compareResults($vdbResult, $sqliteResult, 'LIMIT 3 OFFSET 2');
    }

    public function testOffsetOnly(): void
    {
        // Use a large limit to simulate "no limit" since parser doesn't support LIMIT -1
        $vdbResult = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 1000 OFFSET 5');
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 1000 OFFSET 5');

        $this->compareResults($vdbResult, $sqliteResult, 'OFFSET 5 with large limit');
    }

    // =========================================================================
    // Window semantics: limit() narrows, never expands
    // =========================================================================

    public function testLimitNarrowingMatchesSqlite(): void
    {
        // Start with LIMIT 10, then narrow to LIMIT 5
        // This should be equivalent to just LIMIT 5
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 10')
            ->limit(5);

        // SQLite equivalent: just use the narrowed limit
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 5');

        $this->compareResults($vdbPq, $sqliteResult, 'Limit narrowing 10->5');
    }

    public function testLimitNarrowingChainMatchesSqlite(): void
    {
        // Chain: LIMIT 10 -> LIMIT 7 -> LIMIT 3
        // Should be equivalent to LIMIT 3
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 10')
            ->limit(7)
            ->limit(3);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 3');

        $this->compareResults($vdbPq, $sqliteResult, 'Limit narrowing chain 10->7->3');
    }

    public function testLimitCannotExpandMatchesSqlite(): void
    {
        // Start with LIMIT 3, try to expand to LIMIT 10
        // Should still return only 3 rows (clamped)
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 3')
            ->limit(10);

        // SQLite equivalent: original limit is preserved
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 3');

        $this->compareResults($vdbPq, $sqliteResult, 'Limit expansion clamped 3->10');
    }

    // =========================================================================
    // Window semantics: offset() is additive and reduces limit
    // =========================================================================

    public function testOffsetAdditiveMatchesSqlite(): void
    {
        // offset(3) + offset(2) = offset(5)
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id')
            ->offset(3)
            ->offset(2);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT -1 OFFSET 5');

        $this->compareResults($vdbPq, $sqliteResult, 'Additive offset 3+2=5');
    }

    public function testOffsetReducesLimitMatchesSqlite(): void
    {
        // LIMIT 10 + offset(3) should give LIMIT 7 OFFSET 3
        // (we're viewing a window of 7 rows starting at position 3)
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 10')
            ->offset(3);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 7 OFFSET 3');

        $this->compareResults($vdbPq, $sqliteResult, 'Offset reduces limit: LIMIT 10 + offset(3)');
    }

    public function testOffsetBeyondLimitMatchesSqlite(): void
    {
        // LIMIT 5 + offset(10) = 0 rows (offset exceeds limit)
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 5')
            ->offset(10);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 0 OFFSET 10');

        $this->compareResults($vdbPq, $sqliteResult, 'Offset beyond limit gives zero rows');
    }

    public function testCombinedLimitOffsetNarrowingMatchesSqlite(): void
    {
        // Start with LIMIT 8 OFFSET 1
        // Add offset(2) -> should be LIMIT 6 OFFSET 3 (within original window)
        // Then limit(4) -> should be LIMIT 4 OFFSET 3
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 8 OFFSET 1')
            ->offset(2)
            ->limit(4);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 4 OFFSET 3');

        $this->compareResults($vdbPq, $sqliteResult, 'Combined limit+offset narrowing');
    }

    // =========================================================================
    // Automatic barrier: filters on paginated queries wrap in subquery
    // =========================================================================

    public function testFilterOnPaginatedQueryMatchesSqlite(): void
    {
        // When filtering a paginated query, barrier is applied automatically
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 6')
            ->eq('status', 'active');

        // Equivalent: filter AFTER pagination via subquery
        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM (SELECT * FROM users ORDER BY id LIMIT 6) WHERE status = 'active'"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'Filter on paginated query uses automatic barrier');
    }

    public function testFilterOnPaginatedQueryResetsWindowMatchesSqlite(): void
    {
        // After automatic barrier (via filter), can set new limit larger than inner
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 3')
            ->eq('status', 'active') // Triggers automatic barrier
            ->limit(10); // Outer limit on barrier result

        // The inner query returns 3 rows, filter may reduce, outer limit(10) doesn't expand
        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM (SELECT * FROM (SELECT * FROM users ORDER BY id LIMIT 3) WHERE status = 'active') LIMIT 10"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'Filter on paginated query resets window');
    }

    public function testFilterOnPaginatedQueryWithOffsetMatchesSqlite(): void
    {
        // Filter on LIMIT + OFFSET query should also trigger barrier
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 4 OFFSET 1')
            ->eq('status', 'active');

        // Equivalent: filter AFTER pagination
        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM (SELECT * FROM users ORDER BY id LIMIT 4 OFFSET 1) WHERE status = 'active'"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'Filter on paginated+offset query uses barrier');
    }

    // =========================================================================
    // Column projection tests
    // =========================================================================

    public function testColumnProjectionMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id')
            ->columns('id', 'name');

        $sqliteResult = $this->sqlite->query('SELECT id, name FROM users ORDER BY id');

        $this->compareResults($vdbPq, $sqliteResult, 'Column projection');
    }

    public function testColumnProjectionWithFilterMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id')
            ->eq('status', 'active')
            ->columns('id', 'name');

        $sqliteResult = $this->sqlite->query(
            "SELECT id, name FROM users WHERE status = 'active' ORDER BY id"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'Column projection with filter');
    }

    public function testColumnProjectionWithLimitMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 5')
            ->columns('name', 'age');

        $sqliteResult = $this->sqlite->query('SELECT name, age FROM users ORDER BY id LIMIT 5');

        $this->compareResults($vdbPq, $sqliteResult, 'Column projection with limit');
    }

    // =========================================================================
    // Combined operations: filter + limit + offset + projection
    // =========================================================================

    public function testComplexQueryMatchesSqlite(): void
    {
        // Complex query: filter, order, limit, offset, project
        $vdbPq = $this->vdb->query('SELECT * FROM users')
            ->gt('age', 25)
            ->order('age DESC')
            ->limit(5)
            ->offset(1)
            ->columns('name', 'age');

        $sqliteResult = $this->sqlite->query(
            'SELECT name, age FROM users WHERE age > 25 ORDER BY age DESC LIMIT 4 OFFSET 1'
        );

        $this->compareResults($vdbPq, $sqliteResult, 'Complex query with all operations');
    }

    public function testNestedWindowNarrowingMatchesSqlite(): void
    {
        // Multiple levels of window narrowing
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 8')
            ->limit(6)  // Narrow to 6
            ->offset(1) // Now LIMIT 5 OFFSET 1
            ->limit(3); // Narrow to LIMIT 3 OFFSET 1

        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 3 OFFSET 1');

        $this->compareResults($vdbPq, $sqliteResult, 'Nested window narrowing');
    }

    // =========================================================================
    // IN clause tests
    // =========================================================================

    public function testInClauseMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id')
            ->in('id', [1, 3, 5, 7]);

        $sqliteResult = $this->sqlite->query('SELECT * FROM users WHERE id IN (1, 3, 5, 7) ORDER BY id');

        $this->compareResults($vdbPq, $sqliteResult, 'IN clause');
    }

    public function testInClauseWithLimitMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 5')
            ->in('id', [1, 3, 5, 7, 9]);

        // Since we're filtering on paginated query, barrier applies
        $sqliteResult = $this->sqlite->query(
            'SELECT * FROM (SELECT * FROM users ORDER BY id LIMIT 5) WHERE id IN (1, 3, 5, 7, 9)'
        );

        $this->compareResults($vdbPq, $sqliteResult, 'IN clause on paginated query');
    }

    // =========================================================================
    // OR predicate tests
    // =========================================================================

    public function testOrPredicateMatchesSqlite(): void
    {
        // or() ORs together its arguments, then ANDs with existing WHERE
        // Correct usage: ->or($predicate1, $predicate2) gives (pred1 OR pred2)
        $p = new \mini\Table\Predicate();
        $vdbPq = $this->vdb->query("SELECT * FROM users ORDER BY id")
            ->or($p->eq('status', 'active'), $p->eq('status', 'inactive'));

        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM users WHERE status = 'active' OR status = 'inactive' ORDER BY id"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'OR predicate with multiple args');
    }

    public function testOrPredicateWithExistingWhereMatchesSqlite(): void
    {
        // or() result is ANDed with existing WHERE
        // WHERE age > 25 AND (status = 'active' OR status = 'inactive')
        $p = new \mini\Table\Predicate();
        $vdbPq = $this->vdb->query("SELECT * FROM users ORDER BY id")
            ->gt('age', 25)
            ->or($p->eq('status', 'active'), $p->eq('status', 'inactive'));

        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM users WHERE age > 25 AND (status = 'active' OR status = 'inactive') ORDER BY id"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'OR predicate ANDed with existing WHERE');
    }

    public function testOrInSqlMatchesSqlite(): void
    {
        // OR directly in SQL also works
        $vdbPq = $this->vdb->query(
            "SELECT * FROM users WHERE status = 'active' OR status = 'inactive' ORDER BY id"
        );

        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM users WHERE status = 'active' OR status = 'inactive' ORDER BY id"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'OR in SQL');
    }

    // =========================================================================
    // Aggregate function tests
    // =========================================================================

    public function testCountAggregateMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT COUNT(*) as cnt FROM users');
        $sqliteResult = $this->sqlite->query('SELECT COUNT(*) as cnt FROM users');

        $this->compareResults($vdbPq, $sqliteResult, 'COUNT aggregate');
    }

    public function testSumAggregateMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT SUM(age) as total_age FROM users');
        $sqliteResult = $this->sqlite->query('SELECT SUM(age) as total_age FROM users');

        $this->compareResults($vdbPq, $sqliteResult, 'SUM aggregate');
    }

    public function testGroupByMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT status, COUNT(*) as cnt FROM users GROUP BY status ORDER BY status');
        $sqliteResult = $this->sqlite->query('SELECT status, COUNT(*) as cnt FROM users GROUP BY status ORDER BY status');

        $this->compareResults($vdbPq, $sqliteResult, 'GROUP BY with COUNT');
    }

    // =========================================================================
    // Subquery tests
    // =========================================================================

    public function testInSubqueryMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query(
            "SELECT * FROM users WHERE id IN (SELECT id FROM users WHERE status = 'active') ORDER BY id"
        );
        $sqliteResult = $this->sqlite->query(
            "SELECT * FROM users WHERE id IN (SELECT id FROM users WHERE status = 'active') ORDER BY id"
        );

        $this->compareResults($vdbPq, $sqliteResult, 'IN subquery');
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyResultSetMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query("SELECT * FROM users WHERE name = 'NonExistent'");
        $sqliteResult = $this->sqlite->query("SELECT * FROM users WHERE name = 'NonExistent'");

        $this->compareResults($vdbPq, $sqliteResult, 'Empty result set');
    }

    public function testLimitZeroMatchesSqlite(): void
    {
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 0');
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 0');

        $this->compareResults($vdbPq, $sqliteResult, 'LIMIT 0');
    }

    public function testOffsetBeyondDataMatchesSqlite(): void
    {
        // Use large limit instead of -1 since parser doesn't support negative limits
        $vdbPq = $this->vdb->query('SELECT * FROM users ORDER BY id LIMIT 1000 OFFSET 100');
        $sqliteResult = $this->sqlite->query('SELECT * FROM users ORDER BY id LIMIT 1000 OFFSET 100');

        $this->compareResults($vdbPq, $sqliteResult, 'OFFSET beyond data');
    }
};

exit($test->run());
