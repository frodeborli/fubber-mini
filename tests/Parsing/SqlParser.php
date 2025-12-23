<?php
/**
 * SQL Parser Tests
 *
 * Tests for lexer and parser correctness.
 */
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../assert.php';

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
    JoinNode
};

// --- Lexer Tests ---

// Test: t.* tokenizes correctly (no trailing dot in identifier)
$lexer = new SqlLexer('SELECT t.* FROM x');
$tokens = $lexer->tokenize();
assert_eq('SELECT', $tokens[0]['type']);
assert_eq('IDENTIFIER', $tokens[1]['type']);
assert_eq('t', $tokens[1]['value']);
assert_eq('DOT', $tokens[2]['type']);
assert_eq('STAR', $tokens[3]['type']);
assert_eq('*', $tokens[3]['value']);
echo "✓ t.* tokenizes correctly\n";

// Test: table.column tokenizes as separate tokens
$lexer = new SqlLexer('SELECT users.name FROM users');
$tokens = $lexer->tokenize();
assert_eq('IDENTIFIER', $tokens[1]['type']);
assert_eq('users', $tokens[1]['value']);
assert_eq('DOT', $tokens[2]['type']);
assert_eq('IDENTIFIER', $tokens[3]['type']);
assert_eq('name', $tokens[3]['value']);
echo "✓ table.column tokenizes correctly\n";

// Test: Numbers with multiple dots only accept first dot
$lexer = new SqlLexer('SELECT 1.2.3');
$tokens = $lexer->tokenize();
assert_eq('NUMBER', $tokens[1]['type']);
assert_eq('1.2', $tokens[1]['value']);
assert_eq('DOT', $tokens[2]['type']);
assert_eq('NUMBER', $tokens[3]['type']);
assert_eq('3', $tokens[3]['value']);
echo "✓ Numbers stop at second dot\n";

// Test: Valid decimal numbers
$lexer = new SqlLexer('SELECT 3.14159');
$tokens = $lexer->tokenize();
assert_eq('NUMBER', $tokens[1]['type']);
assert_eq('3.14159', $tokens[1]['value']);
echo "✓ Valid decimal numbers work\n";

// --- Parser Tests ---

// Test: SELECT t.* FROM x parses correctly
$parser = new SqlParser();
$ast = $parser->parse('SELECT t.* FROM x');
assert_true($ast instanceof SelectStatement);
assert_eq(1, count($ast->columns));
assert_true($ast->columns[0]->expression instanceof IdentifierNode);
assert_eq(['t', '*'], $ast->columns[0]->expression->parts);
assert_true($ast->columns[0]->expression->isWildcard());
echo "✓ SELECT t.* parses correctly\n";

// Test: SELECT * still works
$ast = $parser->parse('SELECT * FROM users');
assert_eq(['*'], $ast->columns[0]->expression->parts);
assert_true($ast->columns[0]->expression->isWildcard());
echo "✓ SELECT * still works\n";

// Test: Qualified column names
$ast = $parser->parse('SELECT users.id, users.name FROM users');
assert_eq(['users', 'id'], $ast->columns[0]->expression->parts);
assert_eq(['users', 'name'], $ast->columns[1]->expression->parts);
assert_true($ast->columns[0]->expression->isQualified());
assert_eq('id', $ast->columns[0]->expression->getName());
assert_eq(['users'], $ast->columns[0]->expression->getQualifier());
echo "✓ Qualified column names work\n";

// Test: Schema-qualified names (db.table.column)
$ast = $parser->parse('SELECT mydb.users.id FROM mydb.users');
assert_eq(['mydb', 'users', 'id'], $ast->columns[0]->expression->parts);
assert_eq('id', $ast->columns[0]->expression->getName());
assert_eq(['mydb', 'users'], $ast->columns[0]->expression->getQualifier());
echo "✓ Schema-qualified names work\n";

// Test: Quoted identifiers with dots inside (backticks)
$ast = $parser->parse('SELECT `my.table`.`weird-col` FROM `my.table`');
assert_eq(['my.table', 'weird-col'], $ast->columns[0]->expression->parts);
assert_eq('weird-col', $ast->columns[0]->expression->getName());
assert_eq('my.table.weird-col', $ast->columns[0]->expression->getFullName());
echo "✓ Backtick-quoted identifiers with internal dots work\n";

