<?php
/**
 * Regression tests: comparison semantics must not depend on the plan
 *
 * The same predicate on the same data must give one answer regardless of
 * which table class backs the data and whether the optimizer could push the
 * predicate down. Discovered inconsistency: with a TEXT column holding
 * '5.0', `WHERE label = 5` matched on ArrayTable (PHP loose ==) but not on
 * InMemoryTable (SQLite TEXT affinity), and on the SAME InMemoryTable the
 * forced-evaluation spelling `label || '' = 5` matched while the pushable
 * spelling did not. Plan-dependent semantics is the silent-wrong-answer
 * class: the optimizer's routing decision changed query results.
 *
 * The spec here is SQLite itself: every case asserts that both table
 * classes, pushable and forced-evaluation spellings alike, return exactly
 * what a real SQLite database returns for the same schema, data and SQL.
 * SQLite's own asymmetry is preserved deliberately: a bare column operand
 * carries its affinity ('label = 5' coerces 5 to text), while an expression
 * operand does not ('label || '' = 5' compares by storage class).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ArrayTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private ?\PDO $oracle = null;

    protected function setUp(): void
    {
        $this->oracle = new \PDO('sqlite::memory:');
        $this->oracle->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->oracle->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, age INTEGER, label TEXT)');
        $this->oracle->exec("INSERT INTO people VALUES (1, 5, '5.0'), (2, 30, '30'), (3, 25, '25')");
    }

    private function makeVdb(string $tableClass): VirtualDatabase
    {
        $t = new $tableClass(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('label', ColumnType::Text),
        );
        $t->insert(['id' => 1, 'age' => 5,  'label' => '5.0']);
        $t->insert(['id' => 2, 'age' => 30, 'label' => '30']);
        $t->insert(['id' => 3, 'age' => 25, 'label' => '25']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('people', $t);
        return $vdb;
    }

    /**
     * Run $sql against real SQLite and both VDB table classes; every engine
     * answer must equal the oracle's.
     */
    private function assertMatchesSqlite(string $sql): void
    {
        $expected = array_map(
            fn($r) => (int) $r['id'],
            $this->oracle->query($sql)->fetchAll(\PDO::FETCH_ASSOC)
        );
        sort($expected);

        foreach ([InMemoryTable::class, ArrayTable::class] as $tableClass) {
            $got = array_map(
                fn($r) => (int) $r->id,
                iterator_to_array($this->makeVdb($tableClass)->query($sql))
            );
            sort($got);

            $short = (new \ReflectionClass($tableClass))->getShortName();
            $this->assertSame(
                $expected,
                $got,
                "$short disagrees with SQLite for: $sql (sqlite=[" . implode(',', $expected) . "], got=[" . implode(',', $got) . "])"
            );
        }
    }

    // -- INT column: string literal coerces via column affinity --------------

    public function testIntColumnEqStringLiteralPushable(): void
    {
        $this->assertMatchesSqlite("SELECT id FROM people WHERE age = '5'");
    }

    public function testIntColumnEqIntLiteralPushable(): void
    {
        $this->assertMatchesSqlite('SELECT id FROM people WHERE age = 5');
    }

    public function testIntColumnForcedEvalEqStringLiteral(): void
    {
        // age + 0 is an EXPRESSION: no affinity, storage-class comparison
        $this->assertMatchesSqlite("SELECT id FROM people WHERE age + 0 = '5'");
    }

    // -- TEXT column: numeric literal coerces to text via column affinity ----

    public function testTextColumnEqIntLiteralPushable(): void
    {
        // label affinity converts 5 -> '5'; '5.0' != '5', '30' != '5'
        $this->assertMatchesSqlite('SELECT id FROM people WHERE label = 5');
    }

    public function testTextColumnEqMatchingIntLiteralPushable(): void
    {
        // 30 -> '30' matches the stored '30'
        $this->assertMatchesSqlite('SELECT id FROM people WHERE label = 30');
    }

    public function testTextColumnEqStringLiteralPushable(): void
    {
        $this->assertMatchesSqlite("SELECT id FROM people WHERE label = '5.0'");
    }

    public function testTextColumnForcedEvalEqIntLiteral(): void
    {
        // label || '' is an EXPRESSION: text vs integer by storage class
        $this->assertMatchesSqlite("SELECT id FROM people WHERE label || '' = 5");
    }

    // -- Range comparisons: affinity vs storage class ------------------------

    public function testTextColumnGtIntLiteralPushable(): void
    {
        // affinity: 30 -> '30'; text comparison '25'>'30' false, '5.0'>'30' true
        $this->assertMatchesSqlite('SELECT id FROM people WHERE label > 30');
    }

    public function testTextColumnForcedEvalGtIntLiteral(): void
    {
        // no affinity: every TEXT outranks every number by storage class
        $this->assertMatchesSqlite("SELECT id FROM people WHERE label || '' > 30");
    }

    public function testIntColumnGtStringLiteralPushable(): void
    {
        $this->assertMatchesSqlite("SELECT id FROM people WHERE age > '10'");
    }

    // -- IN lists ------------------------------------------------------------

    public function testTextColumnInIntList(): void
    {
        $this->assertMatchesSqlite('SELECT id FROM people WHERE label IN (30, 25)');
    }

    public function testIntColumnInStringList(): void
    {
        $this->assertMatchesSqlite("SELECT id FROM people WHERE age IN ('5', '30')");
    }

    // -- OR fallback path: affinity must survive un-pushable plans -----------

    public function testTextColumnEqIntLiteralInOrFallback(): void
    {
        // != in an OR branch forces row-by-row evaluation of the whole OR;
        // 'label = 5' must still apply label's affinity there, because its
        // operand is a bare column - same answer as the pushable spelling.
        $this->assertMatchesSqlite('SELECT id FROM people WHERE label = 30 OR age != age');
    }
};

exit($test->run());
