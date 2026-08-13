<?php
/**
 * Regression tests: OR predicate pushdown to SQL backends
 *
 * When a VDB table is backed by a PartialQuery, WHERE predicates push down
 * as native SQL executed by the backing database. Two defects fixed here:
 *
 * 1. `a OR b OR c` parses as `(a OR b) OR c`, and the planner handed the
 *    nested-OR left operand to buildPredicateFromAst(), which cannot express
 *    nested OR as a Predicate - so any OR with three or more branches fell
 *    back to a full scan filtered in PHP, even though TableInterface::or()
 *    is variadic and could push it. The chain is now flattened into its
 *    branches before delegating.
 *
 * 2. Predicate values converted to AST (the or() path) were inlined as
 *    quoted literals, unlike eq()/lt()/like() which bind parameters. All
 *    non-NULL values now become bound placeholders, so the backend receives
 *    `?` with driver-bound typed parameters - injection-safe by construction
 *    and one cached plan per SQL shape. NULL stays a literal to keep its
 *    `= NULL` matches-nothing semantics unchanged.
 *
 * The tests observe the actual SQL handed to the executor - what the backend
 * receives, not what any debug rendering shows.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Database\PartialQuery;
use mini\Parsing\SQL\SqlRenderer;

$test = new class extends Test {

    /** @var list<string> SQL strings the backend actually received */
    private array $backendSql = [];

    protected function setUp(): void
    {
        \mini\bootstrap();
        \mini\db()->exec('DROP TABLE IF EXISTS orpush');
        \mini\db()->exec('CREATE TABLE orpush (id INTEGER PRIMARY KEY, name TEXT, salary INTEGER)');
        \mini\db()->exec("INSERT INTO orpush VALUES (1,'Alice',50),(2,'Bob',30),(3,'Carol',70)");
    }

    /**
     * Run a VDB query against a PartialQuery-backed table whose executor
     * records every SQL string before executing it for real.
     */
    private function runLogged(string $vdbSql): array
    {
        $this->backendSql = [];
        $dialect = \mini\db()->getDialect();
        $renderer = SqlRenderer::forDialect($dialect);

        $executor = function (PartialQuery $query, $ast) use ($dialect, $renderer): \Traversable {
            [$sql, $params] = $ast !== null ? $renderer->renderWithParams($ast) : $query->getSql($dialect);
            $this->backendSql[] = $sql;
            foreach (\mini\db()->query($sql, $params) as $row) {
                yield $row;
            }
        };

        $vdb = new VirtualDatabase();
        $vdb->registerTable('emp', PartialQuery::fromSql(\mini\db(), $executor, 'SELECT * FROM orpush'));

        return array_map(fn($r) => $r->name, iterator_to_array($vdb->query($vdbSql)));
    }

    private function backendSawWhere(): bool
    {
        foreach ($this->backendSql as $sql) {
            if (str_contains($sql, 'WHERE')) {
                return true;
            }
        }
        return false;
    }

    public function testTwoBranchOrPushesToBackend(): void
    {
        $names = $this->runLogged("SELECT name FROM emp WHERE salary > 40 OR name = 'Bob'");
        sort($names);
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names);
        $this->assertTrue($this->backendSawWhere(), 'two-branch OR must push as a WHERE clause');
    }

    public function testThreeBranchOrPushesToBackend(): void
    {
        $names = $this->runLogged("SELECT name FROM emp WHERE salary > 60 OR name = 'Bob' OR id = 1");
        sort($names);
        $this->assertSame(['Alice', 'Bob', 'Carol'], $names);
        $this->assertTrue(
            $this->backendSawWhere(),
            'three-branch OR must be flattened and pushed, not fall back to a full scan'
        );
    }

    public function testConjunctionInsideBranchPushesToBackend(): void
    {
        $names = $this->runLogged("SELECT name FROM emp WHERE (salary > 40 AND id < 3) OR name = 'Bob'");
        sort($names);
        $this->assertSame(['Alice', 'Bob'], $names);
        $this->assertTrue($this->backendSawWhere());
    }

    public function testUnsupportedShapeFallsBackSafely(): void
    {
        // NOT LIKE inside OR is a documented pushdown refusal: the backend
        // gets a full scan and PHP filters. The answer must still be correct -
        // the fallback is fail-safe, never fail-wrong. (This test previously
        // used !=, which now pushes via the range-split rewrite.)
        $names = $this->runLogged("SELECT name FROM emp WHERE name NOT LIKE 'A%' OR salary = 999");
        sort($names);
        $this->assertSame(['Bob', 'Carol'], $names);
        $this->assertFalse($this->backendSawWhere(), 'refused shape scans and filters in PHP');
    }

    public function testPushedOrValuesAreBoundParameters(): void
    {
        $this->runLogged("SELECT name FROM emp WHERE salary > 40 OR name = 'Bob' OR id = 1");

        $whereSql = null;
        foreach ($this->backendSql as $sql) {
            if (str_contains($sql, 'WHERE')) {
                $whereSql = $sql;
            }
        }
        $this->assertNotNull($whereSql);

        // Values travel as driver-bound placeholders, never inlined literals
        $this->assertSame(3, substr_count($whereSql, '?'), "expected 3 placeholders in: $whereSql");
        $this->assertStringNotContainsString("'Bob'", $whereSql);
        $this->assertStringNotContainsString('40', $whereSql);
    }

    public function testDirectOrCallBindsTypedParameters(): void
    {
        // Same property asserted at the PartialQuery layer, with types visible
        $ref = new \ReflectionProperty(\mini\Database\Query::class, 'pq');
        $partial = $ref->getValue(\mini\db()->query('SELECT * FROM orpush'));

        $a = (new \mini\Table\Predicate())->gt('salary', 40);
        $b = (new \mini\Table\Predicate())->eq('name', 'Bob');
        $c = (new \mini\Table\Predicate())->eq('id', 1);

        [$sql, $params] = $partial->or($a, $b, $c)->getSql(\mini\db()->getDialect());

        $this->assertSame(3, substr_count($sql, '?'), "expected 3 placeholders in: $sql");
        $this->assertSame([40, 'Bob', 1], $params);
        $this->assertSame('integer', gettype($params[0]), 'numeric values keep their PHP type');
    }

    public function testNotEqualsPushesAsRangeSplit(): void
    {
        // x != k has no direct pushdown verb, but under a total ordering it is
        // exactly (x < k OR x > k) - both verbs the backend push supports. NULL
        // semantics survive: a NULL operand makes both branches UNKNOWN, so the
        // OR is UNKNOWN and the row is excluded, as != requires.
        \mini\db()->exec("INSERT INTO orpush (id, name, salary) VALUES (4, NULL, 10)");

        $names = $this->runLogged("SELECT name FROM emp WHERE name != 'Bob'");
        sort($names);
        $this->assertSame(['Alice', 'Carol'], $names, 'NULL name row must be excluded, like SQL != does');

        $this->assertTrue($this->backendSawWhere(), 'name != literal must push as a range split');
        $whereSql = implode(' ', $this->backendSql);
        $this->assertStringContainsString('<', $whereSql);
        $this->assertStringContainsString('>', $whereSql);
    }

    public function testNotEqualsInsideOrPushes(): void
    {
        // After range-splitting, a != inside an OR flattens into plain branches
        $names = $this->runLogged("SELECT name FROM emp WHERE name != 'Bob' OR salary > 60");
        sort($names);
        $this->assertSame(['Alice', 'Carol'], $names);
        $this->assertTrue($this->backendSawWhere(), '!= inside OR must push, not scan');
    }

    public function testLikePrefixPushesToBackend(): void
    {
        // The sargable spelling an LLM should use for prefix matching - and the
        // spelling backends can serve from an index - already pushes.
        $names = $this->runLogged("SELECT name FROM emp WHERE name LIKE 'A%'");
        $this->assertSame(['Alice'], $names);
        $this->assertTrue($this->backendSawWhere(), 'LIKE prefix must push');
        $this->assertStringContainsString('LIKE', implode(' ', $this->backendSql));
    }
};

exit($test->run());
