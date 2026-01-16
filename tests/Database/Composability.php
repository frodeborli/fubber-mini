<?php
/**
 * Test cross-source composability
 *
 * Tests composition of different table sources in VirtualDatabase:
 * - InMemoryTable
 * - GeneratorTable
 * - ArrayTable
 * - CSVTable
 * - PartialQuery (from PDODatabase)
 * - Nested VirtualDatabase queries
 *
 * The interfaces (TableInterface, ResultSetInterface) promise that different data sources
 * can be composed together. This test suite verifies that promise.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Database\PDODatabase;
use mini\Database\PartialQuery;
use mini\Database\Query;
use mini\Table\InMemoryTable;
use mini\Table\GeneratorTable;
use mini\Table\ArrayTable;
use mini\Table\CSVTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private PDODatabase $sqliteA;
    private PDODatabase $sqliteB;
    private string $csvFile;
    private static ?\ReflectionProperty $pqRef = null;

    /**
     * Extract the internal PartialQuery from a Query via reflection
     */
    private function pq(Query $query): PartialQuery
    {
        if (self::$pqRef === null) {
            self::$pqRef = new \ReflectionProperty(Query::class, 'pq');
        }
        return self::$pqRef->getValue($query);
    }

    protected function setUp(): void
    {
        \mini\bootstrap();

        // Create first SQLite database
        $pdoA = new PDO('sqlite::memory:');
        $this->sqliteA = new PDODatabase($pdoA);
        $pdoA->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, source TEXT)');
        $pdoA->exec("INSERT INTO users VALUES (1, 'Alice', 30, 'db_a')");
        $pdoA->exec("INSERT INTO users VALUES (2, 'Bob', 25, 'db_a')");

        // Create second SQLite database
        $pdoB = new PDO('sqlite::memory:');
        $this->sqliteB = new PDODatabase($pdoB);
        $pdoB->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, age INTEGER, source TEXT)');
        $pdoB->exec("INSERT INTO users VALUES (3, 'Charlie', 35, 'db_b')");
        $pdoB->exec("INSERT INTO users VALUES (4, 'Diana', 28, 'db_b')");

        // Create CSV file
        $this->csvFile = sys_get_temp_dir() . '/composability_test_' . uniqid() . '.csv';
        file_put_contents($this->csvFile, "id,name,age,source\n5,Eve,32,csv\n6,Frank,29,csv\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->csvFile)) {
            unlink($this->csvFile);
        }
    }

    private function createInMemoryUsers(): InMemoryTable
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('source', ColumnType::Text),
        );
        $table->insert(['id' => 7, 'name' => 'Grace', 'age' => 27, 'source' => 'memory']);
        $table->insert(['id' => 8, 'name' => 'Henry', 'age' => 33, 'source' => 'memory']);
        return $table;
    }

    private function createGeneratorUsers(): GeneratorTable
    {
        $generator = function () {
            yield (object)['id' => 9, 'name' => 'Ivy', 'age' => 26, 'source' => 'generator'];
            yield (object)['id' => 10, 'name' => 'Jack', 'age' => 31, 'source' => 'generator'];
        };

        return new GeneratorTable(
            $generator,
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('source', ColumnType::Text),
        );
    }

    private function createArrayUsers(): ArrayTable
    {
        return new ArrayTable(
            [
                ['id' => 11, 'name' => 'Kate', 'age' => 24, 'source' => 'array'],
                ['id' => 12, 'name' => 'Leo', 'age' => 36, 'source' => 'array'],
            ],
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('source', ColumnType::Text),
        );
    }

    // =========================================================================
    // Basic table type tests - verify each type works in VirtualDatabase
    // =========================================================================

    public function testInMemoryTableInVdb(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->createInMemoryUsers());

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(2, $rows);
        $this->assertSame('Grace', $rows[0]->name);
    }

    public function testGeneratorTableInVdb(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->createGeneratorUsers());

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(2, $rows);
        $this->assertSame('Ivy', $rows[0]->name);
    }

    public function testArrayTableInVdb(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->createArrayUsers());

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(2, $rows);
        $this->assertSame('Kate', $rows[0]->name);
    }

    public function testCsvTableInVdb(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', CSVTable::fromFile($this->csvFile));

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(2, $rows);
        $this->assertSame('Eve', $rows[0]->name);
    }

    public function testPartialQueryInVdb(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $this->pq($this->sqliteA->query('SELECT * FROM users')));

        $rows = iterator_to_array($vdb->query('SELECT * FROM users'));

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    // =========================================================================
    // UNION: Combining two same-type tables
    // =========================================================================

    public function testUnionTwoInMemoryTables(): void
    {
        $table1 = $this->createInMemoryUsers();
        $table2 = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('source', ColumnType::Text),
        );
        $table2->insert(['id' => 100, 'name' => 'Test1', 'age' => 20, 'source' => 'memory2']);
        $table2->insert(['id' => 101, 'name' => 'Test2', 'age' => 21, 'source' => 'memory2']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users1', $table1);
        $vdb->registerTable('users2', $table2);

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM users1 UNION ALL SELECT * FROM users2'
        ));

        $this->assertCount(4, $rows);
    }

    public function testUnionTwoSqliteDatabases(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users_a', $this->pq($this->sqliteA->query('SELECT * FROM users')));
        $vdb->registerTable('users_b', $this->pq($this->sqliteB->query('SELECT * FROM users')));

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM users_a UNION ALL SELECT * FROM users_b'
        ));

        $this->assertCount(4, $rows);

        $sources = array_unique(array_map(fn($r) => $r->source, $rows));
        sort($sources);
        $this->assertSame(['db_a', 'db_b'], $sources);
    }

    // =========================================================================
    // UNION: Combining different table types
    // =========================================================================

    public function testUnionInMemoryAndGenerator(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('memory_users', $this->createInMemoryUsers());
        $vdb->registerTable('gen_users', $this->createGeneratorUsers());

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM memory_users UNION ALL SELECT * FROM gen_users'
        ));

        $this->assertCount(4, $rows);
    }

    public function testUnionInMemoryAndCsv(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('memory_users', $this->createInMemoryUsers());
        $vdb->registerTable('csv_users', CSVTable::fromFile($this->csvFile));

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM memory_users UNION ALL SELECT * FROM csv_users'
        ));

        $this->assertCount(4, $rows);
    }

    public function testUnionInMemoryAndPartialQuery(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('memory_users', $this->createInMemoryUsers());
        $vdb->registerTable('sqlite_users', $this->pq($this->sqliteA->query('SELECT * FROM users')));

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM memory_users UNION ALL SELECT * FROM sqlite_users'
        ));

        $this->assertCount(4, $rows);
    }

    public function testUnionFourDifferentSources(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('memory_users', $this->createInMemoryUsers());
        $vdb->registerTable('gen_users', $this->createGeneratorUsers());
        $vdb->registerTable('csv_users', CSVTable::fromFile($this->csvFile));
        $vdb->registerTable('array_users', $this->createArrayUsers());

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM memory_users ' .
            'UNION ALL SELECT * FROM gen_users ' .
            'UNION ALL SELECT * FROM csv_users ' .
            'UNION ALL SELECT * FROM array_users'
        ));

        $this->assertCount(8, $rows);

        $sources = array_unique(array_map(fn($r) => $r->source, $rows));
        sort($sources);
        $this->assertSame(['array', 'csv', 'generator', 'memory'], $sources);
    }

    // =========================================================================
    // Nested VirtualDatabase - queries as table sources
    // =========================================================================

    public function testNestedVdbSimple(): void
    {
        // Layer 1: combine memory tables
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users1', $this->createInMemoryUsers());
        $vdb1->registerTable('users2', $this->createArrayUsers());

        $combined = $this->pq($vdb1->query('SELECT * FROM users1 UNION ALL SELECT * FROM users2'));

        // Layer 2: use combined as a table
        $vdb2 = new VirtualDatabase();
        $vdb2->registerTable('all_users', $combined);

        $rows = iterator_to_array($vdb2->query('SELECT * FROM all_users'));

        $this->assertCount(4, $rows);
    }

    public function testNestedVdbWithFilter(): void
    {
        // Layer 1
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users', $this->createInMemoryUsers());

        $query = $this->pq($vdb1->query('SELECT * FROM users'));

        // Layer 2 with filter
        $vdb2 = new VirtualDatabase();
        $vdb2->registerTable('users', $query);

        $rows = iterator_to_array($vdb2->query('SELECT * FROM users WHERE age > 30'));

        $this->assertCount(1, $rows);
        $this->assertSame('Henry', $rows[0]->name);
    }

    public function testThreeLevelsOfNesting(): void
    {
        // Level 1: raw source
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('raw', $this->createInMemoryUsers());

        // Level 2: filter
        $vdb2 = new VirtualDatabase();
        $vdb2->registerTable('filtered', $this->pq($vdb1->query('SELECT * FROM raw WHERE age >= 27')));

        // Level 3: further filter
        $vdb3 = new VirtualDatabase();
        $vdb3->registerTable('final', $this->pq($vdb2->query('SELECT * FROM filtered WHERE age >= 30')));

        $rows = iterator_to_array($vdb3->query('SELECT * FROM final'));

        $this->assertCount(1, $rows);
        $this->assertSame('Henry', $rows[0]->name);
    }

    public function testFiveLevelsOfNesting(): void
    {
        // Level 1: base data
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users', $this->createInMemoryUsers());

        // Level 2: select all
        $vdb2 = new VirtualDatabase();
        $vdb2->registerTable('l2', $this->pq($vdb1->query('SELECT * FROM users')));

        // Level 3: filter
        $vdb3 = new VirtualDatabase();
        $vdb3->registerTable('l3', $this->pq($vdb2->query('SELECT * FROM l2 WHERE age >= 27')));

        // Level 4: order
        $vdb4 = new VirtualDatabase();
        $vdb4->registerTable('l4', $this->pq($vdb3->query('SELECT * FROM l3 ORDER BY age DESC')));

        // Level 5: limit
        $vdb5 = new VirtualDatabase();
        $vdb5->registerTable('l5', $this->pq($vdb4->query('SELECT * FROM l4 LIMIT 1')));

        $rows = iterator_to_array($vdb5->query('SELECT name, age FROM l5'));

        $this->assertCount(1, $rows);
        $this->assertSame('Henry', $rows[0]->name);  // Oldest is Henry (33)
    }

    public function testUnionAcrossNestingLevels(): void
    {
        // Create two different nesting depths and UNION them
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users', $this->createInMemoryUsers());

        // Deep path: 3 levels
        $vdb2 = new VirtualDatabase();
        $vdb2->registerTable('filtered', $this->pq($vdb1->query('SELECT name FROM users WHERE age >= 30')));

        $vdb3 = new VirtualDatabase();
        $vdb3->registerTable('deep', $this->pq($vdb2->query('SELECT * FROM filtered')));

        // Shallow path: 1 level
        $shallow = $this->pq($vdb1->query('SELECT name FROM users WHERE age < 30'));

        // UNION them in a new VDB
        $vdbFinal = new VirtualDatabase();
        $vdbFinal->registerTable('deep_users', $this->pq($vdb3->query('SELECT * FROM deep')));
        $vdbFinal->registerTable('shallow_users', $shallow);

        $rows = iterator_to_array($vdbFinal->query(
            'SELECT * FROM deep_users UNION ALL SELECT * FROM shallow_users'
        ));

        $this->assertCount(2, $rows);  // Henry (>=30) + Grace (<30)
    }

    // =========================================================================
    // Cross-source JOINs
    // =========================================================================

    public function testJoinInMemoryTables(): void
    {
        $users = $this->createInMemoryUsers();

        $orders = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('amount', ColumnType::Float),
        );
        $orders->insert(['id' => 1, 'user_id' => 7, 'amount' => 100.0]);
        $orders->insert(['id' => 2, 'user_id' => 7, 'amount' => 50.0]);
        $orders->insert(['id' => 3, 'user_id' => 8, 'amount' => 75.0]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $users);
        $vdb->registerTable('orders', $orders);

        $rows = iterator_to_array($vdb->query(
            'SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id'
        ));

        $this->assertCount(3, $rows);
    }

    public function testJoinCsvAndInMemory(): void
    {
        $orders = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('user_id', ColumnType::Int),
            new ColumnDef('amount', ColumnType::Float),
        );
        // CSV has users 5 (Eve) and 6 (Frank)
        $orders->insert(['id' => 1, 'user_id' => 5, 'amount' => 200.0]);
        $orders->insert(['id' => 2, 'user_id' => 6, 'amount' => 150.0]);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', CSVTable::fromFile($this->csvFile));
        $vdb->registerTable('orders', $orders);

        $rows = iterator_to_array($vdb->query(
            'SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id'
        ));

        $this->assertCount(2, $rows);
    }

    // =========================================================================
    // Filters on composed queries
    // =========================================================================

    public function testFilterAfterUnion(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users1', $this->createInMemoryUsers());
        $vdb->registerTable('users2', $this->createArrayUsers());

        $query = $vdb->query('SELECT * FROM users1 UNION ALL SELECT * FROM users2');
        $filtered = $query->gt('age', 30);

        $rows = iterator_to_array($filtered);

        // Henry (33) and Leo (36) are > 30
        $this->assertCount(2, $rows);
    }

    public function testOrderAndLimitOnComposed(): void
    {
        $vdb = new VirtualDatabase();
        $vdb->registerTable('users1', $this->createInMemoryUsers());
        $vdb->registerTable('users2', $this->createArrayUsers());

        $query = $vdb->query('SELECT * FROM users1 UNION ALL SELECT * FROM users2')
            ->order('age DESC')
            ->limit(2);

        $rows = iterator_to_array($query);

        $this->assertCount(2, $rows);
        // Leo (36) and Henry (33) are the oldest
        $this->assertSame('Leo', $rows[0]->name);
        $this->assertSame('Henry', $rows[1]->name);
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyTableInUnion(): void
    {
        $empty = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('source', ColumnType::Text),
        );

        $vdb = new VirtualDatabase();
        $vdb->registerTable('empty_users', $empty);
        $vdb->registerTable('real_users', $this->createInMemoryUsers());

        $rows = iterator_to_array($vdb->query(
            'SELECT * FROM empty_users UNION ALL SELECT * FROM real_users'
        ));

        $this->assertCount(2, $rows);
    }

    public function testSameTableRegisteredTwice(): void
    {
        $users = $this->createInMemoryUsers();

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users_a', $users);
        $vdb->registerTable('users_b', $users);

        // Simple CROSS JOIN without WHERE (WHERE with duplicate PKs hits InMemoryTable limitation)
        $rows = iterator_to_array($vdb->query(
            'SELECT a.name as name1, b.name as name2 FROM users_a a CROSS JOIN users_b b'
        ));

        // 2 users x 2 users = 4
        $this->assertCount(4, $rows);
    }

    // =========================================================================
    // withTables() - Table shadowing
    // =========================================================================

    public function testVdbWithTablesCreatesNewInstance(): void
    {
        $users1 = $this->createInMemoryUsers();
        $users2 = $this->createArrayUsers();

        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users', $users1);

        $vdb2 = $vdb1->withTables(['extra' => $users2]);

        // Original unchanged
        $original = iterator_to_array($vdb1->query('SELECT * FROM users'));
        $this->assertCount(2, $original);

        // New VDB has both tables
        $users = iterator_to_array($vdb2->query('SELECT * FROM users'));
        $this->assertCount(2, $users);

        $extra = iterator_to_array($vdb2->query('SELECT * FROM extra'));
        $this->assertCount(2, $extra);
    }

    public function testVdbWithTablesShadowsExisting(): void
    {
        $original = $this->createInMemoryUsers();
        $shadow = $this->createArrayUsers();

        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('users', $original);

        // Shadow the users table with different data
        $vdb2 = $vdb1->withTables(['users' => $shadow]);

        // Original still has InMemory data (Grace, Henry)
        $rows1 = iterator_to_array($vdb1->query('SELECT * FROM users'));
        $this->assertCount(2, $rows1);
        $this->assertSame('Grace', $rows1[0]->name);

        // New VDB has Array data (Kate, Leo) - shadowed
        $rows2 = iterator_to_array($vdb2->query('SELECT * FROM users'));
        $this->assertCount(2, $rows2);
        $this->assertSame('Kate', $rows2[0]->name);
    }

    public function testPdoDatabaseWithTablesCreatesVdb(): void
    {
        // Create a real database with tables
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $sqlite->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL)');
        $sqlite->exec("INSERT INTO users VALUES (1, 'Real User')");
        $sqlite->exec("INSERT INTO orders VALUES (1, 1, 100.0)");
        $sqlite->exec("INSERT INTO orders VALUES (2, 7, 200.0)"); // user_id 7 = Grace from mock

        $db = new PDODatabase($sqlite);

        // Shadow users with mock data, keep real orders
        $mockUsers = $this->createInMemoryUsers(); // Grace (id=7), Henry (id=8)
        $vdb = $db->withTables(['users' => $mockUsers]);

        // It returns a VirtualDatabase
        $this->assertInstanceOf(VirtualDatabase::class, $vdb);

        // Mock table is accessible
        $users = iterator_to_array($vdb->query('SELECT * FROM users'));
        $this->assertCount(2, $users);
        $this->assertSame('Grace', $users[0]->name);

        // Real table is also accessible
        $orders = iterator_to_array($vdb->query('SELECT * FROM orders'));
        $this->assertCount(2, $orders);

        // Can JOIN mock users with real orders
        $joined = iterator_to_array($vdb->query(
            'SELECT u.name, o.amount FROM users u JOIN orders o ON u.id = o.user_id'
        ));
        $this->assertCount(1, $joined);
        $this->assertSame('Grace', $joined[0]->name);
        $this->assertEquals(200.0, $joined[0]->amount);
    }

    public function testWithTablesPreservesCustomAggregates(): void
    {
        $vdb1 = new VirtualDatabase();
        $vdb1->registerTable('numbers', new ArrayTable([
            (object)['value' => 1],
            (object)['value' => 2],
            (object)['value' => 3],
        ]));

        // Register a custom aggregate
        $vdb1->createAggregate(
            'custom_sum',
            function (&$context, $value) {
                $context = ($context ?? 0) + $value;
            },
            function (&$context) {
                return $context ?? 0;
            },
            1
        );

        // Create new VDB with extra table
        $vdb2 = $vdb1->withTables(['extra' => new ArrayTable([(object)['x' => 1]])]);

        // Custom aggregate should work in new VDB
        $result = iterator_to_array($vdb2->query('SELECT custom_sum(value) as total FROM numbers'));
        $this->assertSame(6, $result[0]->total);
    }
};

exit($test->run());