// Test: Double-quoted identifiers (standard SQL)
$lexer = new SqlLexer('SELECT "column name" FROM "table name"');
$tokens = $lexer->tokenize();
assert_eq('IDENTIFIER', $tokens[1]['type']);
assert_eq('column name', $tokens[1]['value']);
assert_eq('IDENTIFIER', $tokens[3]['type']);
assert_eq('table name', $tokens[3]['value']);
echo "✓ Double-quoted identifiers tokenize correctly\n";

// Test: Double-quoted identifiers with escaped quotes
$lexer = new SqlLexer('SELECT "col""name" FROM t');
$tokens = $lexer->tokenize();
assert_eq('IDENTIFIER', $tokens[1]['type']);
assert_eq('col"name', $tokens[1]['value']);
echo "✓ Escaped double quotes in identifiers work\n";

// Test: Double-quoted identifiers parse correctly
$ast = $parser->parse('SELECT "user name", "order-date" FROM "my table"');
assert_eq(['user name'], $ast->columns[0]->expression->parts);
assert_eq(['order-date'], $ast->columns[1]->expression->parts);
assert_eq('my table', $ast->from->getName());
echo "✓ Double-quoted identifiers parse correctly\n";

// Test: Mixed quote styles (backticks and double quotes)
$ast = $parser->parse('SELECT `col1`, "col2" FROM `t1`');
assert_eq(['col1'], $ast->columns[0]->expression->parts);
assert_eq(['col2'], $ast->columns[1]->expression->parts);
echo "✓ Mixed backtick and double-quote identifiers work\n";

// Test: Arithmetic with proper precedence (multiplication before addition)
$ast = $parser->parse('SELECT a + b * c FROM t');
$expr = $ast->columns[0]->expression;
assert_true($expr instanceof BinaryOperation);
assert_eq('+', $expr->operator);
// Left should be 'a', right should be 'b * c'
assert_true($expr->left instanceof IdentifierNode);
assert_eq('a', $expr->left->getName());
assert_true($expr->right instanceof BinaryOperation);
assert_eq('*', $expr->right->operator);
echo "✓ Arithmetic precedence: a + b * c parses as a + (b * c)\n";

// Test: Arithmetic with division
$ast = $parser->parse('SELECT total / count FROM stats');
$expr = $ast->columns[0]->expression;
assert_true($expr instanceof BinaryOperation);
assert_eq('/', $expr->operator);
echo "✓ Division operator works\n";

// Test: LIMIT with placeholder
$ast = $parser->parse('SELECT * FROM users LIMIT ?');
assert_true($ast->limit instanceof PlaceholderNode);
assert_eq('?', $ast->limit->token);
echo "✓ LIMIT ? parses correctly\n";

// Test: LIMIT with number literal
$ast = $parser->parse('SELECT * FROM users LIMIT 10');
assert_true($ast->limit instanceof LiteralNode);
assert_eq('10', $ast->limit->value);
echo "✓ LIMIT 10 parses correctly\n";

// Test: Comparison operators restricted (reject arithmetic as comparison)
$ast = $parser->parse('SELECT * FROM users WHERE age > 18');
assert_true($ast->where instanceof BinaryOperation);
assert_eq('>', $ast->where->operator);
echo "✓ Comparison operators work\n";

// Test: LIMIT rejects non-number/placeholder
assert_throws(
    fn() => $parser->parse('SELECT * FROM users LIMIT foo'),
    SqlSyntaxException::class
);
echo "✓ LIMIT rejects identifier\n";

assert_throws(
    fn() => $parser->parse("SELECT * FROM users LIMIT 'ten'"),
    SqlSyntaxException::class
);
echo "✓ LIMIT rejects string\n";

// Test: IN accepts scalar values
$ast = $parser->parse('SELECT * FROM t WHERE x IN (1, 2, 3)');
assert_true($ast->where instanceof InOperation);
assert_count(3, $ast->where->values);
echo "✓ IN with scalar values works\n";

// Test: IN accepts arithmetic expressions
$ast = $parser->parse('SELECT * FROM t WHERE x IN (a + 1, b * 2)');
assert_true($ast->where instanceof InOperation);
assert_true($ast->where->values[0] instanceof BinaryOperation);
echo "✓ IN with arithmetic expressions works\n";

// Test: IN rejects boolean expressions (comparison in list would leave trailing tokens)
assert_throws(
    fn() => $parser->parse('SELECT * FROM t WHERE x IN (a = b)'),
    SqlSyntaxException::class
);
echo "✓ IN rejects comparison expressions\n";

