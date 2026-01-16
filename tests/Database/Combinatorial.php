<?php
/**
 * Combinatorial tests for database operations
 *
 * Tests operations across all combinations of:
 * - Table implementations (InMemoryTable, PDO-backed PartialQuery, etc.)
 * - Database wrappers (VirtualDatabase, PDODatabase)
 * - Key types (integer, string)
 * - Operations (eq, lt, gt, join, union, etc.)
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\PDODatabase;
use mini\Database\VirtualDatabase;
use mini\Database\PartialQuery;
use mini\Database\Query;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;
use mini\Table\Contracts\TableInterface;

/**
 * Schema definition - implementation agnostic
 *
 * Format: ['column_name' => [ColumnType, IndexType], ...]
 * IndexType defaults to None if not specified
 */
class Schema
{
    /** @var ColumnDef[] */
    public readonly array $columns;

    public function __construct(array $definition)
    {
        $columns = [];
        foreach ($definition as $name => $spec) {
            $type = $spec[0] ?? ColumnType::Text;
            $index = $spec[1] ?? IndexType::None;
            $columns[] = new ColumnDef($name, $type, $index);
        }
        $this->columns = $columns;
    }

    /**
     * Create an InMemoryTable with this schema and given data
     */
    public function createInMemoryTable(array $rows = []): InMemoryTable
    {
        $table = new InMemoryTable(...$this->columns);
        foreach ($rows as $row) {
            $table->insert($row);
        }
        return $table;
    }

    /**
     * Create table in a PDODatabase and return the database
     */
    public function createInPdo(string $tableName, array $rows = []): PDODatabase
    {
        $pdo = new PDODatabase(new \PDO('sqlite::memory:'));

        // Build CREATE TABLE SQL
        $columnsSql = [];
        foreach ($this->columns as $col) {
            $sql = "\"{$col->name}\" {$col->type->sqlType()}";
            if ($col->index === IndexType::Primary) {
                $sql .= ' PRIMARY KEY';
            } elseif ($col->index === IndexType::Unique) {
                $sql .= ' UNIQUE';
            }
            $columnsSql[] = $sql;
        }
        $pdo->exec("CREATE TABLE \"{$tableName}\" (" . implode(', ', $columnsSql) . ")");

        // Insert rows
        foreach ($rows as $row) {
            $columns = array_keys($row);
            $placeholders = array_map(fn($c) => ":$c", $columns);
            $sql = "INSERT INTO \"{$tableName}\" (\"" . implode('", "', $columns) . "\") VALUES (" . implode(', ', $placeholders) . ")";
            $pdo->exec($sql, $row);
        }

        return $pdo;
    }

    /**
     * Generate CREATE TABLE SQL
     */
    public function createTableSql(string $tableName): string
    {
        $columnsSql = [];
        foreach ($this->columns as $col) {
            $sql = "\"{$col->name}\" {$col->type->sqlType()}";
            if ($col->index === IndexType::Primary) {
                $sql .= ' PRIMARY KEY';
            } elseif ($col->index === IndexType::Unique) {
                $sql .= ' UNIQUE';
            }
            $columnsSql[] = $sql;
        }
        return "CREATE TABLE \"{$tableName}\" (" . implode(', ', $columnsSql) . ")";
    }
}

/**
 * Fixture: schema + test data for a table
 */
class TableFixture
{
    public function __construct(
        public readonly string $name,
        public readonly Schema $schema,
        public readonly array $rows,
    ) {}
}

