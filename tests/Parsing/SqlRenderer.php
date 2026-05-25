<?php
/**
 * SqlRenderer tests.
 *
 * The renderer round-trips parsed AST back to SQL for a given dialect.
 * These tests cover edge cases that can produce *syntactically valid
 * looking* SQL that downstream engines reject — historically the
 * subtlest class of renderer bug.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\SqlDialect;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\SqlRenderer;
use mini\Test;

$test = new class extends Test {

    private SqlParser $parser;
    private SqlRenderer $renderer;
    private \PDO $sqlite;

    protected function setUp(): void
    {
        $this->parser   = new SqlParser();
        $this->renderer = SqlRenderer::forDialect(SqlDialect::Generic);

        // In-memory SQLite to verify rendered SQL is actually
        // executable, not just visually plausible. Anything that
        // SQLite's parser rejects is a renderer bug, regardless of
        // how the AST looks.
        $this->sqlite = new \PDO('sqlite::memory:');
        $this->sqlite->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->sqlite->exec('CREATE TABLE artists (id INTEGER PRIMARY KEY, name TEXT)');
        $this->sqlite->exec('CREATE TABLE works   (id INTEGER PRIMARY KEY, composer_id INTEGER)');
        $this->sqlite->exec('CREATE TABLE pieces  (id INTEGER PRIMARY KEY, composer_id INTEGER, performer_id INTEGER)');
    }

    private function render(string $sql): string
    {
        $ast = $this->parser->parse($sql);
        [$out, ] = $this->renderer->renderWithParams($ast);
        return $out;
    }

    private function assertSqliteAccepts(string $sql): void
    {
        // prepare() is enough — full parse without executing — so
        // tests don't need fixture rows.
        try {
            $this->sqlite->prepare($sql);
        } catch (\PDOException $e) {
            $this->fail("SQLite rejected rendered SQL:\n  $sql\n  reason: {$e->getMessage()}");
        }
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────
    // Same-operator UNION chain: no parens, valid SQL
    // ─────────────────────────────────────────────────────────────────

    public function testUnionChainRendersWithoutRedundantParens(): void
    {
        $rendered = $this->render(
            'SELECT id FROM artists UNION SELECT id FROM works UNION SELECT id FROM pieces'
        );
        $this->assertFalse(
            str_contains($rendered, '('),
            "Expected no parens in a same-operator UNION chain, got:\n  $rendered",
        );
        $this->assertSqliteAccepts($rendered);
    }

    public function testUnionAllChainRendersWithoutRedundantParens(): void
    {
        $rendered = $this->render(
            'SELECT id FROM artists UNION ALL SELECT id FROM works UNION ALL SELECT id FROM pieces'
        );
        $this->assertFalse(str_contains($rendered, '('), "Got: $rendered");
        $this->assertSqliteAccepts($rendered);
    }

    public function testIntersectChainRendersWithoutRedundantParens(): void
    {
        $rendered = $this->render(
            'SELECT id FROM artists INTERSECT SELECT id FROM works INTERSECT SELECT id FROM pieces'
        );
        $this->assertFalse(str_contains($rendered, '('), "Got: $rendered");
        $this->assertSqliteAccepts($rendered);
    }

    // ─────────────────────────────────────────────────────────────────
    // Mixed operator / ALL flag: still rendered flat, because SQLite
    // refuses parens around a compound-select operand. The AST is
    // left-associative and SQLite evaluates the flat form the same way
    // it'd evaluate the (forbidden) explicit grouping, so meaning is
    // preserved on round-trip.
    // ─────────────────────────────────────────────────────────────────

    public function testMixedUnionAndExceptRendersFlat(): void
    {
        $rendered = $this->render(
            'SELECT id FROM artists UNION SELECT id FROM works EXCEPT SELECT id FROM pieces'
        );
        $this->assertFalse(
            str_contains($rendered, '('),
            "Expected no parens (SQLite rejects parenthesised compound-select operands), got:\n  $rendered",
        );
        $this->assertSqliteAccepts($rendered);
    }

    public function testMixedUnionAndUnionAllRendersFlat(): void
    {
        $rendered = $this->render(
            'SELECT id FROM artists UNION ALL SELECT id FROM works UNION SELECT id FROM pieces'
        );
        $this->assertFalse(str_contains($rendered, '('), "Got: $rendered");
        $this->assertSqliteAccepts($rendered);
    }

    // ─────────────────────────────────────────────────────────────────
    // The regression: UNION chain inside an IN-subquery
    //
    // Previously rendered as `IN ((A UNION B) UNION C)` — SQLite parses
    // the inner `(A UNION B)` as a scalar subquery, then chokes on the
    // trailing UNION and emits "near 'UNION': syntax error".
    // ─────────────────────────────────────────────────────────────────

    public function testUnionChainEmbeddedInInSubqueryParses(): void
    {
        $rendered = $this->render(<<<'SQL'
            SELECT * FROM artists
            WHERE id IN (
                SELECT composer_id  FROM works  WHERE composer_id  IS NOT NULL
                UNION
                SELECT composer_id  FROM pieces WHERE composer_id  IS NOT NULL
                UNION
                SELECT performer_id FROM pieces WHERE performer_id IS NOT NULL
            )
        SQL);
        $this->assertSqliteAccepts($rendered);

        // Defensive: ensure no double-paren pattern survives. The
        // subquery itself is wrapped once by IN ( … ); the inner
        // UNION chain shouldn't add its own.
        $this->assertFalse(
            str_contains($rendered, 'IN ((SELECT'),
            "Renderer wrapped the IN-subquery's UNION chain in an extra paren:\n  $rendered",
        );
    }
};

exit($test->run());
