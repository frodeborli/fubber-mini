<?php
/**
 * Grammar for the scalar constructs whose arguments are separated by keywords
 *
 * CAST(x AS type), POSITION(x IN y), SUBSTRING(x FROM y FOR z),
 * TRIM([LEADING|TRAILING|BOTH] [chars] FROM x) and LIKE ... ESCAPE cannot be
 * written as ordinary function calls, so they are productions in the parser
 * rather than entries in the scalar function registry.
 *
 * All of them used to be syntax errors - POSITION worst of all, because `IN`
 * was read as the IN operator and `SELECT POSITION('li' IN name)` failed with
 * the thoroughly misleading "Table not found: name".
 *
 * The renderer has to round-trip them: an AST that renders to SQL Mini cannot
 * re-parse breaks PartialQuery's pushdown to a real database.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\SqlDialect;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\SqlRenderer;
use mini\Parsing\SQL\SqlSyntaxException;
use mini\Parsing\SQL\AST\CastNode;
use mini\Parsing\SQL\AST\FunctionCallNode;
use mini\Parsing\SQL\AST\LikeOperation;
use mini\Parsing\SQL\AST\SelectStatement;
use mini\Test;

$test = new class extends Test {

    private SqlParser $parser;
    private SqlRenderer $renderer;

    protected function setUp(): void
    {
        $this->parser = new SqlParser();
        $this->renderer = SqlRenderer::forDialect(SqlDialect::Generic);
    }

    private function firstColumn(string $sql): mixed
    {
        $ast = $this->parser->parse($sql);
        $this->assertInstanceOf(SelectStatement::class, $ast);
        return $ast->columns[0]->expression;
    }

    private function render(string $sql): string
    {
        [$out, ] = $this->renderer->renderWithParams($this->parser->parse($sql));
        return $out;
    }

    /** The rendered SQL must parse back to the same rendering */
    private function assertRoundTrips(string $sql): string
    {
        $rendered = $this->render($sql);
        $this->assertSame($rendered, $this->render($rendered), "Not stable under re-parse: $rendered");
        return $rendered;
    }

    // ── CAST ─────────────────────────────────────────────────────────────

    public function testCastParsesToACastNode(): void
    {
        $expr = $this->firstColumn('SELECT CAST(price AS INTEGER) FROM t');
        $this->assertInstanceOf(CastNode::class, $expr);
        $this->assertSame('INTEGER', $expr->castType);
        $this->assertSame('INTEGER', $expr->affinity());
        // A CastNode is a FunctionCallNode, so generic AST walks see its operand
        $this->assertInstanceOf(FunctionCallNode::class, $expr);
        $this->assertCount(1, $expr->arguments);
    }

    public function testCastTypeAffinities(): void
    {
        $affinity = fn(string $type) => $this->firstColumn("SELECT CAST(x AS $type) FROM t")->affinity();

        $this->assertSame('INTEGER', $affinity('INT'));
        $this->assertSame('INTEGER', $affinity('BIGINT'));
        $this->assertSame('TEXT', $affinity('TEXT'));
        $this->assertSame('TEXT', $affinity('VARCHAR(255)'));
        $this->assertSame('REAL', $affinity('REAL'));
        $this->assertSame('REAL', $affinity('DOUBLE PRECISION'));
        $this->assertSame('NUMERIC', $affinity('DECIMAL(10,2)'));
        $this->assertSame('NUMERIC', $affinity('BOOLEAN'));
        $this->assertSame('BLOB', $affinity('BLOB'));
    }

    public function testCastRendersBackAsCast(): void
    {
        $this->assertSame(
            'SELECT CAST(price AS INTEGER) FROM t',
            $this->assertRoundTrips('SELECT CAST(price AS INTEGER) FROM t')
        );
        $this->assertSame(
            'SELECT CAST(price AS DECIMAL(10,2)) FROM t',
            $this->assertRoundTrips('SELECT CAST(price AS DECIMAL(10, 2)) FROM t')
        );
    }

    public function testCastRequiresAType(): void
    {
        $this->assertThrows(fn() => $this->parser->parse('SELECT CAST(x) FROM t'), SqlSyntaxException::class);
        $this->assertThrows(fn() => $this->parser->parse('SELECT CAST(x AS) FROM t'), SqlSyntaxException::class);
    }

    // ── POSITION ─────────────────────────────────────────────────────────

    public function testPositionInSyntaxParses(): void
    {
        $expr = $this->firstColumn("SELECT POSITION('li' IN name) FROM t");
        $this->assertInstanceOf(FunctionCallNode::class, $expr);
        $this->assertSame('POSITION', $expr->name);
        $this->assertCount(2, $expr->arguments);
    }

    public function testPositionRendersWithInSyntax(): void
    {
        $this->assertSame(
            'SELECT POSITION(\'li\' IN name) FROM t',
            $this->assertRoundTrips("SELECT POSITION('li' IN name) FROM t")
        );
    }

    public function testPositionWithoutInFailsWithAnHonestError(): void
    {
        try {
            $this->parser->parse('SELECT POSITION(name) FROM t');
            $this->fail('Expected a syntax error');
        } catch (SqlSyntaxException $e) {
            $this->assertStringContainsString('POSITION(substring IN string)', $e->getMessage());
        }
    }

    // ── SUBSTRING / TRIM ─────────────────────────────────────────────────

    public function testSubstringFromForParses(): void
    {
        $expr = $this->firstColumn('SELECT SUBSTRING(name FROM 2 FOR 3) FROM t');
        $this->assertSame('SUBSTRING', strtoupper($expr->name));
        $this->assertCount(3, $expr->arguments);

        $expr = $this->firstColumn('SELECT SUBSTRING(name FROM 2) FROM t');
        $this->assertCount(2, $expr->arguments);

        // The comma spelling is unchanged
        $expr = $this->firstColumn('SELECT SUBSTR(name, 2, 3) FROM t');
        $this->assertCount(3, $expr->arguments);
    }

    public function testTrimSpecificationLowersToTheRightFunction(): void
    {
        $name = fn(string $sql) => strtoupper($this->firstColumn("SELECT $sql FROM t")->name);

        $this->assertSame('TRIM', $name("TRIM(name)"));
        $this->assertSame('TRIM', $name("TRIM(BOTH ' ' FROM name)"));
        $this->assertSame('LTRIM', $name("TRIM(LEADING ' ' FROM name)"));
        $this->assertSame('RTRIM', $name("TRIM(TRAILING ' ' FROM name)"));

        // TRIM(chars FROM x) puts the string first, matching TRIM(x, chars)
        $expr = $this->firstColumn("SELECT TRIM(LEADING 'a' FROM name) FROM t");
        $this->assertSame('name', $expr->arguments[0]->getName());
        $this->assertSame('a', $expr->arguments[1]->value);
    }

    public function testTrimSpecificationRequiresFrom(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse("SELECT TRIM(LEADING 'a') FROM t"),
            SqlSyntaxException::class
        );
    }

    public function testSubstringAndTrimRoundTripToTheCommaSpelling(): void
    {
        // The keyword spellings normalise onto the comma form, which every
        // dialect Mini renders for understands
        $this->assertSame(
            'SELECT SUBSTRING(name, 2, 3) FROM t',
            $this->assertRoundTrips('SELECT SUBSTRING(name FROM 2 FOR 3) FROM t')
        );
        $this->assertSame(
            'SELECT LTRIM(name, \' \') FROM t',
            $this->assertRoundTrips("SELECT TRIM(LEADING ' ' FROM name) FROM t")
        );
    }

    // ── LIKE ... ESCAPE ──────────────────────────────────────────────────

    public function testLikeEscapeParses(): void
    {
        $ast = $this->parser->parse("SELECT * FROM t WHERE name LIKE '100#%' ESCAPE '#'");
        $this->assertInstanceOf(LikeOperation::class, $ast->where);
        $this->assertNotNull($ast->where->escape);
        $this->assertSame('#', $ast->where->escape->value);
        $this->assertFalse($ast->where->negated);

        $ast = $this->parser->parse("SELECT * FROM t WHERE name NOT LIKE '100#%' ESCAPE '#'");
        $this->assertTrue($ast->where->negated);
        $this->assertNotNull($ast->where->escape);
    }

    public function testLikeWithoutEscapeHasNoEscapeNode(): void
    {
        $ast = $this->parser->parse("SELECT * FROM t WHERE name LIKE '100%'");
        $this->assertNull($ast->where->escape);
    }

    public function testLikeEscapeRoundTrips(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE name LIKE \'100#%\' ESCAPE \'#\'',
            $this->assertRoundTrips("SELECT * FROM t WHERE name LIKE '100#%' ESCAPE '#'")
        );
        $this->assertSame(
            'SELECT * FROM t WHERE name NOT LIKE \'100#%\' ESCAPE \'#\'',
            $this->assertRoundTrips("SELECT * FROM t WHERE name NOT LIKE '100#%' ESCAPE '#'")
        );
    }

    public function testEscapeCharacterIsBoundByTheParameterBinder(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE name LIKE ? ESCAPE ?');
        $bound = (new \mini\Parsing\SQL\AstParameterBinder(['100#%', '#']))->bind($ast);

        // The binder replaces placeholders with literals - including the one
        // in the ESCAPE clause, which it used to leave unbound
        $this->assertSame('100#%', $bound->where->pattern->value);
        $this->assertNotNull($bound->where->escape);
        $this->assertInstanceOf(\mini\Parsing\SQL\AST\LiteralNode::class, $bound->where->escape);
        $this->assertSame('#', $bound->where->escape->value);
    }

    public function testEscapeIsOnlyAKeywordAfterALikePattern(): void
    {
        // ESCAPE is a soft keyword: a column may still be called "escape"
        $ast = $this->parser->parse('SELECT escape FROM t WHERE escape = 1');
        $this->assertSame('escape', $ast->columns[0]->expression->getName());
    }
};

exit($test->run());