$test = new class extends Test {

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

    // =========================================================================
    // FIXTURES - Define test data schemas
    // =========================================================================

    private function usersSchemaIntKey(): Schema
    {
        return new Schema([
            'id' => [ColumnType::Int, IndexType::Primary],
            'name' => [ColumnType::Text],
            'age' => [ColumnType::Int],
        ]);
    }

    private function usersSchemaStringKey(): Schema
    {
        return new Schema([
            'id' => [ColumnType::Text, IndexType::Primary],
            'name' => [ColumnType::Text],
            'age' => [ColumnType::Int],
        ]);
    }

    private function ordersSchemaIntKey(): Schema
    {
        return new Schema([
            'id' => [ColumnType::Int, IndexType::Primary],
            'user_id' => [ColumnType::Int, IndexType::Index],
            'amount' => [ColumnType::Int],
        ]);
    }

    private function ordersSchemaStringKey(): Schema
    {
        return new Schema([
            'id' => [ColumnType::Text, IndexType::Primary],
            'user_id' => [ColumnType::Text, IndexType::Index],
            'amount' => [ColumnType::Int],
        ]);
    }

    private function usersDataInt(): array
    {
        return [
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Charlie', 'age' => 35],
        ];
    }

    private function usersDataString(): array
    {
        return [
            ['id' => 'u1', 'name' => 'Alice', 'age' => 30],
            ['id' => 'u2', 'name' => 'Bob', 'age' => 25],
            ['id' => 'u3', 'name' => 'Charlie', 'age' => 35],
        ];
    }

    private function ordersDataInt(): array
    {
        return [
            ['id' => 1, 'user_id' => 1, 'amount' => 100],
            ['id' => 2, 'user_id' => 1, 'amount' => 200],
            ['id' => 3, 'user_id' => 2, 'amount' => 150],
        ];
    }

    private function ordersDataString(): array
    {
        return [
            ['id' => 'o1', 'user_id' => 'u1', 'amount' => 100],
            ['id' => 'o2', 'user_id' => 'u1', 'amount' => 200],
            ['id' => 'o3', 'user_id' => 'u2', 'amount' => 150],
        ];
    }

    // =========================================================================
    // TABLE FACTORIES - Different ways to create a TableInterface
    // =========================================================================

    /**
     * Get all table factory configurations
     *
     * Each factory takes (Schema, data[], tableName) and returns a TableInterface
     *
     * @return array<string, Closure(Schema, array, string): TableInterface>
     */
    private function tableFactories(): array
    {
        return [
            'in_memory' => function (Schema $schema, array $data, string $_name): TableInterface {
                return $schema->createInMemoryTable($data);
            },
            'pdo_query' => function (Schema $schema, array $data, string $name): TableInterface {
                $pdo = $schema->createInPdo($name, $data);
                return $this->pq($pdo->query("SELECT * FROM \"{$name}\""));
            },
        ];
    }

    // =========================================================================
    // DATABASE CONFIGURATIONS - Different ways to host tables
    // =========================================================================

    /**
     * Get database configurations for two-table scenarios
     *
     * Each config takes two TableFixtures and returns [Query $queryA, Query $queryB]
     *
     * @return array<string, Closure(TableFixture, TableFixture, Closure): array{Query, Query}>
     */
    private function twoTableConfigs(): array
    {
        return [
            // Both tables in same VirtualDatabase
            'same_vdb' => function (TableFixture $a, TableFixture $b, Closure $tableFactory): array {
                $vdb = new VirtualDatabase();
                $vdb->registerTable($a->name, $tableFactory($a->schema, $a->rows, $a->name));
                $vdb->registerTable($b->name, $tableFactory($b->schema, $b->rows, $b->name));
                return [
                    $vdb->query("SELECT * FROM \"{$a->name}\""),
                    $vdb->query("SELECT * FROM \"{$b->name}\""),
                ];
            },

            // Both tables in same PDODatabase (ignores tableFactory, uses native PDO tables)
            'same_pdo' => function (TableFixture $a, TableFixture $b, Closure $_tableFactory): array {
                $pdo = new PDODatabase(new \PDO('sqlite::memory:'));
                $pdo->exec($a->schema->createTableSql($a->name));
                $pdo->exec($b->schema->createTableSql($b->name));
                foreach ($a->rows as $row) {
                    $cols = array_keys($row);
                    $pdo->exec(
                        "INSERT INTO \"{$a->name}\" (\"" . implode('", "', $cols) . "\") VALUES (:" . implode(', :', $cols) . ")",
                        $row
                    );
                }
                foreach ($b->rows as $row) {
                    $cols = array_keys($row);
                    $pdo->exec(
                        "INSERT INTO \"{$b->name}\" (\"" . implode('", "', $cols) . "\") VALUES (:" . implode(', :', $cols) . ")",
                        $row
                    );
                }
                return [
                    $pdo->query("SELECT * FROM \"{$a->name}\""),
                    $pdo->query("SELECT * FROM \"{$b->name}\""),
                ];
            },

            // Table A in VDB (using factory), Table B in separate PDO
            'vdb_and_pdo' => function (TableFixture $a, TableFixture $b, Closure $tableFactory): array {
                $vdb = new VirtualDatabase();
                $vdb->registerTable($a->name, $tableFactory($a->schema, $a->rows, $a->name));

                $pdo = $b->schema->createInPdo($b->name, $b->rows);

                return [
                    $vdb->query("SELECT * FROM \"{$a->name}\""),
                    $pdo->query("SELECT * FROM \"{$b->name}\""),
                ];
            },

            // Mixed: VDB wrapping PDO query + direct VDB table (ignores tableFactory)
            'vdb_wrapping_pdo' => function (TableFixture $a, TableFixture $b, Closure $_tableFactory): array {
                // Table A: VDB wrapping a PDO query
                $pdoA = $a->schema->createInPdo($a->name, $a->rows);
                $vdb = new VirtualDatabase();
                $vdb->registerTable($a->name, $this->pq($pdoA->query("SELECT * FROM \"{$a->name}\"")));

                // Table B: Direct InMemoryTable in same VDB
                $vdb->registerTable($b->name, $b->schema->createInMemoryTable($b->rows));

                return [
                    $vdb->query("SELECT * FROM \"{$a->name}\""),
                    $vdb->query("SELECT * FROM \"{$b->name}\""),
                ];
            },
        ];
    }

    // =========================================================================
    // SINGLE-TABLE FILTER TESTS
    // =========================================================================

    public function testEqAcrossTableImplementations(): void
    {
        $schema = $this->usersSchemaIntKey();
        $data = $this->usersDataInt();

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users', $factory($schema, $data, 'users'));

            $result = iterator_to_array($vdb->query('SELECT * FROM users')->eq('id', 1));
            $this->assertCount(1, $result, "eq filter failed for $factoryName");
            $this->assertSame('Alice', $result[0]->name, "eq returned wrong row for $factoryName");
        }
    }

    public function testLtGtAcrossTableImplementations(): void
    {
        $schema = $this->usersSchemaIntKey();
        $data = $this->usersDataInt();

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users', $factory($schema, $data, 'users'));

            // lt: age < 30 should return Bob (25)
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->lt('age', 30));
            $this->assertCount(1, $result, "lt filter failed for $factoryName");
            $this->assertSame('Bob', $result[0]->name, "lt returned wrong row for $factoryName");

            // gt: age > 30 should return Charlie (35)
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->gt('age', 30));
            $this->assertCount(1, $result, "gt filter failed for $factoryName");
            $this->assertSame('Charlie', $result[0]->name, "gt returned wrong row for $factoryName");

            // lte: age <= 30 should return Alice (30) and Bob (25)
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->lte('age', 30)->order('age'));
            $this->assertCount(2, $result, "lte filter failed for $factoryName");

            // gte: age >= 30 should return Alice (30) and Charlie (35)
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->gte('age', 30)->order('age'));
            $this->assertCount(2, $result, "gte filter failed for $factoryName");
        }
    }

    public function testLikeAcrossTableImplementations(): void
    {
        $schema = $this->usersSchemaIntKey();
        $data = $this->usersDataInt();

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users', $factory($schema, $data, 'users'));

            // like: name LIKE 'A%' should return Alice
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->like('name', 'A%'));
            $this->assertCount(1, $result, "like filter failed for $factoryName");
            $this->assertSame('Alice', $result[0]->name, "like returned wrong row for $factoryName");

            // like: name LIKE '%e' should return Alice and Charlie
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->like('name', '%e')->order('name'));
            $this->assertCount(2, $result, "like suffix filter failed for $factoryName");
        }
    }

    public function testInAcrossTableImplementations(): void
    {
        $schema = $this->usersSchemaIntKey();
        $data = $this->usersDataInt();

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users', $factory($schema, $data, 'users'));

            // in: id IN (1, 3) should return Alice and Charlie
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->in('id', [1, 3])->order('id'));
            $this->assertCount(2, $result, "in filter failed for $factoryName");
            $this->assertSame('Alice', $result[0]->name, "in returned wrong first row for $factoryName");
            $this->assertSame('Charlie', $result[1]->name, "in returned wrong second row for $factoryName");
        }
    }

    public function testFiltersWithStringKeys(): void
    {
        $schema = $this->usersSchemaStringKey();
        $data = $this->usersDataString();

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users', $factory($schema, $data, 'users'));

            // eq with string key
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->eq('id', 'u1'));
            $this->assertCount(1, $result, "eq with string key failed for $factoryName");
            $this->assertSame('Alice', $result[0]->name);

            // in with string keys
            $result = iterator_to_array($vdb->query('SELECT * FROM users')->in('id', ['u1', 'u3'])->order('id'));
            $this->assertCount(2, $result, "in with string keys failed for $factoryName");
        }
    }

    // =========================================================================
    // JOIN TESTS - Test joins across database configurations
    // =========================================================================

    public function testJoinWithIntegerKeys(): void
    {
        $usersFixture = new TableFixture(
            'users',
            $this->usersSchemaIntKey(),
            $this->usersDataInt()
        );
        $ordersFixture = new TableFixture(
            'orders',
            $this->ordersSchemaIntKey(),
            $this->ordersDataInt()
        );

        foreach ($this->twoTableConfigs() as $configName => $config) {
            // Only test with in_memory factory for now to keep combinations manageable
            $tableFactory = $this->tableFactories()['in_memory'];

            [$usersQuery, $ordersQuery] = $config($usersFixture, $ordersFixture, $tableFactory);

            // Wrap in VDB to perform the join
            $vdb = new VirtualDatabase();
            $vdb->registerTable('u', $this->pq($usersQuery));
            $vdb->registerTable('o', $this->pq($ordersQuery));

            $result = iterator_to_array(
                $vdb->query('SELECT u.name, o.amount FROM u JOIN o ON u.id = o.user_id ORDER BY o.amount')
            );

            $this->assertCount(3, $result, "Join returned wrong count for $configName");
            $this->assertSame('Alice', $result[0]->name, "Join order wrong for $configName");
            $this->assertSame(100, $result[0]->amount, "Join amount wrong for $configName");
        }
    }

    public function testJoinWithStringKeys(): void
    {
        $usersFixture = new TableFixture(
            'users',
            $this->usersSchemaStringKey(),
            $this->usersDataString()
        );
        $ordersFixture = new TableFixture(
            'orders',
            $this->ordersSchemaStringKey(),
            $this->ordersDataString()
        );

        foreach ($this->twoTableConfigs() as $configName => $config) {
            $tableFactory = $this->tableFactories()['in_memory'];

            [$usersQuery, $ordersQuery] = $config($usersFixture, $ordersFixture, $tableFactory);

            $vdb = new VirtualDatabase();
            $vdb->registerTable('u', $this->pq($usersQuery));
            $vdb->registerTable('o', $this->pq($ordersQuery));

            $result = iterator_to_array(
                $vdb->query('SELECT u.name, o.amount FROM u JOIN o ON u.id = o.user_id ORDER BY o.amount')
            );

            $this->assertCount(3, $result, "Join with string keys returned wrong count for $configName");
        }
    }

    // =========================================================================
    // FULL MATRIX TEST - All factories × all configs
    // =========================================================================

    public function testJoinMatrixIntegerKeys(): void
    {
        $usersFixture = new TableFixture(
            'users',
            $this->usersSchemaIntKey(),
            $this->usersDataInt()
        );
        $ordersFixture = new TableFixture(
            'orders',
            $this->ordersSchemaIntKey(),
            $this->ordersDataInt()
        );

        $factories = $this->tableFactories();
        $configs = $this->twoTableConfigs();

        foreach ($configs as $configName => $config) {
            foreach ($factories as $factoryName => $factory) {
                $label = "$configName + $factoryName";

                [$usersQuery, $ordersQuery] = $config($usersFixture, $ordersFixture, $factory);

                // Wrap in VDB to perform the join
                $vdb = new VirtualDatabase();
                $vdb->registerTable('u', $this->pq($usersQuery));
                $vdb->registerTable('o', $this->pq($ordersQuery));

                $result = iterator_to_array(
                    $vdb->query('SELECT u.name, o.amount FROM u JOIN o ON u.id = o.user_id ORDER BY o.amount')
                );

                $this->assertCount(3, $result, "Join count wrong for $label");

                // Verify join correctness: Alice has 2 orders, Bob has 1
                $aliceOrders = array_filter($result, fn($r) => $r->name === 'Alice');
                $this->assertCount(2, $aliceOrders, "Alice should have 2 orders for $label");
            }
        }
    }

    // =========================================================================
    // UNION TESTS
    // =========================================================================

    public function testUnionAcrossTableImplementations(): void
    {
        $schema = $this->usersSchemaIntKey();

        $dataA = [
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
        ];
        $dataB = [
            ['id' => 3, 'name' => 'Charlie', 'age' => 35],
            ['id' => 4, 'name' => 'Diana', 'age' => 28],
        ];

        foreach ($this->tableFactories() as $factoryName => $factory) {
            $vdb = new VirtualDatabase();
            $vdb->registerTable('users_a', $factory($schema, $dataA, 'users_a'));
            $vdb->registerTable('users_b', $factory($schema, $dataB, 'users_b'));

            $result = iterator_to_array(
                $vdb->query('SELECT * FROM users_a UNION SELECT * FROM users_b ORDER BY id')
            );

            $this->assertCount(4, $result, "Union count wrong for $factoryName");
            $this->assertSame('Alice', $result[0]->name);
            $this->assertSame('Diana', $result[3]->name);
        }
    }

    public function testUnionAcrossMixedTableTypes(): void
    {
        $schema = $this->usersSchemaIntKey();

        $dataA = [
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
        ];
        $dataB = [
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
        ];

        $factories = $this->tableFactories();

        // Test union between different table implementations
        foreach ($factories as $factoryNameA => $factoryA) {
            foreach ($factories as $factoryNameB => $factoryB) {
                $label = "$factoryNameA + $factoryNameB";

                $vdb = new VirtualDatabase();
                $vdb->registerTable('users_a', $factoryA($schema, $dataA, 'users_a'));
                $vdb->registerTable('users_b', $factoryB($schema, $dataB, 'users_b'));

                $result = iterator_to_array(
                    $vdb->query('SELECT * FROM users_a UNION SELECT * FROM users_b ORDER BY id')
                );

                $this->assertCount(2, $result, "Union count wrong for $label");
            }
        }
    }

    // =========================================================================
    // EXPRESSION TESTS - Compare PDO vs VDB for SQL expressions
    // =========================================================================

    /**
     * Create both PDO and VDB databases with identical test data
     *
     * @return array{pdo: PDODatabase, vdb: VirtualDatabase}
     */
    private function createMatchingDatabases(): array
    {
        $schema = new Schema([
            'id' => [ColumnType::Int, IndexType::Primary],
            'a' => [ColumnType::Int],
            'b' => [ColumnType::Int],
            'name' => [ColumnType::Text],
        ]);

        $data = [
            ['id' => 1, 'a' => 10, 'b' => 5, 'name' => 'Alice'],
            ['id' => 2, 'a' => 20, 'b' => 3, 'name' => 'Bob'],
            ['id' => 3, 'a' => 15, 'b' => 8, 'name' => 'Charlie'],
            ['id' => 4, 'a' => 5, 'b' => 10, 'name' => 'Diana'],
        ];

        // PDO with native SQLite
        $pdo = $schema->createInPdo('t', $data);

        // VDB with InMemoryTable (also SQLite-backed, so should match)
        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $schema->createInMemoryTable($data));

        return ['pdo' => $pdo, 'vdb' => $vdb];
    }

    /**
     * Run same SQL on both databases and compare results
     */
    private function assertSameResults(string $sql, string $description): void
    {
        ['pdo' => $pdo, 'vdb' => $vdb] = $this->createMatchingDatabases();

        $pdoResult = iterator_to_array($pdo->query($sql));
        $vdbResult = iterator_to_array($vdb->query($sql));

        $this->assertSame(
            count($pdoResult),
            count($vdbResult),
            "$description: row count mismatch"
        );

        // Compare row by row
        foreach ($pdoResult as $i => $pdoRow) {
            $vdbRow = $vdbResult[$i];
            foreach ((array) $pdoRow as $col => $pdoVal) {
                $vdbVal = $vdbRow->$col ?? null;

                // Handle boolean comparison (SQLite returns 0/1, PHP might have true/false)
                if (($pdoVal === 0 || $pdoVal === 1 || $pdoVal === '0' || $pdoVal === '1') &&
                    ($vdbVal === true || $vdbVal === false || $vdbVal === 0 || $vdbVal === 1)) {
                    $pdoBool = (bool) $pdoVal;
                    $vdbBool = (bool) $vdbVal;
                    $this->assertSame(
                        $pdoBool,
                        $vdbBool,
                        "$description: row $i column '$col' boolean mismatch (PDO: $pdoVal, VDB: " . var_export($vdbVal, true) . ")"
                    );
                }
                // Handle numeric comparison (int vs string, float precision)
                elseif (is_numeric($pdoVal) && is_numeric($vdbVal)) {
                    // Compare as strings first to preserve large integer precision
                    // (casting to float loses precision beyond 2^53)
                    if ((string) $pdoVal === (string) $vdbVal) {
                        continue; // Exact match
                    }
                    // Fall back to approximate float comparison for actual floats
                    $diff = abs((float) $pdoVal - (float) $vdbVal);
                    $this->assertTrue(
                        $diff < 0.0001,
                        "$description: row $i column '$col' numeric mismatch (PDO: $pdoVal, VDB: $vdbVal)"
                    );
                } else {
                    $this->assertSame(
                        $pdoVal,
                        $vdbVal,
                        "$description: row $i column '$col' mismatch (PDO: " . var_export($pdoVal, true) . ", VDB: " . var_export($vdbVal, true) . ")"
                    );
                }
            }
        }
    }

    public function testSelectArithmeticExpressions(): void
    {
        $expressions = [
            'SELECT a + 1 AS result FROM t ORDER BY id' => 'addition',
            'SELECT a - 1 AS result FROM t ORDER BY id' => 'subtraction',
            'SELECT a * 2 AS result FROM t ORDER BY id' => 'multiplication',
            // Note: division differs - SQLite uses integer division, VDB uses float
            // 'SELECT a / 2 AS result FROM t ORDER BY id' => 'division',
            'SELECT a + b AS result FROM t ORDER BY id' => 'two columns addition',
            'SELECT a * 2 + b AS result FROM t ORDER BY id' => 'compound expression',
            'SELECT (a + b) * 2 AS result FROM t ORDER BY id' => 'parenthesized expression',
            'SELECT -a AS result FROM t ORDER BY id' => 'unary minus',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    /**
     * Test division behavior difference between SQLite and VDB
     *
     * SQLite uses integer division: 15 / 2 = 7
     * VDB uses float division: 15 / 2 = 7.5
     *
     * This documents the known behavioral difference.
     */
    public function testDivisionBehaviorDifference(): void
    {
        ['pdo' => $pdo, 'vdb' => $vdb] = $this->createMatchingDatabases();

        // SQLite integer division
        $pdoResult = iterator_to_array($pdo->query('SELECT a / 2 AS result FROM t ORDER BY id'));
        // VDB float division
        $vdbResult = iterator_to_array($vdb->query('SELECT a / 2 AS result FROM t ORDER BY id'));

        // Row 3: a=15 -> SQLite: 7, VDB: 7.5
        $this->assertSame(7.0, (float) $pdoResult[2]->result, 'SQLite should use integer division');
        $this->assertSame(7.5, (float) $vdbResult[2]->result, 'VDB uses float division');
    }

    public function testWhereArithmeticExpressions(): void
    {
        $expressions = [
            'SELECT * FROM t WHERE a + 1 = 11 ORDER BY id' => 'addition in WHERE',
            'SELECT * FROM t WHERE a - 5 = 5 ORDER BY id' => 'subtraction in WHERE',
            'SELECT * FROM t WHERE a * 2 = 40 ORDER BY id' => 'multiplication in WHERE',
            'SELECT * FROM t WHERE a / 2 = 10 ORDER BY id' => 'division in WHERE',
            'SELECT * FROM t WHERE a + b = 15 ORDER BY id' => 'two columns in WHERE',
            'SELECT * FROM t WHERE a = 10 - 0 ORDER BY id' => 'expression on right side',
            'SELECT * FROM t WHERE a + b > 20 ORDER BY id' => 'expression with comparison',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testOrderByExpressions(): void
    {
        $expressions = [
            'SELECT * FROM t ORDER BY a + b' => 'ORDER BY addition',
            'SELECT * FROM t ORDER BY a * 2' => 'ORDER BY multiplication',
            'SELECT * FROM t ORDER BY a - b' => 'ORDER BY subtraction',
            'SELECT * FROM t ORDER BY a + b DESC' => 'ORDER BY expression DESC',
            'SELECT * FROM t ORDER BY a * 2 + b' => 'ORDER BY compound expression',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testColumnAliases(): void
    {
        $expressions = [
            'SELECT a + 1 AS a_plus_one FROM t ORDER BY id' => 'simple alias',
            'SELECT a AS x, b AS y FROM t ORDER BY id' => 'multiple aliases',
            'SELECT a + b AS sum, a * b AS product FROM t ORDER BY id' => 'expression aliases',
            'SELECT *, a + b AS total FROM t ORDER BY id' => 'star with alias',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testStringExpressions(): void
    {
        $expressions = [
            "SELECT name || '!' AS result FROM t ORDER BY id" => 'string concatenation',
            "SELECT 'Hello ' || name AS result FROM t ORDER BY id" => 'string prefix concat',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testComparisonExpressions(): void
    {
        $expressions = [
            'SELECT a > b AS result FROM t ORDER BY id' => 'greater than comparison',
            'SELECT a < b AS result FROM t ORDER BY id' => 'less than comparison',
            'SELECT a = b AS result FROM t ORDER BY id' => 'equality comparison',
            'SELECT a >= b AS result FROM t ORDER BY id' => 'greater or equal',
            'SELECT a <= b AS result FROM t ORDER BY id' => 'less or equal',
            'SELECT a != b AS result FROM t ORDER BY id' => 'not equal',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testNullHandling(): void
    {
        // Create databases with NULL values
        $schema = new Schema([
            'id' => [ColumnType::Int, IndexType::Primary],
            'val' => [ColumnType::Int],
        ]);

        $data = [
            ['id' => 1, 'val' => 10],
            ['id' => 2, 'val' => null],
            ['id' => 3, 'val' => 20],
        ];

        $pdo = $schema->createInPdo('t', $data);
        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $schema->createInMemoryTable($data));

        // NULL comparisons
        $pdoResult = iterator_to_array($pdo->query('SELECT * FROM t WHERE val IS NULL'));
        $vdbResult = iterator_to_array($vdb->query('SELECT * FROM t WHERE val IS NULL'));
        $this->assertCount(count($pdoResult), $vdbResult, 'IS NULL count mismatch');

        $pdoResult = iterator_to_array($pdo->query('SELECT * FROM t WHERE val IS NOT NULL ORDER BY id'));
        $vdbResult = iterator_to_array($vdb->query('SELECT * FROM t WHERE val IS NOT NULL ORDER BY id'));
        $this->assertCount(count($pdoResult), $vdbResult, 'IS NOT NULL count mismatch');
    }

    public function testCaseExpressions(): void
    {
        $expressions = [
            'SELECT CASE WHEN a > 10 THEN \'high\' ELSE \'low\' END AS level FROM t ORDER BY id'
                => 'simple CASE WHEN',
            'SELECT CASE WHEN a > 15 THEN \'high\' WHEN a > 5 THEN \'medium\' ELSE \'low\' END AS level FROM t ORDER BY id'
                => 'multiple WHEN clauses',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testBuiltinFunctions(): void
    {
        $expressions = [
            'SELECT ABS(-5) AS result FROM t LIMIT 1' => 'ABS function',
            'SELECT COALESCE(NULL, 1) AS result FROM t LIMIT 1' => 'COALESCE function',
            'SELECT UPPER(name) AS result FROM t ORDER BY id' => 'UPPER function',
            'SELECT LOWER(name) AS result FROM t ORDER BY id' => 'LOWER function',
            'SELECT LENGTH(name) AS result FROM t ORDER BY id' => 'LENGTH function',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testAggregateExpressions(): void
    {
        $expressions = [
            'SELECT SUM(a) AS result FROM t' => 'SUM aggregate',
            'SELECT AVG(a) AS result FROM t' => 'AVG aggregate',
            'SELECT MIN(a) AS result FROM t' => 'MIN aggregate',
            'SELECT MAX(a) AS result FROM t' => 'MAX aggregate',
            'SELECT COUNT(*) AS result FROM t' => 'COUNT star',
            'SELECT COUNT(a) AS result FROM t' => 'COUNT column',
            'SELECT SUM(a + b) AS result FROM t' => 'SUM with expression',
        ];

        foreach ($expressions as $sql => $desc) {
            $this->assertSameResults($sql, $desc);
        }
    }

    public function testGroupByExpressions(): void
    {
        // Need different data for GROUP BY tests
        $schema = new Schema([
            'id' => [ColumnType::Int, IndexType::Primary],
            'category' => [ColumnType::Text],
            'amount' => [ColumnType::Int],
        ]);

        $data = [
            ['id' => 1, 'category' => 'A', 'amount' => 10],
            ['id' => 2, 'category' => 'A', 'amount' => 20],
            ['id' => 3, 'category' => 'B', 'amount' => 15],
            ['id' => 4, 'category' => 'B', 'amount' => 25],
        ];

        $pdo = $schema->createInPdo('t', $data);
        $vdb = new VirtualDatabase();
        $vdb->registerTable('t', $schema->createInMemoryTable($data));

        $expressions = [
            'SELECT category, SUM(amount) AS total FROM t GROUP BY category ORDER BY category'
                => 'GROUP BY with SUM',
            'SELECT category, COUNT(*) AS cnt FROM t GROUP BY category ORDER BY category'
                => 'GROUP BY with COUNT',
            'SELECT category, AVG(amount) AS avg FROM t GROUP BY category ORDER BY category'
                => 'GROUP BY with AVG',
        ];

        foreach ($expressions as $sql => $desc) {
            $pdoResult = iterator_to_array($pdo->query($sql));
            $vdbResult = iterator_to_array($vdb->query($sql));

            $this->assertSame(count($pdoResult), count($vdbResult), "$desc: row count mismatch");
        }
    }

    // =========================================================================
    // VDB REGRESSION TESTS - Document VDB's specific semantic choices
    // =========================================================================

    /**
     * VDB uses float division for int/int (unlike SQLite's integer division)
     *
     * This is intentional - VDB follows PHP/MySQL semantics, not SQLite.
     */
    public function testVdbDivisionSemantics(): void
    {
        $vdb = new VirtualDatabase();
        $table = new InMemoryTable(new ColumnDef('id', ColumnType::Int, IndexType::Primary));
        $table->insert(['id' => 1]);
        $vdb->registerTable('t', $table);

        // int / int returns float
        $result = iterator_to_array($vdb->query('SELECT 15 / 2 AS result FROM t'))[0];
        $this->assertSame(7.5, $result->result, 'VDB: 15/2 should be 7.5 (float division)');

        // Division with one float operand
        $result = iterator_to_array($vdb->query('SELECT 15.0 / 2 AS result FROM t'))[0];
        $this->assertSame(7.5, $result->result, 'VDB: 15.0/2 should be 7.5');

        $result = iterator_to_array($vdb->query('SELECT 15 / 2.0 AS result FROM t'))[0];
        $this->assertSame(7.5, $result->result, 'VDB: 15/2.0 should be 7.5');

        // 1/3 returns repeating decimal
        $result = iterator_to_array($vdb->query('SELECT 1 / 3 AS result FROM t'))[0];
        $this->assertTrue(
            abs($result->result - 0.3333333333333333) < 0.0000000001,
            'VDB: 1/3 should be ~0.333...'
        );
    }

    /**
     * VDB preserves integer precision up to PHP_INT_MAX (2^63-1)
     */
    public function testVdbIntegerPrecision(): void
    {
        $vdb = new VirtualDatabase();
        $table = new InMemoryTable(new ColumnDef('id', ColumnType::Int, IndexType::Primary));
        $table->insert(['id' => 1]);
        $vdb->registerTable('t', $table);

        // 2^53 + 1 - beyond float precision but within int64
        $result = iterator_to_array($vdb->query('SELECT 9007199254740993 AS result FROM t'))[0];
        $this->assertSame(9007199254740993, $result->result, 'VDB should preserve 2^53+1 as integer');
        $this->assertSame('integer', gettype($result->result), 'Type should be integer');

        // Large multiplication within int64 range
        // 2^30 * 2^30 = 2^60 (fits in int64, not in float53)
        $result = iterator_to_array($vdb->query('SELECT 1073741824 * 1073741824 AS result FROM t'))[0];
        $this->assertSame(1152921504606846976, $result->result, 'VDB: 2^30 * 2^30 should preserve precision');
        $this->assertSame('integer', gettype($result->result), 'Large multiplication should stay integer');

        // Addition preserves precision
        $result = iterator_to_array($vdb->query('SELECT 9007199254740993 + 1 AS result FROM t'))[0];
        $this->assertSame(9007199254740994, $result->result, 'VDB: 2^53+1 + 1 should preserve precision');
    }

    /**
     * VDB overflows to float beyond int64 (PHP behavior)
     */
    public function testVdbIntegerOverflow(): void
    {
        $vdb = new VirtualDatabase();
        $table = new InMemoryTable(new ColumnDef('id', ColumnType::Int, IndexType::Primary));
        $table->insert(['id' => 1]);
        $vdb->registerTable('t', $table);

        // 2^32 * 2^32 = 2^64 - overflows int64, becomes float
        $result = iterator_to_array($vdb->query('SELECT 4294967296 * 4294967296 AS result FROM t'))[0];
        $this->assertSame('double', gettype($result->result), 'Overflow should convert to float');
        // Note: precision is lost, but this is expected PHP behavior
    }

    /**
     * VDB literal type inference: integers stay integers, decimals become floats
     */
    public function testVdbLiteralTypes(): void
    {
        $vdb = new VirtualDatabase();
        $table = new InMemoryTable(new ColumnDef('id', ColumnType::Int, IndexType::Primary));
        $table->insert(['id' => 1]);
        $vdb->registerTable('t', $table);

        // Integer literal
        $result = iterator_to_array($vdb->query('SELECT 42 AS result FROM t'))[0];
        $this->assertSame(42, $result->result);
        $this->assertSame('integer', gettype($result->result), '42 should be integer');

        // Float literal (has decimal point)
        $result = iterator_to_array($vdb->query('SELECT 42.0 AS result FROM t'))[0];
        $this->assertSame(42.0, $result->result);
        $this->assertSame('double', gettype($result->result), '42.0 should be float');

        // Negative integer
        $result = iterator_to_array($vdb->query('SELECT -42 AS result FROM t'))[0];
        $this->assertSame(-42, $result->result);
        $this->assertSame('integer', gettype($result->result), '-42 should be integer');
    }

    /**
     * VDB arithmetic type promotion follows PHP rules
     */
    public function testVdbArithmeticTypePromotion(): void
    {
        $vdb = new VirtualDatabase();
        $table = new InMemoryTable(new ColumnDef('id', ColumnType::Int, IndexType::Primary));
        $table->insert(['id' => 1]);
        $vdb->registerTable('t', $table);

        // int + int = int
        $result = iterator_to_array($vdb->query('SELECT 10 + 5 AS result FROM t'))[0];
        $this->assertSame('integer', gettype($result->result), 'int + int should be integer');

        // int + float = float
        $result = iterator_to_array($vdb->query('SELECT 10 + 5.0 AS result FROM t'))[0];
        $this->assertSame('double', gettype($result->result), 'int + float should be float');

        // int * int = int
        $result = iterator_to_array($vdb->query('SELECT 10 * 5 AS result FROM t'))[0];
        $this->assertSame('integer', gettype($result->result), 'int * int should be integer');

        // int / int = int when exact, float when not (PHP behavior)
        $result = iterator_to_array($vdb->query('SELECT 10 / 5 AS result FROM t'))[0];
        $this->assertSame('integer', gettype($result->result), 'Exact division returns int');
        $this->assertSame(2, $result->result, '10 / 5 = 2');

        $result = iterator_to_array($vdb->query('SELECT 10 / 3 AS result FROM t'))[0];
        $this->assertSame('double', gettype($result->result), 'Non-exact division returns float');
        $this->assertTrue(abs($result->result - 3.3333333333) < 0.0001, '10 / 3 ≈ 3.333...');

        // int % int = int
        $result = iterator_to_array($vdb->query('SELECT 10 % 3 AS result FROM t'))[0];
        $this->assertSame('integer', gettype($result->result), 'int % int should be integer');
        $this->assertSame(1, $result->result, '10 % 3 = 1');
    }

    // =========================================================================
    // EXPRESSION EVALUATION REGRESSION TESTS
    // =========================================================================

    /**
     * Helper to create a VDB with test data for expression tests
     */
    private function createExpressionTestVdb(): VirtualDatabase
    {
        $vdb = new VirtualDatabase();

        $users = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('score', ColumnType::Float),
            new ColumnDef('active', ColumnType::Int),
            new ColumnDef('notes', ColumnType::Text),
        );
        $users->insert(['id' => 1, 'name' => 'Alice', 'age' => 30, 'score' => 85.5, 'active' => 1, 'notes' => 'First user']);
        $users->insert(['id' => 2, 'name' => 'Bob', 'age' => 25, 'score' => 92.0, 'active' => 1, 'notes' => null]);
        $users->insert(['id' => 3, 'name' => 'Charlie', 'age' => 35, 'score' => 78.5, 'active' => 0, 'notes' => 'Inactive']);
        $users->insert(['id' => 4, 'name' => 'Diana', 'age' => 28, 'score' => null, 'active' => 1, 'notes' => '']);
        $vdb->registerTable('users', $users);

        return $vdb;
    }

    /**
     * Helper to get single result value
     */
    private function queryOne(VirtualDatabase $vdb, string $sql): mixed
    {
        $rows = iterator_to_array($vdb->query($sql));
        return $rows[0]->result ?? null;
    }

    // -------------------------------------------------------------------------
    // Arithmetic Operators
    // -------------------------------------------------------------------------

    public function testArithmeticAddition(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Literal + literal
        $this->assertSame(15, $this->queryOne($vdb, 'SELECT 10 + 5 AS result FROM users LIMIT 1'));

        // Column + literal
        $this->assertSame(31, $this->queryOne($vdb, 'SELECT age + 1 AS result FROM users WHERE id = 1'));

        // Column + column
        $this->assertSame(115.5, $this->queryOne($vdb, 'SELECT age + score AS result FROM users WHERE id = 1'));

        // Negative numbers
        $this->assertSame(5, $this->queryOne($vdb, 'SELECT 10 + -5 AS result FROM users LIMIT 1'));

        // Float + int
        $this->assertSame(15.5, $this->queryOne($vdb, 'SELECT 10.5 + 5 AS result FROM users LIMIT 1'));
    }

    public function testArithmeticSubtraction(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(5, $this->queryOne($vdb, 'SELECT 10 - 5 AS result FROM users LIMIT 1'));
        $this->assertSame(29, $this->queryOne($vdb, 'SELECT age - 1 AS result FROM users WHERE id = 1'));
        $this->assertSame(-5, $this->queryOne($vdb, 'SELECT 5 - 10 AS result FROM users LIMIT 1'));
        $this->assertSame(15, $this->queryOne($vdb, 'SELECT 10 - -5 AS result FROM users LIMIT 1'));
    }

    public function testArithmeticMultiplication(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(50, $this->queryOne($vdb, 'SELECT 10 * 5 AS result FROM users LIMIT 1'));
        $this->assertSame(60, $this->queryOne($vdb, 'SELECT age * 2 AS result FROM users WHERE id = 1'));
        $this->assertSame(-50, $this->queryOne($vdb, 'SELECT 10 * -5 AS result FROM users LIMIT 1'));
        $this->assertSame(52.5, $this->queryOne($vdb, 'SELECT 10.5 * 5 AS result FROM users LIMIT 1'));
    }

    public function testArithmeticDivision(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Exact division returns int
        $this->assertSame(2, $this->queryOne($vdb, 'SELECT 10 / 5 AS result FROM users LIMIT 1'));

        // Non-exact returns float
        $result = $this->queryOne($vdb, 'SELECT 10 / 3 AS result FROM users LIMIT 1');
        $this->assertTrue(abs($result - 3.333333) < 0.0001);

        // Division by zero returns null
        $this->assertNull($this->queryOne($vdb, 'SELECT 10 / 0 AS result FROM users LIMIT 1'));

        // Float division
        $this->assertSame(2.1, $this->queryOne($vdb, 'SELECT 10.5 / 5 AS result FROM users LIMIT 1'));
    }

    public function testArithmeticModulo(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(1, $this->queryOne($vdb, 'SELECT 10 % 3 AS result FROM users LIMIT 1'));
        $this->assertSame(0, $this->queryOne($vdb, 'SELECT 10 % 5 AS result FROM users LIMIT 1'));
        $this->assertSame(0, $this->queryOne($vdb, 'SELECT age % 5 AS result FROM users WHERE id = 1'));

        // Modulo by zero returns null
        $this->assertNull($this->queryOne($vdb, 'SELECT 10 % 0 AS result FROM users LIMIT 1'));
    }

    public function testArithmeticPrecedence(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Multiplication before addition
        $this->assertSame(17, $this->queryOne($vdb, 'SELECT 2 + 3 * 5 AS result FROM users LIMIT 1'));

        // Parentheses override
        $this->assertSame(25, $this->queryOne($vdb, 'SELECT (2 + 3) * 5 AS result FROM users LIMIT 1'));

        // Complex expression
        $this->assertSame(14, $this->queryOne($vdb, 'SELECT 2 * 3 + 4 * 2 AS result FROM users LIMIT 1'));

        // Nested parentheses
        $this->assertSame(20, $this->queryOne($vdb, 'SELECT ((2 + 3) * 2) * 2 AS result FROM users LIMIT 1'));
    }

    public function testUnaryOperators(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Unary minus
        $this->assertSame(-10, $this->queryOne($vdb, 'SELECT -10 AS result FROM users LIMIT 1'));
        $this->assertSame(-30, $this->queryOne($vdb, 'SELECT -age AS result FROM users WHERE id = 1'));

        // Double negative (use parentheses since -- is SQL comment)
        $this->assertSame(10, $this->queryOne($vdb, 'SELECT -(-10) AS result FROM users LIMIT 1'));

        // Unary plus (no-op)
        $this->assertSame(10, $this->queryOne($vdb, 'SELECT +10 AS result FROM users LIMIT 1'));
        $this->assertSame(30, $this->queryOne($vdb, 'SELECT +age AS result FROM users WHERE id = 1'));
    }

    // -------------------------------------------------------------------------
    // Comparison Operators
    // -------------------------------------------------------------------------

    public function testComparisonEquals(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // True comparisons return 1
        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 = 5 AS result FROM users LIMIT 1'));
        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT age = 30 AS result FROM users WHERE id = 1'));

        // False comparisons return 0
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 = 6 AS result FROM users LIMIT 1'));

        // String comparison
        $this->assertSame(true, (bool) $this->queryOne($vdb, "SELECT name = 'Alice' AS result FROM users WHERE id = 1"));
    }

    public function testComparisonNotEquals(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 != 6 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 != 5 AS result FROM users LIMIT 1'));

        // <> syntax
        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 <> 6 AS result FROM users LIMIT 1'));
    }

    public function testComparisonLessThan(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 < 10 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 10 < 5 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 < 5 AS result FROM users LIMIT 1'));
    }

    public function testComparisonLessOrEqual(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 <= 10 AS result FROM users LIMIT 1'));
        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 <= 5 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 10 <= 5 AS result FROM users LIMIT 1'));
    }

    public function testComparisonGreaterThan(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 10 > 5 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 > 10 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 > 5 AS result FROM users LIMIT 1'));
    }

    public function testComparisonGreaterOrEqual(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 10 >= 5 AS result FROM users LIMIT 1'));
        $this->assertSame(true, (bool) $this->queryOne($vdb, 'SELECT 5 >= 5 AS result FROM users LIMIT 1'));
        $this->assertSame(false, (bool) $this->queryOne($vdb, 'SELECT 5 >= 10 AS result FROM users LIMIT 1'));
    }

    // -------------------------------------------------------------------------
    // Logical Operators
    // -------------------------------------------------------------------------

    public function testLogicalAnd(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Both true
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age > 20 AND active = 1'));
        $this->assertCount(3, $rows); // Alice, Bob, Diana

        // One false
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age > 30 AND active = 1'));
        $this->assertCount(0, $rows);

        // Short-circuit: if first is false, second not evaluated
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE 1 = 0 AND age > 0'));
        $this->assertCount(0, $rows);
    }

    public function testLogicalOr(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Either true
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age > 32 OR name = \'Bob\''));
        $this->assertCount(2, $rows); // Bob, Charlie

        // Both false
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age > 100 OR name = \'Nobody\''));
        $this->assertCount(0, $rows);
    }

    public function testLogicalNot(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE NOT active = 1'));
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE NOT (age < 30)'));
        $this->assertCount(2, $rows); // Alice (30), Charlie (35)
    }

    public function testLogicalPrecedence(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // AND has higher precedence than OR
        // This means: (age > 30) OR (active = 1 AND name = 'Bob')
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE age > 30 OR active = 1 AND name = 'Bob' ORDER BY id"
        ));
        $this->assertCount(2, $rows); // Bob (matches AND), Charlie (matches first OR)

        // Parentheses override
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE (age > 30 OR active = 1) AND name = 'Bob'"
        ));
        $this->assertCount(1, $rows); // Only Bob
    }

    // -------------------------------------------------------------------------
    // NULL Handling
    // -------------------------------------------------------------------------

    public function testNullArithmetic(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // NULL + anything = NULL
        $this->assertNull($this->queryOne($vdb, 'SELECT score + 10 AS result FROM users WHERE id = 4'));
        $this->assertNull($this->queryOne($vdb, 'SELECT NULL + 10 AS result FROM users LIMIT 1'));

        // NULL * anything = NULL
        $this->assertNull($this->queryOne($vdb, 'SELECT score * 2 AS result FROM users WHERE id = 4'));

        // NULL / anything = NULL
        $this->assertNull($this->queryOne($vdb, 'SELECT score / 2 AS result FROM users WHERE id = 4'));
    }

    public function testNullComparison(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // NULL = NULL is true in VDB (matches PHP behavior)
        // Note: Standard SQL says NULL = NULL is UNKNOWN, but VDB follows PHP
        $result = $this->queryOne($vdb, 'SELECT NULL = NULL AS result FROM users LIMIT 1');
        $this->assertSame(true, $result);

        // NULL != value
        $result = $this->queryOne($vdb, 'SELECT NULL != 5 AS result FROM users LIMIT 1');
        $this->assertSame(true, $result);
    }

    public function testIsNull(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE score IS NULL'));
        $this->assertCount(1, $rows);
        $this->assertSame('Diana', $rows[0]->name);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE notes IS NULL'));
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);
    }

    public function testIsNotNull(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE score IS NOT NULL ORDER BY id'));
        $this->assertCount(3, $rows);

        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE notes IS NOT NULL ORDER BY id'));
        $this->assertCount(3, $rows);
    }

    // -------------------------------------------------------------------------
    // String Operations
    // -------------------------------------------------------------------------

    public function testStringConcatenation(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // || operator
        $result = $this->queryOne($vdb, "SELECT 'Hello' || ' ' || 'World' AS result FROM users LIMIT 1");
        $this->assertSame('Hello World', $result);

        // With column
        $result = $this->queryOne($vdb, "SELECT 'User: ' || name AS result FROM users WHERE id = 1");
        $this->assertSame('User: Alice', $result);

        // Number to string
        $result = $this->queryOne($vdb, "SELECT name || ' is ' || age AS result FROM users WHERE id = 1");
        $this->assertSame('Alice is 30', $result);
    }

    public function testLikeOperator(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // % matches any sequence
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name LIKE 'A%'"));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);

        // % at both ends
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name LIKE '%li%'"));
        $this->assertCount(2, $rows); // Alice, Charlie

        // _ matches single character
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name LIKE '_ob'"));
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);

        // NOT LIKE
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name NOT LIKE 'A%' ORDER BY id"));
        $this->assertCount(3, $rows);
    }

    // -------------------------------------------------------------------------
    // IN Operator
    // -------------------------------------------------------------------------

    public function testInOperator(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Integer list
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE id IN (1, 3) ORDER BY id'));
        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame('Charlie', $rows[1]->name);

        // String list
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE name IN ('Alice', 'Bob') ORDER BY id"));
        $this->assertCount(2, $rows);

        // NOT IN
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE id NOT IN (1, 2) ORDER BY id'));
        $this->assertCount(2, $rows);
        $this->assertSame('Charlie', $rows[0]->name);
        $this->assertSame('Diana', $rows[1]->name);

        // Single value
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE id IN (1)'));
        $this->assertCount(1, $rows);

        // Empty result
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE id IN (99, 100)'));
        $this->assertCount(0, $rows);
    }

    // -------------------------------------------------------------------------
    // BETWEEN Operator
    // -------------------------------------------------------------------------

    public function testBetweenOperator(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Inclusive range
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age BETWEEN 25 AND 30 ORDER BY id'));
        $this->assertCount(3, $rows); // Alice (30), Bob (25), Diana (28)

        // NOT BETWEEN
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE age NOT BETWEEN 25 AND 30 ORDER BY id'));
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);

        // With floats
        $rows = iterator_to_array($vdb->query('SELECT * FROM users WHERE score BETWEEN 80.0 AND 90.0 ORDER BY id'));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }

    // -------------------------------------------------------------------------
    // CASE WHEN Expressions
    // -------------------------------------------------------------------------

    public function testCaseWhenSimple(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $result = $this->queryOne($vdb,
            "SELECT CASE WHEN age > 30 THEN 'old' ELSE 'young' END AS result FROM users WHERE id = 1"
        );
        $this->assertSame('young', $result); // Alice is 30, not > 30

        $result = $this->queryOne($vdb,
            "SELECT CASE WHEN age > 30 THEN 'old' ELSE 'young' END AS result FROM users WHERE id = 3"
        );
        $this->assertSame('old', $result); // Charlie is 35
    }

    public function testCaseWhenMultiple(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $sql = "SELECT CASE
            WHEN age < 26 THEN 'young'
            WHEN age < 31 THEN 'middle'
            ELSE 'senior'
        END AS result FROM users WHERE id = ?";

        // Test each case
        $rows = iterator_to_array($vdb->query(str_replace('?', '2', $sql))); // Bob, 25
        $this->assertSame('young', $rows[0]->result);

        $rows = iterator_to_array($vdb->query(str_replace('?', '1', $sql))); // Alice, 30
        $this->assertSame('middle', $rows[0]->result);

        $rows = iterator_to_array($vdb->query(str_replace('?', '3', $sql))); // Charlie, 35
        $this->assertSame('senior', $rows[0]->result);
    }

    public function testCaseWhenNoElse(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Without ELSE, unmatched cases return NULL
        $result = $this->queryOne($vdb,
            "SELECT CASE WHEN age > 100 THEN 'ancient' END AS result FROM users WHERE id = 1"
        );
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Built-in Functions
    // -------------------------------------------------------------------------

    public function testStringFunctions(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // UPPER
        $this->assertSame('ALICE', $this->queryOne($vdb, "SELECT UPPER(name) AS result FROM users WHERE id = 1"));

        // LOWER
        $this->assertSame('alice', $this->queryOne($vdb, "SELECT LOWER(name) AS result FROM users WHERE id = 1"));

        // LENGTH
        $this->assertSame(5, $this->queryOne($vdb, "SELECT LENGTH(name) AS result FROM users WHERE id = 1"));

        // TRIM
        $this->assertSame('hello', $this->queryOne($vdb, "SELECT TRIM('  hello  ') AS result FROM users LIMIT 1"));

        // SUBSTR
        $this->assertSame('Ali', $this->queryOne($vdb, "SELECT SUBSTR(name, 1, 3) AS result FROM users WHERE id = 1"));

        // REPLACE
        $this->assertSame('Alyce', $this->queryOne($vdb, "SELECT REPLACE(name, 'ic', 'yc') AS result FROM users WHERE id = 1"));
    }

    public function testNumericFunctions(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // ABS
        $this->assertSame(5, $this->queryOne($vdb, "SELECT ABS(-5) AS result FROM users LIMIT 1"));
        $this->assertSame(5.5, $this->queryOne($vdb, "SELECT ABS(-5.5) AS result FROM users LIMIT 1"));

        // ROUND
        $this->assertSame(86.0, $this->queryOne($vdb, "SELECT ROUND(score) AS result FROM users WHERE id = 1"));
        $this->assertSame(85.5, $this->queryOne($vdb, "SELECT ROUND(score, 1) AS result FROM users WHERE id = 1"));

        // FLOOR
        $this->assertSame(85.0, $this->queryOne($vdb, "SELECT FLOOR(score) AS result FROM users WHERE id = 1"));

        // CEIL
        $this->assertSame(86.0, $this->queryOne($vdb, "SELECT CEIL(score) AS result FROM users WHERE id = 1"));
    }

    public function testNullFunctions(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // COALESCE - returns first non-null (preserves type of the value returned)
        $this->assertSame(100, $this->queryOne($vdb, "SELECT COALESCE(score, 100) AS result FROM users WHERE id = 4"));
        $this->assertSame(85.5, $this->queryOne($vdb, "SELECT COALESCE(score, 100) AS result FROM users WHERE id = 1"));
        $this->assertSame(1, $this->queryOne($vdb, "SELECT COALESCE(NULL, NULL, 1) AS result FROM users LIMIT 1"));

        // IFNULL
        $this->assertSame(0, $this->queryOne($vdb, "SELECT IFNULL(score, 0) AS result FROM users WHERE id = 4"));

        // NULLIF - returns NULL if args equal
        $this->assertNull($this->queryOne($vdb, "SELECT NULLIF(5, 5) AS result FROM users LIMIT 1"));
        $this->assertSame(5, $this->queryOne($vdb, "SELECT NULLIF(5, 6) AS result FROM users LIMIT 1"));
    }

    // -------------------------------------------------------------------------
    // Aggregate Functions
    // -------------------------------------------------------------------------

    public function testAggregateCount(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // COUNT(*)
        $this->assertSame(4, $this->queryOne($vdb, "SELECT COUNT(*) AS result FROM users"));

        // COUNT(column) - excludes NULL
        $this->assertSame(3, $this->queryOne($vdb, "SELECT COUNT(score) AS result FROM users"));

        // COUNT with WHERE
        $this->assertSame(3, $this->queryOne($vdb, "SELECT COUNT(*) AS result FROM users WHERE active = 1"));
    }

    public function testAggregateSum(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(118, $this->queryOne($vdb, "SELECT SUM(age) AS result FROM users"));

        // SUM ignores NULL
        $this->assertSame(256.0, $this->queryOne($vdb, "SELECT SUM(score) AS result FROM users"));

        // SUM with expression
        $this->assertSame(236, $this->queryOne($vdb, "SELECT SUM(age * 2) AS result FROM users"));
    }

    public function testAggregateAvg(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $result = $this->queryOne($vdb, "SELECT AVG(age) AS result FROM users");
        $this->assertSame(29.5, $result); // (30+25+35+28)/4

        // AVG ignores NULL
        $result = $this->queryOne($vdb, "SELECT AVG(score) AS result FROM users");
        $this->assertTrue(abs($result - 85.333333) < 0.001); // (85.5+92+78.5)/3
    }

    public function testAggregateMinMax(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $this->assertSame(25, $this->queryOne($vdb, "SELECT MIN(age) AS result FROM users"));
        $this->assertSame(35, $this->queryOne($vdb, "SELECT MAX(age) AS result FROM users"));

        // With NULL values
        $this->assertSame(78.5, $this->queryOne($vdb, "SELECT MIN(score) AS result FROM users"));
        $this->assertSame(92.0, $this->queryOne($vdb, "SELECT MAX(score) AS result FROM users"));

        // String min/max
        $this->assertSame('Alice', $this->queryOne($vdb, "SELECT MIN(name) AS result FROM users"));
        $this->assertSame('Diana', $this->queryOne($vdb, "SELECT MAX(name) AS result FROM users"));
    }

    // -------------------------------------------------------------------------
    // Column Aliases and References
    // -------------------------------------------------------------------------

    public function testColumnAliasesBasic(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $rows = iterator_to_array($vdb->query("SELECT name AS user_name, age AS user_age FROM users WHERE id = 1"));
        $this->assertSame('Alice', $rows[0]->user_name);
        $this->assertSame(30, $rows[0]->user_age);

        // Expression with alias
        $rows = iterator_to_array($vdb->query("SELECT age + 10 AS age_plus_ten FROM users WHERE id = 1"));
        $this->assertSame(40, $rows[0]->age_plus_ten);
    }

    public function testStarWithAdditionalColumns(): void
    {
        $vdb = $this->createExpressionTestVdb();

        $rows = iterator_to_array($vdb->query("SELECT *, age * 2 AS double_age FROM users WHERE id = 1"));
        $this->assertSame('Alice', $rows[0]->name);
        $this->assertSame(30, $rows[0]->age);
        $this->assertSame(60, $rows[0]->double_age);
    }

    // -------------------------------------------------------------------------
    // Complex Expressions
    // -------------------------------------------------------------------------

    public function testComplexExpressions(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Nested arithmetic with functions
        $result = $this->queryOne($vdb, "SELECT ABS(-5) * 2 + 3 AS result FROM users LIMIT 1");
        $this->assertSame(13, $result);

        // CASE inside expression
        $result = $this->queryOne($vdb,
            "SELECT age + CASE WHEN active = 1 THEN 10 ELSE 0 END AS result FROM users WHERE id = 1"
        );
        $this->assertSame(40, $result);

        // Multiple functions
        $result = $this->queryOne($vdb, "SELECT UPPER(SUBSTR(name, 1, 3)) AS result FROM users WHERE id = 1");
        $this->assertSame('ALI', $result);

        // Comparison in SELECT
        $result = $this->queryOne($vdb, "SELECT (age > 25) AS result FROM users WHERE id = 1");
        $this->assertSame(true, (bool) $result);
    }

    public function testExpressionInOrderBy(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Order by expression
        $rows = iterator_to_array($vdb->query("SELECT * FROM users ORDER BY age * -1"));
        $this->assertSame('Charlie', $rows[0]->name); // 35 * -1 = -35 (smallest)
        $this->assertSame('Bob', $rows[3]->name); // 25 * -1 = -25 (largest)

        // Order by computed column
        $rows = iterator_to_array($vdb->query("SELECT *, age + id AS sort_key FROM users ORDER BY age + id"));
        $this->assertSame('Bob', $rows[0]->name); // 25 + 2 = 27
    }

    public function testExpressionInWhere(): void
    {
        $vdb = $this->createExpressionTestVdb();

        // Arithmetic in WHERE
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE age * 2 > 60 ORDER BY id"));
        $this->assertCount(1, $rows);
        $this->assertSame('Charlie', $rows[0]->name);

        // Function in WHERE
        $rows = iterator_to_array($vdb->query("SELECT * FROM users WHERE LENGTH(name) = 3"));
        $this->assertCount(1, $rows);
        $this->assertSame('Bob', $rows[0]->name);

        // CASE in WHERE
        // Alice: active=1, age=30 → 30 > 28 → match
        // Bob: active=1, age=25 → 25 > 28 → no match
        // Charlie: active=0, age=35 → 0 > 28 → no match
        // Diana: active=1, age=28 → 28 > 28 → no match (not strictly greater)
        $rows = iterator_to_array($vdb->query(
            "SELECT * FROM users WHERE CASE WHEN active = 1 THEN age ELSE 0 END > 28 ORDER BY id"
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->name);
    }
};

exit($test->run());