// Test: Generic NOT boolean operator
$ast = $parser->parse('SELECT * FROM t WHERE NOT is_active');
assert_true($ast->where instanceof UnaryOperation);
assert_eq('NOT', $ast->where->operator);
assert_true($ast->where->expression instanceof IdentifierNode);
echo "✓ Generic NOT works\n";

// Test: NOT with parenthesized expression
$ast = $parser->parse('SELECT * FROM t WHERE NOT (a = b)');
assert_true($ast->where instanceof UnaryOperation);
assert_eq('NOT', $ast->where->operator);
assert_true($ast->where->expression instanceof BinaryOperation);
echo "✓ NOT (expr) works\n";

// Test: NULL literal
$ast = $parser->parse('SELECT NULL FROM t');
assert_true($ast->columns[0]->expression instanceof LiteralNode);
assert_null($ast->columns[0]->expression->value);
assert_eq('null', $ast->columns[0]->expression->valueType);
echo "✓ NULL literal works\n";

// Test: TRUE/FALSE literals
$ast = $parser->parse('SELECT TRUE, FALSE FROM t');
assert_true($ast->columns[0]->expression instanceof LiteralNode);
assert_eq(true, $ast->columns[0]->expression->value);
assert_eq('boolean', $ast->columns[0]->expression->valueType);
assert_eq(false, $ast->columns[1]->expression->value);
echo "✓ TRUE/FALSE literals work\n";

// Test: Boolean in WHERE
$ast = $parser->parse('SELECT * FROM t WHERE is_active = TRUE');
assert_true($ast->where instanceof BinaryOperation);
assert_true($ast->where->right instanceof LiteralNode);
assert_eq(true, $ast->where->right->value);
echo "✓ Boolean in WHERE works\n";

// --- JOIN Tests ---

// Test: Simple JOIN (INNER)
$ast = $parser->parse('SELECT * FROM users JOIN orders ON users.id = orders.user_id');
assert_count(1, $ast->joins);
assert_true($ast->joins[0] instanceof JoinNode);
assert_eq('INNER', $ast->joins[0]->joinType);
assert_eq(['orders'], $ast->joins[0]->table->parts);
assert_true($ast->joins[0]->condition instanceof BinaryOperation);
echo "✓ Simple JOIN works\n";

// Test: INNER JOIN explicit
$ast = $parser->parse('SELECT * FROM users INNER JOIN orders ON users.id = orders.user_id');
assert_eq('INNER', $ast->joins[0]->joinType);
echo "✓ INNER JOIN works\n";

// Test: LEFT JOIN
$ast = $parser->parse('SELECT * FROM users LEFT JOIN orders ON users.id = orders.user_id');
assert_eq('LEFT', $ast->joins[0]->joinType);
echo "✓ LEFT JOIN works\n";

// Test: LEFT OUTER JOIN
$ast = $parser->parse('SELECT * FROM users LEFT OUTER JOIN orders ON users.id = orders.user_id');
assert_eq('LEFT', $ast->joins[0]->joinType);
echo "✓ LEFT OUTER JOIN works\n";

// Test: RIGHT JOIN
$ast = $parser->parse('SELECT * FROM users RIGHT JOIN orders ON users.id = orders.user_id');
assert_eq('RIGHT', $ast->joins[0]->joinType);
echo "✓ RIGHT JOIN works\n";

// Test: FULL JOIN
$ast = $parser->parse('SELECT * FROM a FULL JOIN b ON a.id = b.id');
assert_eq('FULL', $ast->joins[0]->joinType);
echo "✓ FULL JOIN works\n";

// Test: CROSS JOIN (no ON clause)
$ast = $parser->parse('SELECT * FROM a CROSS JOIN b');
assert_eq('CROSS', $ast->joins[0]->joinType);
assert_null($ast->joins[0]->condition);
echo "✓ CROSS JOIN works\n";

