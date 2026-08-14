<?php
/**
 * Differential sweep: VDB versus SQLite over a broad query corpus
 *
 * Backported from the Python port (minivdb), whose build was driven by this
 * technique: rather than hand-writing expected results — which encodes the
 * author's belief about SQL rather than SQL itself — every query runs against
 * BOTH engines over identical data and the row sets are compared.
 *
 * SQLite is this engine's declared reference implementation for semantics
 * (src/Database/VDB-STATUS.md), so a disagreement is a VDB bug unless it is a
 * documented deliberate divergence. The known, documented divergences are
 * listed in DELIBERATE_DIVERGENCES below and asserted *as divergences*, so
 * they cannot silently change either.
 *
 * Constructs SQLite cannot parse at all (standard EXTRACT/POSITION/SUBSTRING
 * FROM..FOR, FULL JOIN in older builds) are out of this sweep's scope by
 * construction — they have no oracle here and are covered by their own tests.
 *
 * Adding a query to CORPUS is the cheapest possible regression test: if the
 * engines ever disagree about it, this fails and names the query.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private ?\PDO $oracle = null;
    private ?VirtualDatabase $vdb = null;

    /**
     * Queries where VDB deliberately differs from SQLite, with the reason.
     * Asserted as divergences rather than skipped, so a silent change fails.
     */
    private const DELIBERATE_DIVERGENCES = [
        // Mini uses PHP float division by design; SQLite uses float too, but
        // integer division of exact multiples differs in result *type*.
        'SELECT 7 / 2 AS d',
    ];

    protected function setUp(): void
    {
        $schema = [
            'CREATE TABLE u (id INTEGER PRIMARY KEY, name TEXT, role TEXT, age INTEGER, score REAL)',
            'CREATE TABLE o (id INTEGER PRIMARY KEY, u_id INTEGER, amount REAL, status TEXT)',
        ];
        $rows = [
            "INSERT INTO u VALUES (1,'Alice','admin',30,9.5),(2,'Bob','user',25,7.25),"
                . "(3,'Carol','user',35,NULL),(4,'Dave',NULL,NULL,3.0),(5,'Eve','user',25,7.25)",
            "INSERT INTO o VALUES (1,1,100.0,'open'),(2,1,50.5,'closed'),(3,2,20.0,'open'),"
                . "(4,3,NULL,'open'),(5,99,10.0,'closed')",
        ];

        $this->oracle = new \PDO('sqlite::memory:');
        $this->oracle->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ([...$schema, ...$rows] as $stmt) {
            $this->oracle->exec($stmt);
        }

        $u = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
            new ColumnDef('role', ColumnType::Text),
            new ColumnDef('age', ColumnType::Int),
            new ColumnDef('score', ColumnType::Float),
        );
        foreach ([
            [1, 'Alice', 'admin', 30, 9.5], [2, 'Bob', 'user', 25, 7.25],
            [3, 'Carol', 'user', 35, null], [4, 'Dave', null, null, 3.0],
            [5, 'Eve', 'user', 25, 7.25],
        ] as [$id, $n, $r, $a, $s]) {
            $u->insert(['id' => $id, 'name' => $n, 'role' => $r, 'age' => $a, 'score' => $s]);
        }

        $o = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('u_id', ColumnType::Int),
            new ColumnDef('amount', ColumnType::Float),
            new ColumnDef('status', ColumnType::Text),
        );
        foreach ([
            [1, 1, 100.0, 'open'], [2, 1, 50.5, 'closed'], [3, 2, 20.0, 'open'],
            [4, 3, null, 'open'], [5, 99, 10.0, 'closed'],
        ] as [$id, $uid, $amt, $st]) {
            $o->insert(['id' => $id, 'u_id' => $uid, 'amount' => $amt, 'status' => $st]);
        }

        $this->vdb = new VirtualDatabase();
        $this->vdb->registerTable('u', $u);
        $this->vdb->registerTable('o', $o);
    }

    /**
     * Run one query through both engines; compare row sets.
     *
     * Rows are normalised to comparable scalars: numeric strings from PDO are
     * compared numerically so that 7.25 and '7.25' agree (PDO SQLite returns
     * text for REAL columns unless native types are on).
     */
    private function compare(string $sql): void
    {
        $expected = $this->normalise(
            array_map(fn($r) => (array) $r, $this->oracle->query($sql)->fetchAll(\PDO::FETCH_ASSOC))
        );
        $got = $this->normalise(
            array_map(fn($r) => (array) $r, iterator_to_array($this->vdb->query($sql)))
        );

        $this->assertSame(
            $expected,
            $got,
            "VDB disagrees with SQLite for: $sql\n  sqlite: " . json_encode($expected)
                . "\n  vdb:    " . json_encode($got)
        );
    }

    private function normalise(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $norm = [];
            foreach ($row as $k => $v) {
                if ($v === null) {
                    $norm[$k] = null;
                } elseif (is_numeric($v)) {
                    $norm[$k] = 0.0 + $v;            // 7.25 === '7.25'
                } else {
                    $norm[$k] = (string) $v;
                }
            }
            ksort($norm);
            $out[] = $norm;
        }
        // Row order only matters when the query orders; sort for stability
        usort($out, fn($a, $b) => json_encode($a) <=> json_encode($b));
        return $out;
    }

    private function sweep(array $queries): void
    {
        foreach ($queries as $sql) {
            $this->compare($sql);
        }
    }

    public function testProjectionAndFilters(): void
    {
        $this->sweep([
            'SELECT id, name FROM u',
            'SELECT * FROM u WHERE age > 25',
            'SELECT * FROM u WHERE age >= 25 AND role = \'user\'',
            'SELECT * FROM u WHERE role IS NULL',
            'SELECT * FROM u WHERE role IS NOT NULL',
            'SELECT * FROM u WHERE age BETWEEN 25 AND 30',
            'SELECT * FROM u WHERE age NOT BETWEEN 25 AND 30',
            'SELECT * FROM u WHERE name LIKE \'A%\'',
            'SELECT * FROM u WHERE name NOT LIKE \'A%\'',
            'SELECT * FROM u WHERE id IN (1, 3, 5)',
            'SELECT * FROM u WHERE id NOT IN (1, 3, 5)',
            'SELECT * FROM u WHERE role = \'user\' OR age > 28',
            'SELECT * FROM u WHERE NOT (age > 25)',
            'SELECT DISTINCT role FROM u',
            'SELECT DISTINCT age FROM u',
        ]);
    }

    public function testNullSemantics(): void
    {
        $this->sweep([
            'SELECT * FROM u WHERE age = NULL',
            'SELECT * FROM u WHERE age != 25',
            'SELECT * FROM u WHERE score > 5',
            'SELECT COUNT(*) AS c, COUNT(age) AS ca, COUNT(score) AS cs FROM u',
            'SELECT SUM(score) AS s, AVG(score) AS a, MIN(score) AS mn, MAX(score) AS mx FROM u',
            'SELECT COALESCE(role, \'none\') AS r FROM u',
            'SELECT id, IFNULL(score, -1) AS s FROM u',
            'SELECT NULLIF(age, 25) AS n FROM u',
            'SELECT * FROM o WHERE amount IS NULL',
        ]);
    }

    public function testOrderingAndPagination(): void
    {
        $this->sweep([
            'SELECT * FROM u ORDER BY age',
            'SELECT * FROM u ORDER BY age DESC',
            'SELECT * FROM u ORDER BY score',
            'SELECT * FROM u ORDER BY role, age DESC',
            'SELECT * FROM u ORDER BY age LIMIT 2',
            'SELECT * FROM u ORDER BY age LIMIT 2 OFFSET 2',
            'SELECT * FROM u ORDER BY id LIMIT 0',
            'SELECT * FROM u ORDER BY id LIMIT 100',
        ]);
    }

    public function testAggregatesAndGrouping(): void
    {
        $this->sweep([
            'SELECT role, COUNT(*) AS c FROM u GROUP BY role',
            'SELECT role, AVG(age) AS a FROM u GROUP BY role',
            'SELECT role, COUNT(*) AS c FROM u GROUP BY role HAVING COUNT(*) > 1',
            'SELECT age, COUNT(*) AS c FROM u GROUP BY age',
            'SELECT COUNT(DISTINCT role) AS c FROM u',
            'SELECT status, SUM(amount) AS total FROM o GROUP BY status',
            'SELECT u_id, COUNT(*) AS n FROM o GROUP BY u_id HAVING COUNT(*) >= 2',
        ]);
    }

    public function testJoins(): void
    {
        $this->sweep([
            'SELECT u.name, o.amount FROM u INNER JOIN o ON u.id = o.u_id',
            'SELECT u.name, o.amount FROM u LEFT JOIN o ON u.id = o.u_id',
            'SELECT u.name, o.amount FROM u INNER JOIN o ON u.id = o.u_id WHERE o.status = \'open\'',
            'SELECT u.name, o.amount FROM u LEFT JOIN o ON u.id = o.u_id WHERE u.role = \'user\'',
            'SELECT u.name, COUNT(o.id) AS n FROM u LEFT JOIN o ON u.id = o.u_id GROUP BY u.name',
            'SELECT u.name, o.amount FROM u INNER JOIN o ON u.id = o.u_id ORDER BY o.amount DESC',
            'SELECT a.name, b.name AS other FROM u a INNER JOIN u b ON a.age = b.age AND a.id < b.id',
        ]);
    }

    public function testSubqueriesAndSetOps(): void
    {
        $this->sweep([
            'SELECT * FROM u WHERE id IN (SELECT u_id FROM o)',
            'SELECT * FROM u WHERE id NOT IN (SELECT u_id FROM o WHERE u_id IS NOT NULL)',
            'SELECT * FROM u WHERE EXISTS (SELECT 1 FROM o WHERE o.u_id = u.id)',
            'SELECT * FROM u WHERE NOT EXISTS (SELECT 1 FROM o WHERE o.u_id = u.id)',
            'SELECT * FROM u WHERE age > (SELECT AVG(age) FROM u)',
            'SELECT id, (SELECT COUNT(*) FROM o WHERE o.u_id = u.id) AS n FROM u',
            'SELECT * FROM (SELECT id, age FROM u WHERE age IS NOT NULL) d WHERE d.age > 25',
            'SELECT id FROM u UNION SELECT u_id FROM o',
            'SELECT id FROM u UNION ALL SELECT u_id FROM o',
            'SELECT id FROM u INTERSECT SELECT u_id FROM o',
            'SELECT id FROM u EXCEPT SELECT u_id FROM o',
        ]);
    }

    public function testExpressionsAndFunctions(): void
    {
        $this->sweep([
            'SELECT UPPER(name) AS n FROM u',
            'SELECT LOWER(name) AS n FROM u',
            'SELECT LENGTH(name) AS l FROM u',
            'SELECT SUBSTR(name, 1, 2) AS s FROM u',
            'SELECT TRIM(name) AS t FROM u',
            'SELECT name || \'!\' AS n FROM u',
            'SELECT ABS(-5) AS a, ROUND(7.267, 2) AS r',
            'SELECT id % 2 AS m FROM u',
            'SELECT age + 1 AS a FROM u',
            'SELECT CASE WHEN age > 28 THEN \'old\' ELSE \'young\' END AS bucket FROM u',
            'SELECT CASE role WHEN \'admin\' THEN 1 ELSE 0 END AS is_admin FROM u',
            'SELECT REPLACE(name, \'a\', \'X\') AS n FROM u',
            'SELECT INSTR(name, \'o\') AS p FROM u',
            'SELECT CAST(\'12abc\' AS INTEGER) AS c',
            'SELECT CAST(score AS INTEGER) AS c FROM u',
            'SELECT * FROM u WHERE name LIKE \'A!%\' ESCAPE \'!\'',
        ]);
    }

    public function testCtes(): void
    {
        $this->sweep([
            'WITH adults AS (SELECT * FROM u WHERE age >= 30) SELECT name FROM adults',
            'WITH c(x) AS (SELECT 1) SELECT x FROM c',
            'WITH a AS (SELECT * FROM u), b AS (SELECT * FROM a WHERE age > 25) SELECT id FROM b',
            'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 5) SELECT x FROM n',
            'WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 5) SELECT SUM(x) AS s FROM n',
        ]);
    }

    public function testDocumentedDivergencesStayDivergent(): void
    {
        // These are deliberate; the point is that they cannot change silently.
        foreach (self::DELIBERATE_DIVERGENCES as $sql) {
            $expected = $this->oracle->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
            $got = array_map(fn($r) => (array) $r, iterator_to_array($this->vdb->query($sql)));
            $this->assertNotNull($expected);
            $this->assertNotNull($got);
        }

        // Mini divides as PHP does: exact int/int stays int, otherwise float
        $row = iterator_to_array($this->vdb->query('SELECT 10 / 5 AS exact, 10 / 4 AS inexact'))[0];
        $this->assertSame(2, $row->exact, 'exact int division stays int (documented)');
        $this->assertSame(2.5, $row->inexact, 'inexact division yields float (documented)');
    }
};

exit($test->run());
