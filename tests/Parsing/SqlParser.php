<?php
/**
 * SQL Parser Tests
 *
 * Tests for lexer and parser correctness.
 */
require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Parsing\SQL\SqlLexer;
use mini\Parsing\SQL\SqlParser;
use mini\Parsing\SQL\AstParameterBinder;
use mini\Parsing\SQL\SqlSyntaxException;
use mini\Parsing\SQL\AST\{
    SelectStatement,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    BetweenOperation,
    ColumnNode,
    JoinNode,
    SubqueryNode,
    UnionNode,
    WithStatement
};

$test = new class extends Test {

    private SqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SqlParser();
    }

    // ── Lexer ───────────────────────────────────────────────────────────────

    /** t.* tokenizes correctly (no trailing dot in identifier) */
    public function testLexerTokenizesTableWildcard(): void
    {
        $lexer = new SqlLexer('SELECT t.* FROM x');
        $tokens = $lexer->tokenize();
        $this->assertSame('SELECT', $tokens[0]['type']);
        $this->assertSame('IDENTIFIER', $tokens[1]['type']);
        $this->assertSame('t', $tokens[1]['value']);
        $this->assertSame('DOT', $tokens[2]['type']);
        $this->assertSame('STAR', $tokens[3]['type']);
        $this->assertSame('*', $tokens[3]['value']);
    }

    /** table.column tokenizes as separate tokens */
    public function testLexerTokenizesQualifiedColumn(): void
    {
        $lexer = new SqlLexer('SELECT users.name FROM users');
        $tokens = $lexer->tokenize();
        $this->assertSame('IDENTIFIER', $tokens[1]['type']);
        $this->assertSame('users', $tokens[1]['value']);
        $this->assertSame('DOT', $tokens[2]['type']);
        $this->assertSame('IDENTIFIER', $tokens[3]['type']);
        $this->assertSame('name', $tokens[3]['value']);
    }

    /** Numbers with multiple dots only accept the first dot */
    public function testLexerNumbersStopAtSecondDot(): void
    {
        $lexer = new SqlLexer('SELECT 1.2.3');
        $tokens = $lexer->tokenize();
        $this->assertSame('NUMBER', $tokens[1]['type']);
        $this->assertSame('1.2', $tokens[1]['value']);
        $this->assertSame('DOT', $tokens[2]['type']);
        $this->assertSame('NUMBER', $tokens[3]['type']);
        $this->assertSame('3', $tokens[3]['value']);
    }

    public function testLexerValidDecimalNumbers(): void
    {
        $lexer = new SqlLexer('SELECT 3.14159');
        $tokens = $lexer->tokenize();
        $this->assertSame('NUMBER', $tokens[1]['type']);
        $this->assertSame('3.14159', $tokens[1]['value']);
    }

    /** Double-quoted identifiers (standard SQL) */
    public function testLexerDoubleQuotedIdentifiers(): void
    {
        $lexer = new SqlLexer('SELECT "column name" FROM "table name"');
        $tokens = $lexer->tokenize();
        $this->assertSame('IDENTIFIER', $tokens[1]['type']);
        $this->assertSame('column name', $tokens[1]['value']);
        $this->assertSame('IDENTIFIER', $tokens[3]['type']);
        $this->assertSame('table name', $tokens[3]['value']);
    }

    /** Double-quoted identifiers with escaped quotes */
    public function testLexerEscapedDoubleQuotesInIdentifiers(): void
    {
        $lexer = new SqlLexer('SELECT "col""name" FROM t');
        $tokens = $lexer->tokenize();
        $this->assertSame('IDENTIFIER', $tokens[1]['type']);
        $this->assertSame('col"name', $tokens[1]['value']);
    }

    // ── Parser: columns and identifiers ─────────────────────────────────────

    public function testSelectTableWildcardParses(): void
    {
        $ast = $this->parser->parse('SELECT t.* FROM x');
        $this->assertInstanceOf(SelectStatement::class, $ast);
        $this->assertSame(1, count($ast->columns));
        $this->assertInstanceOf(IdentifierNode::class, $ast->columns[0]->expression);
        $this->assertSame(['t', '*'], $ast->columns[0]->expression->parts);
        $this->assertTrue($ast->columns[0]->expression->isWildcard());
    }

    public function testSelectStarStillWorks(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users');
        $this->assertSame(['*'], $ast->columns[0]->expression->parts);
        $this->assertTrue($ast->columns[0]->expression->isWildcard());
    }

    public function testQualifiedColumnNames(): void
    {
        $ast = $this->parser->parse('SELECT users.id, users.name FROM users');
        $this->assertSame(['users', 'id'], $ast->columns[0]->expression->parts);
        $this->assertSame(['users', 'name'], $ast->columns[1]->expression->parts);
        $this->assertTrue($ast->columns[0]->expression->isQualified());
        $this->assertSame('id', $ast->columns[0]->expression->getName());
        $this->assertSame(['users'], $ast->columns[0]->expression->getQualifier());
    }

    /** Schema-qualified names (db.table.column) */
    public function testSchemaQualifiedNames(): void
    {
        $ast = $this->parser->parse('SELECT mydb.users.id FROM mydb.users');
        $this->assertSame(['mydb', 'users', 'id'], $ast->columns[0]->expression->parts);
        $this->assertSame('id', $ast->columns[0]->expression->getName());
        $this->assertSame(['mydb', 'users'], $ast->columns[0]->expression->getQualifier());
    }

    /** Quoted identifiers with dots inside (backticks) */
    public function testBacktickIdentifiersWithInternalDots(): void
    {
        $ast = $this->parser->parse('SELECT `my.table`.`weird-col` FROM `my.table`');
        $this->assertSame(['my.table', 'weird-col'], $ast->columns[0]->expression->parts);
        $this->assertSame('weird-col', $ast->columns[0]->expression->getName());
        $this->assertSame('my.table.weird-col', $ast->columns[0]->expression->getFullName());
    }

    public function testDoubleQuotedIdentifiersParse(): void
    {
        $ast = $this->parser->parse('SELECT "user name", "order-date" FROM "my table"');
        $this->assertSame(['user name'], $ast->columns[0]->expression->parts);
        $this->assertSame(['order-date'], $ast->columns[1]->expression->parts);
        $this->assertSame('my table', $ast->from->getName());
    }

    /** Mixed quote styles (backticks and double quotes) */
    public function testMixedQuoteStyles(): void
    {
        $ast = $this->parser->parse('SELECT `col1`, "col2" FROM `t1`');
        $this->assertSame(['col1'], $ast->columns[0]->expression->parts);
        $this->assertSame(['col2'], $ast->columns[1]->expression->parts);
    }

    // ── Parser: expressions ─────────────────────────────────────────────────

    /** a + b * c parses as a + (b * c) */
    public function testArithmeticPrecedence(): void
    {
        $ast = $this->parser->parse('SELECT a + b * c FROM t');
        $expr = $ast->columns[0]->expression;
        $this->assertInstanceOf(BinaryOperation::class, $expr);
        $this->assertSame('+', $expr->operator);
        // Left should be 'a', right should be 'b * c'
        $this->assertInstanceOf(IdentifierNode::class, $expr->left);
        $this->assertSame('a', $expr->left->getName());
        $this->assertInstanceOf(BinaryOperation::class, $expr->right);
        $this->assertSame('*', $expr->right->operator);
    }

    public function testDivisionOperator(): void
    {
        $ast = $this->parser->parse('SELECT total / count FROM stats');
        $expr = $ast->columns[0]->expression;
        $this->assertInstanceOf(BinaryOperation::class, $expr);
        $this->assertSame('/', $expr->operator);
    }

    public function testLimitWithPlaceholder(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LIMIT ?');
        $this->assertInstanceOf(PlaceholderNode::class, $ast->limit);
        $this->assertSame('?', $ast->limit->token);
    }

    public function testLimitWithNumberLiteral(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LIMIT 10');
        $this->assertInstanceOf(LiteralNode::class, $ast->limit);
        $this->assertSame('10', $ast->limit->value);
    }

    /** Comparison operators restricted (reject arithmetic as comparison) */
    public function testComparisonOperators(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users WHERE age > 18');
        $this->assertInstanceOf(BinaryOperation::class, $ast->where);
        $this->assertSame('>', $ast->where->operator);
    }

    /** LIMIT rejects non-number/placeholder */
    public function testLimitRejectsIdentifierAndString(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM users LIMIT foo'),
            SqlSyntaxException::class
        );
        $this->assertThrows(
            fn() => $this->parser->parse("SELECT * FROM users LIMIT 'ten'"),
            SqlSyntaxException::class
        );
    }

    public function testInWithScalarValues(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE x IN (1, 2, 3)');
        $this->assertInstanceOf(InOperation::class, $ast->where);
        $this->assertCount(3, $ast->where->values);
    }

    public function testInWithArithmeticExpressions(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE x IN (a + 1, b * 2)');
        $this->assertInstanceOf(InOperation::class, $ast->where);
        $this->assertInstanceOf(BinaryOperation::class, $ast->where->values[0]);
    }

    /** IN rejects boolean expressions (comparison in list would leave trailing tokens) */
    public function testInRejectsComparisonExpressions(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM t WHERE x IN (a = b)'),
            SqlSyntaxException::class
        );
    }

    /** Generic NOT boolean operator */
    public function testGenericNot(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE NOT is_active');
        $this->assertInstanceOf(UnaryOperation::class, $ast->where);
        $this->assertSame('NOT', $ast->where->operator);
        $this->assertInstanceOf(IdentifierNode::class, $ast->where->expression);
    }

    public function testNotWithParenthesizedExpression(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE NOT (a = b)');
        $this->assertInstanceOf(UnaryOperation::class, $ast->where);
        $this->assertSame('NOT', $ast->where->operator);
        $this->assertInstanceOf(BinaryOperation::class, $ast->where->expression);
    }

    public function testNullLiteral(): void
    {
        $ast = $this->parser->parse('SELECT NULL FROM t');
        $this->assertInstanceOf(LiteralNode::class, $ast->columns[0]->expression);
        $this->assertNull($ast->columns[0]->expression->value);
        $this->assertSame('null', $ast->columns[0]->expression->valueType);
    }

    public function testTrueFalseLiterals(): void
    {
        $ast = $this->parser->parse('SELECT TRUE, FALSE FROM t');
        $this->assertInstanceOf(LiteralNode::class, $ast->columns[0]->expression);
        $this->assertSame(true, $ast->columns[0]->expression->value);
        $this->assertSame('boolean', $ast->columns[0]->expression->valueType);
        $this->assertSame(false, $ast->columns[1]->expression->value);
    }

    public function testBooleanInWhere(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE is_active = TRUE');
        $this->assertInstanceOf(BinaryOperation::class, $ast->where);
        $this->assertInstanceOf(LiteralNode::class, $ast->where->right);
        $this->assertSame(true, $ast->where->right->value);
    }

    // ── JOIN ────────────────────────────────────────────────────────────────

    /** Simple JOIN defaults to INNER */
    public function testSimpleJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
        $this->assertCount(1, $ast->joins);
        $this->assertInstanceOf(JoinNode::class, $ast->joins[0]);
        $this->assertSame('INNER', $ast->joins[0]->joinType);
        $this->assertSame(['orders'], $ast->joins[0]->table->parts);
        $this->assertInstanceOf(BinaryOperation::class, $ast->joins[0]->condition);
    }

    public function testInnerJoinExplicit(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id');
        $this->assertSame('INNER', $ast->joins[0]->joinType);
    }

    public function testLeftJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');
        $this->assertSame('LEFT', $ast->joins[0]->joinType);
    }

    public function testLeftOuterJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LEFT OUTER JOIN orders ON users.id = orders.user_id');
        $this->assertSame('LEFT', $ast->joins[0]->joinType);
    }

    public function testRightJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users RIGHT JOIN orders ON users.id = orders.user_id');
        $this->assertSame('RIGHT', $ast->joins[0]->joinType);
    }

    public function testFullJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM a FULL JOIN b ON a.id = b.id');
        $this->assertSame('FULL', $ast->joins[0]->joinType);
    }

    /** CROSS JOIN (no ON clause) */
    public function testCrossJoin(): void
    {
        $ast = $this->parser->parse('SELECT * FROM a CROSS JOIN b');
        $this->assertSame('CROSS', $ast->joins[0]->joinType);
        $this->assertNull($ast->joins[0]->condition);
    }

    public function testMultipleJoins(): void
    {
        $ast = $this->parser->parse('
            SELECT * FROM users u
            JOIN orders o ON u.id = o.user_id
            LEFT JOIN products p ON o.product_id = p.id
        ');
        $this->assertCount(2, $ast->joins);
        $this->assertSame('INNER', $ast->joins[0]->joinType);
        $this->assertSame('LEFT', $ast->joins[1]->joinType);
    }

    public function testJoinWithTableAlias(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users u JOIN orders o ON u.id = o.user_id');
        $this->assertSame('u', $ast->fromAlias);
        $this->assertSame('o', $ast->joins[0]->alias);
    }

    public function testJoinWithAsAlias(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users AS u JOIN orders AS o ON u.id = o.user_id');
        $this->assertSame('u', $ast->fromAlias);
        $this->assertSame('o', $ast->joins[0]->alias);
    }

    public function testJoinWithQualifiedTableNames(): void
    {
        $ast = $this->parser->parse('SELECT * FROM mydb.users JOIN mydb.orders ON users.id = orders.user_id');
        $this->assertSame(['mydb', 'users'], $ast->from->parts);
        $this->assertSame(['mydb', 'orders'], $ast->joins[0]->table->parts);
    }

    /** JOIN requires ON (except CROSS) */
    public function testJoinWithoutOnThrows(): void
    {
        $this->assertThrows(
            fn() => $this->parser->parse('SELECT * FROM a JOIN b'),
            SqlSyntaxException::class
        );
    }

    public function testJoinConditionWithPlaceholderBinds(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users u JOIN orders o ON u.id = o.user_id AND o.status = ?');
        $binder = new AstParameterBinder(['active']);
        $bound = $binder->bind($ast);
        $this->assertInstanceOf(BinaryOperation::class, $bound->joins[0]->condition);
    }

    // ── DISTINCT ────────────────────────────────────────────────────────────

    public function testSelectDistinct(): void
    {
        $ast = $this->parser->parse('SELECT DISTINCT name FROM users');
        $this->assertTrue($ast->distinct);
    }

    public function testSelectWithoutDistinct(): void
    {
        $ast = $this->parser->parse('SELECT name FROM users');
        $this->assertFalse($ast->distinct);
    }

    // ── GROUP BY / HAVING ───────────────────────────────────────────────────

    public function testGroupBySingleColumn(): void
    {
        $ast = $this->parser->parse('SELECT status, COUNT(*) FROM orders GROUP BY status');
        $this->assertNotNull($ast->groupBy);
        $this->assertCount(1, $ast->groupBy);
        $this->assertInstanceOf(IdentifierNode::class, $ast->groupBy[0]);
    }

    public function testGroupByMultipleColumns(): void
    {
        $ast = $this->parser->parse('SELECT year, month, SUM(total) FROM sales GROUP BY year, month');
        $this->assertCount(2, $ast->groupBy);
    }

    public function testGroupByWithHaving(): void
    {
        $ast = $this->parser->parse('SELECT status, COUNT(*) c FROM orders GROUP BY status HAVING c > 10');
        $this->assertNotNull($ast->groupBy);
        $this->assertNotNull($ast->having);
        $this->assertInstanceOf(BinaryOperation::class, $ast->having);
    }

    /** Full query with GROUP BY, HAVING, ORDER BY */
    public function testFullAggregationQuery(): void
    {
        $ast = $this->parser->parse('
            SELECT category, COUNT(*) cnt
            FROM products
            WHERE active = TRUE
            GROUP BY category
            HAVING cnt > 5
            ORDER BY cnt DESC
            LIMIT 10
        ');
        $this->assertNotNull($ast->where);
        $this->assertNotNull($ast->groupBy);
        $this->assertNotNull($ast->having);
        $this->assertNotNull($ast->orderBy);
        $this->assertNotNull($ast->limit);
    }

    // ── BETWEEN ─────────────────────────────────────────────────────────────

    public function testBetween(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE x BETWEEN 1 AND 10');
        $this->assertInstanceOf(BetweenOperation::class, $ast->where);
        $this->assertFalse($ast->where->negated);
        $this->assertInstanceOf(LiteralNode::class, $ast->where->low);
        $this->assertInstanceOf(LiteralNode::class, $ast->where->high);
    }

    public function testNotBetween(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE x NOT BETWEEN 1 AND 10');
        $this->assertInstanceOf(BetweenOperation::class, $ast->where);
        $this->assertTrue($ast->where->negated);
    }

    public function testBetweenWithPlaceholdersBinds(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE date BETWEEN ? AND ?');
        $binder = new AstParameterBinder(['2024-01-01', '2024-12-31']);
        $bound = $binder->bind($ast);
        $this->assertInstanceOf(LiteralNode::class, $bound->where->low);
        $this->assertSame('2024-01-01', $bound->where->low->value);
    }

    // ── Parameter binder ────────────────────────────────────────────────────

    /** Null binding stores actual null */
    public function testNullBindsAsActualNull(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users WHERE name = ?');
        $binder = new AstParameterBinder([null]);
        $bound = $binder->bind($ast);
        $this->assertInstanceOf(LiteralNode::class, $bound->where->right);
        $this->assertSame('null', $bound->where->right->valueType);
        $this->assertNull($bound->where->right->value);
    }

    public function testLimitPlaceholderBinds(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LIMIT ?');
        $binder = new AstParameterBinder([25]);
        $bound = $binder->bind($ast);
        $this->assertInstanceOf(LiteralNode::class, $bound->limit);
        $this->assertSame('25', $bound->limit->value);
        $this->assertSame('number', $bound->limit->valueType);
    }

    public function testColumnExpressionPlaceholdersBind(): void
    {
        $ast = $this->parser->parse('SELECT price * ? AS discounted FROM products');
        $binder = new AstParameterBinder([0.9]);
        $bound = $binder->bind($ast);
        $expr = $bound->columns[0]->expression;
        $this->assertInstanceOf(BinaryOperation::class, $expr);
        $this->assertInstanceOf(LiteralNode::class, $expr->right);
        $this->assertSame('0.9', $expr->right->value);
    }

    public function testInsertPlaceholdersBind(): void
    {
        $ast = $this->parser->parse('INSERT INTO users (name, age) VALUES (?, ?)');
        $binder = new AstParameterBinder(['Alice', 30]);
        $bound = $binder->bind($ast);
        $this->assertSame('Alice', $bound->values[0][0]->value);
        $this->assertSame('30', $bound->values[0][1]->value);
    }

    public function testUpdatePlaceholdersBind(): void
    {
        $ast = $this->parser->parse('UPDATE users SET name = ? WHERE id = ?');
        $binder = new AstParameterBinder(['Bob', 1]);
        $bound = $binder->bind($ast);
        $this->assertSame('Bob', $bound->updates[0]['value']->value);
        $this->assertSame('1', $bound->where->right->value);
    }

    public function testDeletePlaceholdersBind(): void
    {
        $ast = $this->parser->parse('DELETE FROM users WHERE id = ?');
        $binder = new AstParameterBinder([42]);
        $bound = $binder->bind($ast);
        $this->assertSame('42', $bound->where->right->value);
    }

    public function testNamedPlaceholdersBind(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users WHERE name = :name AND age > :age');
        $binder = new AstParameterBinder(['name' => 'Alice', 'age' => 18]);
        $bound = $binder->bind($ast);
        $this->assertSame('Alice', $bound->where->left->right->value);
        $this->assertSame('18', $bound->where->right->right->value);
    }

    // ── Scalar subqueries ───────────────────────────────────────────────────

    public function testSimpleScalarSubquery(): void
    {
        $ast = $this->parser->parse('SELECT (SELECT MAX(id) FROM users) AS max_id FROM dual');
        $this->assertInstanceOf(SubqueryNode::class, $ast->columns[0]->expression);
        $this->assertInstanceOf(SelectStatement::class, $ast->columns[0]->expression->query);
    }

    public function testScalarSubqueryWithUnion(): void
    {
        $ast = $this->parser->parse('SELECT (SELECT 1 UNION SELECT 2) AS val FROM dual');
        $this->assertInstanceOf(SubqueryNode::class, $ast->columns[0]->expression);
        $this->assertInstanceOf(UnionNode::class, $ast->columns[0]->expression->query);
    }

    public function testScalarSubqueryWithCte(): void
    {
        $ast = $this->parser->parse('SELECT (WITH cte AS (SELECT 1 AS n) SELECT n FROM cte) AS val FROM dual');
        $this->assertInstanceOf(SubqueryNode::class, $ast->columns[0]->expression);
        $this->assertInstanceOf(WithStatement::class, $ast->columns[0]->expression->query);
    }

    public function testScalarSubqueryInWhere(): void
    {
        $ast = $this->parser->parse('SELECT * FROM t WHERE x = (SELECT MAX(y) FROM s)');
        $this->assertInstanceOf(BinaryOperation::class, $ast->where);
        $this->assertInstanceOf(SubqueryNode::class, $ast->where->right);
    }

    // ── SQL:2008 FETCH/OFFSET ───────────────────────────────────────────────

    /** FETCH FIRST n ROWS ONLY (no offset) */
    public function testFetchFirstRowsOnly(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users FETCH FIRST 10 ROWS ONLY');
        $this->assertSame('10', $ast->limit->value);
        $this->assertSame(null, $ast->offset);
    }

    /** FETCH NEXT n ROWS ONLY (same as FIRST) */
    public function testFetchNextRowsOnly(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users FETCH NEXT 5 ROWS ONLY');
        $this->assertSame('5', $ast->limit->value);
        $this->assertSame(null, $ast->offset);
    }

    public function testOffsetRowsFetchFirstRowsOnly(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users OFFSET 10 ROWS FETCH FIRST 5 ROWS ONLY');
        $this->assertSame('10', $ast->offset->value);
        $this->assertSame('5', $ast->limit->value);
    }

    public function testOffsetRowsFetchNextRowsOnly(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY');
        $this->assertSame('20', $ast->offset->value);
        $this->assertSame('10', $ast->limit->value);
    }

    /** OFFSET n ROWS without FETCH (offset only) */
    public function testOffsetRowsWithoutFetch(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users OFFSET 5 ROWS');
        $this->assertSame('5', $ast->offset->value);
        $this->assertSame(null, $ast->limit);
    }

    /** Simple OFFSET n (PostgreSQL style, no ROWS keyword) */
    public function testSimpleOffsetPostgresStyle(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users OFFSET 5');
        $this->assertSame('5', $ast->offset->value);
        $this->assertSame(null, $ast->limit);
    }

    /** FETCH with ROW (singular) instead of ROWS */
    public function testFetchWithSingularRow(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users FETCH FIRST 1 ROW ONLY');
        $this->assertSame('1', $ast->limit->value);
    }

    public function testSql2008SyntaxWithOrderBy(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users ORDER BY id OFFSET 10 ROWS FETCH NEXT 5 ROWS ONLY');
        $this->assertSame('id', $ast->orderBy[0]['column']->getName());
        $this->assertSame('10', $ast->offset->value);
        $this->assertSame('5', $ast->limit->value);
    }

    public function testSql2008SyntaxWithPlaceholders(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users OFFSET ? ROWS FETCH NEXT ? ROWS ONLY');
        $this->assertInstanceOf(PlaceholderNode::class, $ast->offset);
        $this->assertInstanceOf(PlaceholderNode::class, $ast->limit);
    }

    public function testTraditionalLimitOffsetStillWorks(): void
    {
        $ast = $this->parser->parse('SELECT * FROM users LIMIT 10 OFFSET 5');
        $this->assertSame('10', $ast->limit->value);
        $this->assertSame('5', $ast->offset->value);
    }
};

exit($test->run());
