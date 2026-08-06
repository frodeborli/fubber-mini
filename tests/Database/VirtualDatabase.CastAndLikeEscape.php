<?php
/**
 * CAST(expr AS type) and LIKE ... ESCAPE
 *
 * Both were parse errors: `CAST(x AS INTEGER)` failed with "Expected token
 * RPAREN but found AS" (and the evaluator's CAST arm was a stub that returned
 * the value unchanged), and `LIKE '...' ESCAPE '#'` failed in the parser.
 *
 * SQLite is VirtualDatabase's reference implementation, so every expectation
 * below is cross-checked against an in-memory SQLite at test time - the messy
 * conversions in particular (CAST('12abc' AS INTEGER) is 12, CAST('abc' AS
 * INTEGER) is 0, CAST(1.7 AS INTEGER) truncates rather than rounds).
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\VirtualDatabase;
use mini\Table\InMemoryTable;
use mini\Table\ColumnDef;
use mini\Table\Types\ColumnType;
use mini\Table\Types\IndexType;

$test = new class extends Test {

    private ?\PDO $sqlite = null;

    protected function setUp(): void
    {
        if (in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->sqlite = new \PDO('sqlite::memory:');
            $this->sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
    }

    private function vdb(): VirtualDatabase
    {
        $table = new InMemoryTable(
            new ColumnDef('id', ColumnType::Int, IndexType::Primary),
            new ColumnDef('name', ColumnType::Text),
        );
        $table->insert(['id' => 1, 'name' => 'alice']);
        $table->insert(['id' => 2, 'name' => '100% pure']);
        $table->insert(['id' => 3, 'name' => 'under_score']);

        $vdb = new VirtualDatabase();
        $vdb->registerTable('users', $table);
        return $vdb;
    }

    private function eval(string $expression): mixed
    {
        $rows = iterator_to_array($this->vdb()->query("SELECT $expression AS v FROM users WHERE id = 1"));
        return $rows[0]->v;
    }

    /**
     * Assert a scalar expression evaluates to $expected, and that SQLite
     * agrees - so the expectation cannot silently drift from the reference.
     */
    private function assertMatchesSqlite(mixed $expected, string $expression): void
    {
        $actual = $this->eval($expression);
        $this->assertSame($expected, $actual, "VDB: $expression");

        if ($this->sqlite === null) {
            $this->log('pdo_sqlite not available, skipping cross-check');
            return;
        }

        $reference = $this->sqlite->query("SELECT $expression AS v")->fetch(\PDO::FETCH_ASSOC)['v'];
        if ($this->scalarKey($reference) !== $this->scalarKey($actual)) {
            $this->fail(sprintf(
                "SQLite disagrees on %s: sqlite=%s vdb=%s",
                $expression,
                var_export($reference, true),
                var_export($actual, true)
            ));
        }
    }

    /** Comparable rendering of a scalar - PDO and VDB may differ in int/float-ness */
    private function scalarKey(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return (string)(int)$value;
        }
        if (is_float($value) && $value == (int)$value) {
            return (string)(int)$value;
        }
        return (string)$value;
    }

    // ── CAST ─────────────────────────────────────────────────────────────

    public function testCastToInteger(): void
    {
        $this->assertMatchesSqlite(12, "CAST('12abc' AS INTEGER)");
        $this->assertMatchesSqlite(0, "CAST('abc' AS INTEGER)");
        $this->assertMatchesSqlite(null, 'CAST(NULL AS INTEGER)');
        $this->assertMatchesSqlite(1, 'CAST(1.7 AS INTEGER)');   // truncation, not rounding
        $this->assertMatchesSqlite(-1, 'CAST(-1.7 AS INTEGER)');
        $this->assertMatchesSqlite(1, "CAST('1.9' AS INTEGER)");
        $this->assertMatchesSqlite(42, "CAST('  42  ' AS INTEGER)");
        $this->assertMatchesSqlite(0, "CAST('' AS INTEGER)");
        // The prefix must read as an *integer*: '3e2' is 3, not 300
        $this->assertMatchesSqlite(3, "CAST('3e2' AS INTEGER)");
        $this->assertMatchesSqlite(-12, "CAST('-12abc' AS INTEGER)");
        $this->assertMatchesSqlite(0, "CAST('0x10' AS INTEGER)");
        $this->assertMatchesSqlite(7, "CAST('7' AS INT)");
    }

    public function testCastToReal(): void
    {
        $this->assertMatchesSqlite(2.5, "CAST('2.5abc' AS REAL)");
        $this->assertMatchesSqlite(0.0, "CAST('abc' AS REAL)");
        $this->assertMatchesSqlite(null, 'CAST(NULL AS REAL)');
        $this->assertMatchesSqlite(12.0, 'CAST(12 AS REAL)');
        $this->assertMatchesSqlite(1000.0, "CAST('1e3' AS REAL)");
        $this->assertMatchesSqlite(0.5, "CAST('.5' AS REAL)");
        $this->assertMatchesSqlite(1.5, "CAST('1.5' AS FLOAT)");
        $this->assertMatchesSqlite(1.5, "CAST('1.5' AS DOUBLE)");
    }

    public function testCastToText(): void
    {
        $this->assertMatchesSqlite('12', 'CAST(12 AS TEXT)');
        $this->assertMatchesSqlite('1.5', 'CAST(1.5 AS TEXT)');
        // A REAL keeps looking real
        $this->assertMatchesSqlite('1.0', 'CAST(1.0 AS TEXT)');
        $this->assertMatchesSqlite(null, 'CAST(NULL AS TEXT)');
        $this->assertMatchesSqlite('12', "CAST('12' AS VARCHAR(3))");
        $this->assertMatchesSqlite('12', 'CAST(12 AS CHAR)');
    }

    public function testCastToNumericAndBoolean(): void
    {
        $this->assertMatchesSqlite(12, "CAST('12abc' AS NUMERIC)");
        $this->assertMatchesSqlite(1.5, "CAST('1.5' AS NUMERIC)");
        $this->assertMatchesSqlite(12.7, "CAST('12.7' AS DECIMAL(10,2))");
        $this->assertMatchesSqlite(null, 'CAST(NULL AS NUMERIC)');
        $this->assertMatchesSqlite(1, 'CAST(1 AS BOOLEAN)');
        $this->assertMatchesSqlite(0, "CAST('x' AS BOOLEAN)");
    }

    public function testCastOfColumnAndExpression(): void
    {
        $rows = iterator_to_array($this->vdb()->query(
            "SELECT CAST(id AS TEXT) AS t FROM users WHERE id = 1"
        ));
        $this->assertSame('1', $rows[0]->t);

        // A cast is an ordinary expression: it composes and aggregates see through it
        $rows = iterator_to_array($this->vdb()->query(
            "SELECT SUM(CAST('2abc' AS INTEGER)) AS s FROM users"
        ));
        $this->assertSame(6, $rows[0]->s);
    }

    public function testCastInWhereClause(): void
    {
        $rows = iterator_to_array($this->vdb()->query(
            "SELECT name FROM users WHERE CAST(id AS TEXT) = '2'"
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('100% pure', $rows[0]->name);
    }

    public function testCastWithoutTypeIsASyntaxError(): void
    {
        $this->assertThrows(
            fn() => iterator_to_array($this->vdb()->query('SELECT CAST(id) FROM users')),
            \mini\Parsing\SQL\SqlSyntaxException::class
        );
    }

    // ── LIKE ... ESCAPE ──────────────────────────────────────────────────

    private function names(string $where): array
    {
        $rows = iterator_to_array($this->vdb()->query("SELECT name FROM users WHERE $where ORDER BY id"));
        return array_map(fn($r) => $r->name, $rows);
    }

    public function testEscapeMakesWildcardLiteral(): void
    {
        // '#%' is a literal percent sign, so only the row containing "100%" matches
        $this->assertSame(['100% pure'], $this->names("name LIKE '100#%%' ESCAPE '#'"));
        // ... and without the escape, % is still a wildcard
        $this->assertSame(['100% pure'], $this->names("name LIKE '100%' ESCAPE '#'"));
    }

    public function testEscapeMakesUnderscoreLiteral(): void
    {
        $this->assertSame(['under_score'], $this->names("name LIKE 'under#_score' ESCAPE '#'"));
        // A literal underscore does not match an arbitrary character
        $this->assertSame([], $this->names("name LIKE 'under#_scorX' ESCAPE '#'"));
        // Unescaped, _ matches any single character
        $this->assertSame(['under_score'], $this->names("name LIKE 'under_score' ESCAPE '#'"));
    }

    public function testNotLikeWithEscape(): void
    {
        $this->assertSame(['alice', 'under_score'], $this->names("name NOT LIKE '100#%%' ESCAPE '#'"));
    }

    public function testEscapeSemanticsMatchSqlite(): void
    {
        if ($this->sqlite === null) {
            $this->log('pdo_sqlite not available, skipping cross-check');
            $this->assertTrue(true);
            return;
        }

        $cases = [
            ["'a%b'", "'a#%b'", "'#'"],
            ["'axb'", "'a#%b'", "'#'"],
            ["'a_b'", "'a#_b'", "'#'"],
            ["'axb'", "'a#_b'", "'#'"],
            ["'a#b'", "'a#b'", "'#'"],      // escape before a plain char: the char itself
            ["'ab'", "'a#b'", "'#'"],
            ["'a#'", "'a#'", "'#'"],        // dangling escape matches nothing
            ["'100%'", "'100#%'", "'#'"],
            ["'A%B'", "'a#%b'", "'#'"],     // LIKE is case-insensitive
        ];

        foreach ($cases as [$value, $pattern, $escape]) {
            $expr = "$value LIKE $pattern ESCAPE $escape";
            $reference = (int)$this->sqlite->query("SELECT $expr AS v")->fetch(\PDO::FETCH_ASSOC)['v'];
            $actual = (int)(bool)$this->eval($expr);
            $this->assertSame($reference, $actual, "LIKE mismatch for: $expr");
        }
    }

    public function testMultiCharacterEscapeIsRejected(): void
    {
        try {
            $this->names("name LIKE '100#%%' ESCAPE '##'");
            $this->fail('Expected a single-character ESCAPE error');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ESCAPE expression must be a single character', $e->getMessage());
        }
    }

    public function testEscapeCanBeAParameter(): void
    {
        $rows = iterator_to_array($this->vdb()->query(
            "SELECT name FROM users WHERE name LIKE ? ESCAPE ?",
            ['100#%%', '#']
        ));
        $this->assertCount(1, $rows);
        $this->assertSame('100% pure', $rows[0]->name);
    }

    public function testEscapeSurvivesTheOptimizer(): void
    {
        // NOT (x LIKE y ESCAPE z) is rewritten to a negated LIKE; the escape
        // character must survive that rewrite
        $this->assertSame(['alice', 'under_score'], $this->names("NOT (name LIKE '100#%%' ESCAPE '#')"));
    }
};

exit($test->run());
