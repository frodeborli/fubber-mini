<?php
/**
 * Grammar for typed datetime literals and EXTRACT
 *
 * `DATE '2020-01-01'`, `TIME '13:45:00'` and `TIMESTAMP '2020-01-01 13:45:00'`
 * (SQL:2003 F051-01/02/03) and `EXTRACT(field FROM source)` (F052) are Core
 * spellings that used to be syntax errors: DATE was read as a column named
 * "date" followed by a stray string, and EXTRACT's FROM ended the argument
 * list.
 *
 * Mini stores datetimes as text, so a typed literal is an *assertion about the
 * format* rather than a conversion - which is why the parser is the right
 * place to reject `DATE 'banana'` and `DATE '2020-02-30'`.
 *
 * The renderer has to keep producing SQL that re-parses: EXTRACT keeps its
 * standard syntax, while a typed literal renders as the plain quoted string it
 * denotes, because the engine's reference backend (SQLite) has no typed
 * literal syntax at all.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\SqlDialect;
use mini\Parsing\SQL\DatetimeText;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\SqlRenderer;
use mini\Parsing\SQL\SqlSyntaxException;
use mini\Parsing\SQL\AST\ExtractNode;
use mini\Parsing\SQL\AST\FunctionCallNode;
use mini\Parsing\SQL\AST\IdentifierNode;
use mini\Parsing\SQL\AST\LiteralNode;
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

    public function testTypedDatetimeLiteralsParse(): void
    {
        foreach ([
            "SELECT DATE '2020-01-01'" => ['2020-01-01', 'date'],
            "SELECT TIME '13:45:00'" => ['13:45:00', 'time'],
            "SELECT TIMESTAMP '2020-01-01 13:45:00'" => ['2020-01-01 13:45:00', 'timestamp'],
            "SELECT TIMESTAMP '2020-01-01 13:45:00.250'" => ['2020-01-01 13:45:00.250', 'timestamp'],
        ] as $sql => [$value, $valueType]) {
            $node = $this->firstColumn($sql);
            $this->assertInstanceOf(LiteralNode::class, $node, $sql);
            $this->assertSame($value, $node->value, $sql);
            $this->assertSame($valueType, $node->valueType, $sql);
        }
    }

    public function testTypedLiteralKeywordIsCaseInsensitive(): void
    {
        $node = $this->firstColumn("SELECT date '2020-01-01'");
        $this->assertInstanceOf(LiteralNode::class, $node);
        $this->assertSame('2020-01-01', $node->value);
    }

    public function testMalformedDatetimeLiteralNamesTheExpectedFormat(): void
    {
        foreach ([
            "SELECT DATE 'banana'" => "must be 'YYYY-MM-DD'",
            "SELECT DATE '2020-1-1'" => "must be 'YYYY-MM-DD'",
            "SELECT TIME '1pm'" => "must be 'HH:MM:SS[.fff]'",
            "SELECT TIMESTAMP '2020-01-01T13:45:00'" => "must be 'YYYY-MM-DD HH:MM:SS[.fff]'",
        ] as $sql => $expected) {
            try {
                $this->parser->parse($sql);
                $this->assertTrue(false, "expected a syntax error for: $sql");
            } catch (SqlSyntaxException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), $sql);
            }
        }
    }

    public function testImpossibleDatetimeValuesAreRejected(): void
    {
        try {
            $this->parser->parse("SELECT DATE '2020-02-30'");
            $this->assertTrue(false, 'expected a syntax error');
        } catch (SqlSyntaxException $e) {
            $this->assertStringContainsString('not a date on the calendar', $e->getMessage());
        }

        try {
            $this->parser->parse("SELECT TIME '25:00:00'");
            $this->assertTrue(false, 'expected a syntax error');
        } catch (SqlSyntaxException $e) {
            $this->assertStringContainsString('time field out of range', $e->getMessage());
        }

        // 2020 was a leap year, so this one is legal
        $node = $this->firstColumn("SELECT DATE '2020-02-29'");
        $this->assertSame('2020-02-29', $node->value);
    }

    public function testTheValidatorIsSharedWithTheEvaluator(): void
    {
        // The parser does not own these rules: DatetimeText does, so that
        // EXTRACT applies the identical ones to text coming out of a column.
        // A parser that rejects DATE '2020-13-45' while the evaluator reports
        // month 13 for the same characters would be worse than either
        // behaviour alone, and a column is where malformed text actually
        // arrives from CSV/JSON/API tables.
        foreach (['2020-13-45', '2019-02-30', '0000-01-01', '25:99:99', '2020-01-01 00:00:99'] as $text) {
            $this->assertThrows(
                fn() => DatetimeText::parse($text),
                \InvalidArgumentException::class,
                $text
            );
        }

        $parts = DatetimeText::parse('2020-05-06 13:45:07');
        $this->assertSame('TIMESTAMP', $parts['kind']);
        $this->assertSame(2020, $parts['YEAR']);
        $this->assertSame(7, $parts['SECOND']);

        // A DATE has a zero time, matching strftime('%H','2020-05-06') = '00';
        // a TIME has no date fields at all, so EXTRACT(YEAR ...) can say so.
        $this->assertSame(0, DatetimeText::parse('2020-05-06')['HOUR']);
        $this->assertNull(DatetimeText::parse('13:45:07')['YEAR']);

        // Equal instants are one value, whatever the trailing zeros say.
        $this->assertSame(7, DatetimeText::parse('00:00:07.00')['SECOND']);
        $this->assertSame(7.5, DatetimeText::parse('00:00:07.5')['SECOND']);
    }

    public function testDateRemainsAnOrdinaryIdentifier(): void
    {
        // Only a string literal immediately after DATE makes a typed literal;
        // as a column name, an alias or a type name DATE is just a name.
        $node = $this->firstColumn('SELECT date FROM events');
        $this->assertInstanceOf(IdentifierNode::class, $node);

        $ast = $this->parser->parse('SELECT id AS date FROM events');
        $this->assertSame('date', $ast->columns[0]->alias);

        $node = $this->firstColumn("SELECT CAST(x AS DATE) FROM events");
        $this->assertSame('DATE', $node->castType);
    }

    public function testExtractParses(): void
    {
        foreach (ExtractNode::FIELDS as $field) {
            $node = $this->firstColumn("SELECT EXTRACT($field FROM created_at) FROM events");
            $this->assertInstanceOf(ExtractNode::class, $node);
            $this->assertSame($field, $node->field);
            $this->assertInstanceOf(IdentifierNode::class, $node->arguments[0]);
        }

        // The field name is a keyword here, so its case must not matter
        $node = $this->firstColumn("SELECT EXTRACT(year FROM created_at) FROM events");
        $this->assertSame('YEAR', $node->field);
    }

    public function testExtractIsAFunctionCallNodeForGenericWalks(): void
    {
        // Aggregate detection, column collection and parameter binding all walk
        // FunctionCallNode; ExtractNode has to be one of those or every walk
        // needs a special case.
        $node = $this->firstColumn("SELECT EXTRACT(YEAR FROM created_at) FROM events");
        $this->assertInstanceOf(FunctionCallNode::class, $node);
        $this->assertSame('EXTRACT', $node->name);
        $this->assertCount(1, $node->arguments);
    }

    public function testExtractSourceIsAFullExpression(): void
    {
        $node = $this->firstColumn("SELECT EXTRACT(MONTH FROM TIMESTAMP '2020-05-06 13:45:07')");
        $this->assertInstanceOf(ExtractNode::class, $node);
        $this->assertInstanceOf(LiteralNode::class, $node->arguments[0]);
        $this->assertSame('2020-05-06 13:45:07', $node->arguments[0]->value);
    }

    public function testUnsupportedExtractFieldFailsAtParseTime(): void
    {
        foreach (["SELECT EXTRACT(WEEK FROM x) FROM t", "SELECT EXTRACT(TIMEZONE_HOUR FROM x) FROM t"] as $sql) {
            try {
                $this->parser->parse($sql);
                $this->assertTrue(false, "expected a syntax error for: $sql");
            } catch (SqlSyntaxException $e) {
                $this->assertStringContainsString('EXTRACT requires one of', $e->getMessage());
            }
        }
    }

    public function testExtractWithoutFromIsAnHonestError(): void
    {
        try {
            $this->parser->parse("SELECT EXTRACT(YEAR, created_at) FROM events");
            $this->assertTrue(false, 'expected a syntax error');
        } catch (SqlSyntaxException $e) {
            $this->assertStringContainsString('EXTRACT(field FROM source)', $e->getMessage());
        }
    }

    public function testExtractRoundTripsThroughTheRenderer(): void
    {
        $sql = 'SELECT EXTRACT(YEAR FROM created_at) AS y FROM events WHERE EXTRACT(MONTH FROM created_at) = 5';
        $rendered = $this->render($sql);

        $this->assertStringContainsString('EXTRACT(YEAR FROM created_at)', $rendered);
        $this->assertStringContainsString('EXTRACT(MONTH FROM created_at)', $rendered);

        // Rendered SQL that Mini cannot re-parse breaks PartialQuery pushdown
        $this->assertSame($rendered, $this->render($rendered));
    }

    public function testTypedLiteralRendersAsThePlainStringItDenotes(): void
    {
        // SQLite - the reference backend - has no `DATE '...'` syntax, and the
        // quoted string means the same thing wherever the text form is stored.
        $this->assertSame(
            "SELECT '2020-01-01' AS d",
            $this->render("SELECT DATE '2020-01-01' AS d")
        );
        $this->assertSame(
            "SELECT * FROM events WHERE created_at < '2020-01-01 13:45:00'",
            $this->render("SELECT * FROM events WHERE created_at < TIMESTAMP '2020-01-01 13:45:00'")
        );
    }
};

exit($test->run());