// Test: Multiple JOINs
$ast = $parser->parse('
    SELECT * FROM users u
    JOIN orders o ON u.id = o.user_id
    LEFT JOIN products p ON o.product_id = p.id
');
assert_count(2, $ast->joins);
assert_eq('INNER', $ast->joins[0]->joinType);
assert_eq('LEFT', $ast->joins[1]->joinType);
echo "✓ Multiple JOINs work\n";

// Test: JOIN with table alias
$ast = $parser->parse('SELECT * FROM users u JOIN orders o ON u.id = o.user_id');
assert_eq('u', $ast->fromAlias);
assert_eq('o', $ast->joins[0]->alias);
echo "✓ JOIN with aliases works\n";

// Test: JOIN with AS alias
$ast = $parser->parse('SELECT * FROM users AS u JOIN orders AS o ON u.id = o.user_id');
assert_eq('u', $ast->fromAlias);
assert_eq('o', $ast->joins[0]->alias);
echo "✓ JOIN with AS aliases works\n";

// Test: JOIN with qualified table name
$ast = $parser->parse('SELECT * FROM mydb.users JOIN mydb.orders ON users.id = orders.user_id');
assert_eq(['mydb', 'users'], $ast->from->parts);
assert_eq(['mydb', 'orders'], $ast->joins[0]->table->parts);
echo "✓ JOIN with qualified table names works\n";

// Test: JOIN requires ON (except CROSS)
assert_throws(
    fn() => $parser->parse('SELECT * FROM a JOIN b'),
    SqlSyntaxException::class
);
echo "✓ JOIN without ON throws error\n";

// Test: JOIN with placeholder in condition
$ast = $parser->parse('SELECT * FROM users u JOIN orders o ON u.id = o.user_id AND o.status = ?');
$binder = new AstParameterBinder(['active']);
$bound = $binder->bind($ast);
assert_true($bound->joins[0]->condition instanceof BinaryOperation);
echo "✓ JOIN condition with placeholder binds correctly\n";

// --- DISTINCT Tests ---

// Test: SELECT DISTINCT
$ast = $parser->parse('SELECT DISTINCT name FROM users');
assert_true($ast->distinct);
echo "✓ SELECT DISTINCT works\n";

// Test: SELECT without DISTINCT
$ast = $parser->parse('SELECT name FROM users');
assert_false($ast->distinct);
echo "✓ SELECT without DISTINCT has distinct=false\n";

// --- GROUP BY / HAVING Tests ---

// Test: GROUP BY single column
$ast = $parser->parse('SELECT status, COUNT(*) FROM orders GROUP BY status');
assert_not_null($ast->groupBy);
assert_count(1, $ast->groupBy);
assert_true($ast->groupBy[0] instanceof IdentifierNode);
echo "✓ GROUP BY single column works\n";

// Test: GROUP BY multiple columns
$ast = $parser->parse('SELECT year, month, SUM(total) FROM sales GROUP BY year, month');
assert_count(2, $ast->groupBy);
echo "✓ GROUP BY multiple columns works\n";

// Test: GROUP BY with HAVING
$ast = $parser->parse('SELECT status, COUNT(*) c FROM orders GROUP BY status HAVING c > 10');
assert_not_null($ast->groupBy);
assert_not_null($ast->having);
assert_true($ast->having instanceof BinaryOperation);
echo "✓ GROUP BY with HAVING works\n";

// Test: Full query with GROUP BY, HAVING, ORDER BY
$ast = $parser->parse('
    SELECT category, COUNT(*) cnt
    FROM products
    WHERE active = TRUE
    GROUP BY category
    HAVING cnt > 5
    ORDER BY cnt DESC
    LIMIT 10
');
assert_not_null($ast->where);
assert_not_null($ast->groupBy);
assert_not_null($ast->having);
assert_not_null($ast->orderBy);
assert_not_null($ast->limit);
echo "✓ Full aggregation query works\n";

// --- BETWEEN Tests ---

// Test: BETWEEN
$ast = $parser->parse('SELECT * FROM t WHERE x BETWEEN 1 AND 10');
assert_true($ast->where instanceof BetweenOperation);
assert_false($ast->where->negated);
assert_true($ast->where->low instanceof LiteralNode);
assert_true($ast->where->high instanceof LiteralNode);
echo "✓ BETWEEN works\n";

// Test: NOT BETWEEN
$ast = $parser->parse('SELECT * FROM t WHERE x NOT BETWEEN 1 AND 10');
assert_true($ast->where instanceof BetweenOperation);
assert_true($ast->where->negated);
echo "✓ NOT BETWEEN works\n";

// Test: BETWEEN with expressions
$ast = $parser->parse('SELECT * FROM t WHERE date BETWEEN ? AND ?');
$binder = new AstParameterBinder(['2024-01-01', '2024-12-31']);
$bound = $binder->bind($ast);
assert_true($bound->where->low instanceof LiteralNode);
assert_eq('2024-01-01', $bound->where->low->value);
echo "✓ BETWEEN with placeholders binds correctly\n";

// --- Binder Tests ---

// Test: Null binding stores actual null
$parser = new SqlParser();
$ast = $parser->parse('SELECT * FROM users WHERE name = ?');
$binder = new AstParameterBinder([null]);
$bound = $binder->bind($ast);
assert_true($bound->where->right instanceof LiteralNode);
assert_eq('null', $bound->where->right->valueType);
assert_null($bound->where->right->value);
echo "✓ Null binds as actual null value\n";

// Test: LIMIT placeholder binding
$ast = $parser->parse('SELECT * FROM users LIMIT ?');
$binder = new AstParameterBinder([25]);
$bound = $binder->bind($ast);
assert_true($bound->limit instanceof LiteralNode);
assert_eq('25', $bound->limit->value);
assert_eq('number', $bound->limit->valueType);
echo "✓ LIMIT placeholder binds correctly\n";

// Test: Column expression binding
$ast = $parser->parse('SELECT price * ? AS discounted FROM products');
$binder = new AstParameterBinder([0.9]);
$bound = $binder->bind($ast);
$expr = $bound->columns[0]->expression;
assert_true($expr instanceof BinaryOperation);
assert_true($expr->right instanceof LiteralNode);
assert_eq('0.9', $expr->right->value);
echo "✓ Column expression placeholders bind correctly\n";

// Test: INSERT binding
$ast = $parser->parse('INSERT INTO users (name, age) VALUES (?, ?)');
$binder = new AstParameterBinder(['Alice', 30]);
$bound = $binder->bind($ast);
assert_eq('Alice', $bound->values[0][0]->value);
assert_eq('30', $bound->values[0][1]->value);
echo "✓ INSERT placeholders bind correctly\n";

// Test: UPDATE binding
$ast = $parser->parse('UPDATE users SET name = ? WHERE id = ?');
$binder = new AstParameterBinder(['Bob', 1]);
$bound = $binder->bind($ast);
assert_eq('Bob', $bound->updates[0]['value']->value);
assert_eq('1', $bound->where->right->value);
echo "✓ UPDATE placeholders bind correctly\n";

// Test: DELETE binding
$ast = $parser->parse('DELETE FROM users WHERE id = ?');
$binder = new AstParameterBinder([42]);
$bound = $binder->bind($ast);
assert_eq('42', $bound->where->right->value);
echo "✓ DELETE placeholders bind correctly\n";

// Test: Named placeholders
$ast = $parser->parse('SELECT * FROM users WHERE name = :name AND age > :age');
$binder = new AstParameterBinder(['name' => 'Alice', 'age' => 18]);
$bound = $binder->bind($ast);
assert_eq('Alice', $bound->where->left->right->value);
assert_eq('18', $bound->where->right->right->value);
echo "✓ Named placeholders bind correctly\n";

// --- Scalar Subquery Tests ---

use mini\Parsing\SQL\AST\SubqueryNode;
use mini\Parsing\SQL\AST\UnionNode;
use mini\Parsing\SQL\AST\WithStatement;

// Test: Simple scalar subquery
$ast = $parser->parse('SELECT (SELECT MAX(id) FROM users) AS max_id FROM dual');
assert_true($ast->columns[0]->expression instanceof SubqueryNode);
assert_true($ast->columns[0]->expression->query instanceof SelectStatement);
echo "✓ Simple scalar subquery works\n";

// Test: Scalar subquery with UNION
$ast = $parser->parse('SELECT (SELECT 1 UNION SELECT 2) AS val FROM dual');
assert_true($ast->columns[0]->expression instanceof SubqueryNode);
assert_true($ast->columns[0]->expression->query instanceof UnionNode);
echo "✓ Scalar subquery with UNION works\n";

// Test: Scalar subquery with WITH (CTE)
$ast = $parser->parse('SELECT (WITH cte AS (SELECT 1 AS n) SELECT n FROM cte) AS val FROM dual');
assert_true($ast->columns[0]->expression instanceof SubqueryNode);
assert_true($ast->columns[0]->expression->query instanceof WithStatement);
echo "✓ Scalar subquery with WITH (CTE) works\n";

// Test: Scalar subquery in WHERE clause
$ast = $parser->parse('SELECT * FROM t WHERE x = (SELECT MAX(y) FROM s)');
assert_true($ast->where instanceof BinaryOperation);
assert_true($ast->where->right instanceof SubqueryNode);
echo "✓ Scalar subquery in WHERE clause works\n";

echo "\n✓ All SQL parser tests passed!\n";
