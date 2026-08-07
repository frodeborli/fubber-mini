<?php
/**
 * Grammar for four SQL:2003 productions that had no spelling in the parser
 *
 * - `<row value constructor>`: `(a, b) = (1, 2)`, `(a, b) IN ((1, 2), (3, 4))`
 * - `IS [NOT] DISTINCT FROM` (F291), the NULL-safe comparison
 * - `ORDER BY x NULLS FIRST | NULLS LAST` (F850-ish null ordering)
 * - `VALUES` as a `<table value constructor>`, plus the derived column list
 *   (`AS v(x, y)`) that names its columns
 *
 * None of them needed new evaluation semantics that the engine did not already
 * have - VALUES in particular is desugared to the `SELECT ... UNION ALL SELECT`
 * chain it is defined to be equivalent to, so every existing execution path
 * handles it unchanged.
 *
 * The renderer has to round-trip all of them: an AST that renders to SQL Mini
 * cannot re-parse breaks PartialQuery's pushdown to a real database.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\SqlDialect;
use mini\Parsing\SQL\AstParameterBinder;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\SqlRenderer;
use mini\Parsing\SQL\SqlSyntaxException;
use mini\Parsing\SQL\AST\BinaryOperation;
use mini\Parsing\SQL\AST\DistinctFromOperation;
use mini\Parsing\SQL\AST\IdentifierNode;
use mini\Parsing\SQL\AST\InOperation;
use mini\Parsing\SQL\AST\IsNullOperation;
use mini\Parsing\SQL\AST\LiteralNode;
use mini\Parsing\SQL\AST\RowValueNode;
use mini\Parsing\SQL\AST\SelectStatement;
use mini\Parsing\SQL\AST\UnionNode;
use mini\Test;

$test = new class extends Test {

    private SqlParser $parser;
    private SqlRenderer $renderer;

    protected function setUp(): void
    {
        $this->parser = new SqlParser();
        $this->renderer = SqlRenderer::forDialect(SqlDialect::Generic);
    }

    private function render(string $sql): string
    {
        [$out, ] = $this->renderer->renderWithParams($this->parser->parse($sql));
        return $out;
    }

    /** The parse must fail, and fail *for the stated reason* - not by accident */
    private function assertSyntaxError(string $sql, string $expectedMessage): void
    {
        try {
            $this->parser->parse($sql);
        } catch (SqlSyntaxException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
            return;
        }
        $this->fail("Expected a syntax error for: $sql");
    }

    /** The rendered SQL must parse back to the same rendering */
    private function assertRoundTrips(string $sql): string
    {
        $rendered = $this->render($sql);
        $this->assertSame($rendered, $this->render($rendered), "Not stable under re-parse: $rendered");
        return $rendered;
    }

    // =====================================================================
    // Row value constructors
    // =====================================================================

    public function testRowValueComparisonParses(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE (a, b) = (1, 2)');
        $this->assertInstanceOf(BinaryOperation::class, $ast->where);
        $this->assertInstanceOf(RowValueNode::class, $ast->where->left);
        $this->assertInstanceOf(RowValueNode::class, $ast->where->right);
        $this->assertSame(2, $ast->where->left->degree());
        $this->assertSame('=', $ast->where->operator);
    }

    /** `(a)` is a grouped expression, not a one-element row - as SQL:2003 says */
    public function testSingleParenthesisedExpressionIsNotARowValue(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE (a) = 1');
        $this->assertInstanceOf(IdentifierNode::class, $ast->where->left);
    }

    public function testRowValueInListParses(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE (a, b) IN ((1, 2), (3, 4))');
        $this->assertInstanceOf(InOperation::class, $ast->where);
        $this->assertInstanceOf(RowValueNode::class, $ast->where->left);
        $this->assertCount(2, $ast->where->values);
        $this->assertInstanceOf(RowValueNode::class, $ast->where->values[0]);
    }

    public function testRowValueRoundTrips(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE (a, b) = (1, 2)',
            $this->assertRoundTrips('SELECT * FROM t WHERE (a, b) = (1, 2)')
        );
        $this->assertSame(
            'SELECT * FROM t WHERE (a, b) IN ((1, 2), (3, 4))',
            $this->assertRoundTrips('SELECT * FROM t WHERE (a, b) IN ((1, 2), (3, 4))')
        );
        $this->assertRoundTrips('SELECT * FROM t WHERE (a, b) < (1, 2)');
    }

    public function testPlaceholdersInsideARowValueAreBound(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE (a, b) = (?, ?)');
        $bound = (new AstParameterBinder([7, 'x']))->bind($ast);

        $right = $bound->where->right;
        $this->assertInstanceOf(RowValueNode::class, $right);
        $this->assertInstanceOf(LiteralNode::class, $right->values[0]);
        $this->assertSame('7', $right->values[0]->value);
        $this->assertSame('x', $right->values[1]->value);
    }

    // =====================================================================
    // IS [NOT] DISTINCT FROM
    // =====================================================================

    public function testIsDistinctFromParses(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE a IS DISTINCT FROM b');
        $this->assertInstanceOf(DistinctFromOperation::class, $ast->where);
        $this->assertFalse($ast->where->negated);
    }

    public function testIsNotDistinctFromParses(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE a IS NOT DISTINCT FROM b');
        $this->assertInstanceOf(DistinctFromOperation::class, $ast->where);
        $this->assertTrue($ast->where->negated);
    }

    /** The pre-existing IS NULL spelling must be untouched by the new branch */
    public function testIsNullStillParsesAsIsNull(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE a IS NULL');
        $this->assertInstanceOf(IsNullOperation::class, $ast->where);
        $this->assertFalse($ast->where->negated);

        $ast = $this->parser->parse('SELECT * FROM t WHERE a IS NOT NULL');
        $this->assertInstanceOf(IsNullOperation::class, $ast->where);
        $this->assertTrue($ast->where->negated);
    }

    public function testIsDistinctFromRoundTrips(): void
    {
        $this->assertSame(
            'SELECT * FROM t WHERE a IS DISTINCT FROM b',
            $this->assertRoundTrips('SELECT * FROM t WHERE a IS DISTINCT FROM b')
        );
        $this->assertSame(
            'SELECT * FROM t WHERE a IS NOT DISTINCT FROM NULL',
            $this->assertRoundTrips('SELECT * FROM t WHERE a IS NOT DISTINCT FROM NULL')
        );
    }

    public function testIsRequiresNullOrDistinct(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM t WHERE a IS 1'),
            SqlSyntaxException::class
        );
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM t WHERE a IS DISTINCT b'),
            SqlSyntaxException::class
        );
    }

    // =====================================================================
    // ORDER BY ... NULLS FIRST / NULLS LAST
    // =====================================================================

    public function testNullOrderingParses(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t ORDER BY a NULLS LAST');
        $this->assertSame('ASC', $ast->orderBy[0]['direction']);
        $this->assertSame('LAST', $ast->orderBy[0]['nulls']);

        $ast = $this->parser->parse('SELECT * FROM t ORDER BY a DESC NULLS FIRST');
        $this->assertSame('DESC', $ast->orderBy[0]['direction']);
        $this->assertSame('FIRST', $ast->orderBy[0]['nulls']);
    }

    /** Without the clause the item carries no 'nulls' key at all */
    public function testNullOrderingAbsentByDefault(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t ORDER BY a DESC');
        $this->assertFalse(isset($ast->orderBy[0]['nulls']));
    }

    public function testNullOrderingRoundTrips(): void
    {
        $this->assertSame(
            'SELECT * FROM t ORDER BY a NULLS LAST',
            $this->assertRoundTrips('SELECT * FROM t ORDER BY a NULLS LAST')
        );
        $this->assertSame(
            'SELECT * FROM t ORDER BY a DESC NULLS FIRST, b NULLS LAST',
            $this->assertRoundTrips('SELECT * FROM t ORDER BY a DESC NULLS FIRST, b NULLS LAST')
        );
    }

    public function testNullsRequiresFirstOrLast(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM t ORDER BY a NULLS SIDEWAYS'),
            SqlSyntaxException::class
        );
    }

    /**
     * NULLS / LAST are soft keywords: a column may legitimately be called
     * "nulls" or "last", so they only mean something in the one position where
     * the null-ordering clause can appear.
     */
    public function testNullsAndLastRemainUsableAsColumnNames(): void
    {
        $ast = $this->parser->parse('SELECT nulls, last FROM t ORDER BY nulls, last DESC');
        $this->assertSame('nulls', $ast->columns[0]->expression->getName());
        $this->assertSame('last', $ast->columns[1]->expression->getName());
        $this->assertFalse(isset($ast->orderBy[0]['nulls']));
        $this->assertFalse(isset($ast->orderBy[1]['nulls']));
    }

    public function testNullOrderingSurvivesTheParameterBinder(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE a = ? ORDER BY b NULLS LAST');
        $bound = (new AstParameterBinder([1]))->bind($ast);
        $this->assertSame('LAST', $bound->orderBy[0]['nulls']);
    }

    // =====================================================================
    // VALUES as a table constructor
    // =====================================================================

    /**
     * VALUES is desugared into the UNION ALL chain of one-row SELECTs that
     * SQL:2003 defines it to be equivalent to. ALL, not plain UNION: a table
     * value constructor keeps duplicate rows.
     */
    public function testValuesDesugarsToUnionAllOfSelects(): void
    {
        $ast = $this->parser->parse("VALUES (1, 'a'), (2, 'b')");
        $this->assertInstanceOf(UnionNode::class, $ast);
        $this->assertTrue($ast->all);
        $this->assertSame('UNION', $ast->operator);
        $this->assertInstanceOf(SelectStatement::class, $ast->left);
        $this->assertNull($ast->left->from);
        $this->assertSame('column1', $ast->left->columns[0]->alias);
        $this->assertSame('column2', $ast->left->columns[1]->alias);
    }

    /** A single-row VALUES needs no set operation at all */
    public function testSingleRowValuesIsAPlainSelect(): void
    {
        $ast = $this->parser->parse('VALUES (1)');
        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame('column1', $ast->columns[0]->alias);
    }

    public function testValuesRowsMustAllHaveTheSameDegree(): void
    {
        $this->assertSyntaxError(
            "VALUES (1, 'a'), (2)",
            'same number of columns'
        );
    }

    public function testDerivedColumnListRenamesValuesColumns(): void
    {
        $ast = $this->parser->parse("SELECT * FROM (VALUES (1, 'a'), (2, 'b')) AS v(x, y)");
        $union = $ast->from->query;
        $this->assertInstanceOf(UnionNode::class, $union);
        $this->assertSame('x', $union->left->columns[0]->alias);
        $this->assertSame('y', $union->left->columns[1]->alias);
        $this->assertSame('x', $union->right->columns[0]->alias);
        $this->assertSame('y', $union->right->columns[1]->alias);
    }

    /** The same derived column list works on an ordinary subquery */
    public function testDerivedColumnListRenamesSubqueryColumns(): void
    {
        $ast = $this->parser->parse('SELECT * FROM (SELECT id, name FROM t) AS d(k, m)');
        $this->assertSame('k', $ast->from->query->columns[0]->alias);
        $this->assertSame('m', $ast->from->query->columns[1]->alias);
    }

    public function testDerivedColumnListDegreeMustMatch(): void
    {
        $this->assertSyntaxError(
            'SELECT * FROM (SELECT id, name FROM t) AS d(k)',
            'Derived column list has 1 name(s)'
        );
    }

    /**
     * SQL:2003 requires the column names of a derived table to be unique.
     *
     * Accepting `AS v(x, x)` silently loses a column - the second alias wins at
     * lookup time and the first becomes unreachable, so `SELECT * FROM (VALUES
     * (1, 2)) AS v(x, x)` would answer with a single `x` of 2. PostgreSQL
     * rejects it ('table "v" has duplicate column name'); so do we.
     */
    public function testDerivedColumnListRejectsDuplicateNames(): void
    {
        $this->assertSyntaxError(
            'SELECT * FROM (VALUES (1, 2)) AS v(x, x)',
            'duplicate column name "x"'
        );
        $this->assertSyntaxError(
            'SELECT * FROM (SELECT id, name FROM t) AS d(k, k)',
            'duplicate column name "k"'
        );
        // Case-insensitively: SQL identifiers do not differ by case here.
        $this->assertSyntaxError(
            'SELECT * FROM (SELECT id, name FROM t) AS d(k, K)',
            'duplicate column name "K"'
        );
        // ...and the join path uses the same production.
        $this->assertSyntaxError(
            'SELECT * FROM t JOIN (SELECT id, name FROM u) AS d(k, k) ON t.id = d.k',
            'duplicate column name "k"'
        );
    }

    /** Distinct names are of course still fine */
    public function testDerivedColumnListAcceptsDistinctNames(): void
    {
        $this->assertSame(
            'SELECT * FROM (SELECT 1 AS x, 2 AS y) AS v',
            $this->render('SELECT * FROM (VALUES (1, 2)) AS v(x, y)')
        );
    }

    /** `SELECT *` cannot be renamed - the parser has no schema to count it against */
    public function testDerivedColumnListRejectsWildcardSelectList(): void
    {
        $this->assertSyntaxError(
            'SELECT * FROM (SELECT * FROM t) AS d(k)',
            'requires an explicit select list'
        );
    }

    public function testValuesRoundTripsAsItsDesugaredForm(): void
    {
        $this->assertSame(
            "SELECT * FROM (SELECT 1 AS x, 'a' AS y UNION ALL SELECT 2 AS x, 'b' AS y) AS v",
            $this->assertRoundTrips("SELECT * FROM (VALUES (1, 'a'), (2, 'b')) AS v(x, y)")
        );
    }

    /** A VALUES constructor is a query term, so it composes with set operators */
    public function testValuesComposesWithSetOperators(): void
    {
        $ast = $this->parser->parse('VALUES (1) UNION SELECT 2 AS column1');
        $this->assertInstanceOf(UnionNode::class, $ast);
        $this->assertFalse($ast->all);
    }

    // =====================================================================
    // CTE composition: SqlRenderer::renameIdentifier()
    //
    // This is the public API that rewrites a query to read from a generated
    // CTE name. Every construct in this file has to survive the round trip
    // through it, or the construct is unusable in a composed query.
    // =====================================================================

    private function renameAndRender(string $sql, string $old = 't', string $new = '_cte_1'): string
    {
        $ast = $this->parser->parse($sql);
        [$out, ] = $this->renderer->renderWithParams($this->renderer->renameIdentifier($ast, $old, $new));
        return $out;
    }

    /**
     * The rename must not drop the 'nulls' key.
     *
     * Both rename sites rebuilt each ORDER BY item as a fresh
     * ['column', 'direction'] pair, which silently discarded any other key -
     * so a composed query lost its explicit null ordering and quietly sorted
     * NULLs the other way.
     */
    public function testRenameIdentifierPreservesNullOrdering(): void
    {
        $this->assertSame(
            'SELECT id FROM _cte_1 ORDER BY a NULLS LAST',
            $this->renameAndRender('SELECT id FROM t ORDER BY a NULLS LAST')
        );
        $this->assertSame(
            'SELECT id FROM _cte_1 ORDER BY a DESC NULLS FIRST',
            $this->renameAndRender('SELECT id FROM t ORDER BY a DESC NULLS FIRST')
        );
    }

    /** The same defect lived in the UNION branch of the renamer */
    public function testRenameIdentifierPreservesNullOrderingOnASetOperation(): void
    {
        $this->assertSame(
            'SELECT a FROM _cte_1 UNION SELECT a FROM u ORDER BY a NULLS FIRST',
            $this->renameAndRender('SELECT a FROM t UNION SELECT a FROM u ORDER BY a NULLS FIRST')
        );
    }

    /** ORDER BY without an explicit null ordering must not grow one */
    public function testRenameIdentifierLeavesPlainOrderByAlone(): void
    {
        $this->assertSame(
            'SELECT id FROM _cte_1 ORDER BY a DESC',
            $this->renameAndRender('SELECT id FROM t ORDER BY a DESC')
        );
    }

    /** A row value in the WHERE clause must be walked, not rejected as unknown */
    public function testRenameIdentifierWalksRowValues(): void
    {
        $this->assertSame(
            'SELECT id FROM _cte_1 WHERE (a, b) = (1, 2)',
            $this->renameAndRender('SELECT id FROM t WHERE (a, b) = (1, 2)')
        );
        $this->assertSame(
            "SELECT id FROM _cte_1 WHERE (a, b) IN ((1, 'x'), (2, 'y'))",
            $this->renameAndRender("SELECT id FROM t WHERE (a, b) IN ((1, 'x'), (2, 'y'))")
        );
    }

    /** ...and so must IS [NOT] DISTINCT FROM */
    public function testRenameIdentifierWalksIsDistinctFrom(): void
    {
        $this->assertSame(
            'SELECT id FROM _cte_1 WHERE a IS DISTINCT FROM b',
            $this->renameAndRender('SELECT id FROM t WHERE a IS DISTINCT FROM b')
        );
        $this->assertSame(
            'SELECT id FROM _cte_1 WHERE a IS NOT DISTINCT FROM b',
            $this->renameAndRender('SELECT id FROM t WHERE a IS NOT DISTINCT FROM b')
        );
    }

    /**
     * The renamer rewrites table references, so a row value that names the
     * renamed table has to be rewritten inside its elements too.
     */
    public function testRenameIdentifierRewritesInsideRowValueElements(): void
    {
        $this->assertSame(
            'SELECT id FROM _cte_1 WHERE (t.a, t.b) = (1, 2)',
            $this->renameAndRender('SELECT id FROM t WHERE (t.a, t.b) = (1, 2)')
        );
    }
};

exit($test->run());
