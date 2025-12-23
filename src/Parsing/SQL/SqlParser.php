<?php

namespace mini\Parsing\SQL;

use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    ColumnNode,
    FunctionCallNode,
    WindowFunctionNode,
    UnaryOperation,
    BinaryOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    BetweenOperation,
    SubqueryNode,
    LiteralNode,
    IdentifierNode,
    PlaceholderNode,
    JoinNode,
    CaseWhenNode,
    WithStatement
};

/**
 * SQL Parser - Recursive Descent Implementation
 *
 * Features:
 * - Generates an AST (Abstract Syntax Tree)
 * - Supports SELECT, INSERT, UPDATE, DELETE
 * - Supports WHERE, ORDER BY, LIMIT
 * - Supports IN (LIST) and IN (SELECT ...) recursively
 * - Supports Function Calls (e.g. COUNT(*), MAX(col))
 * - Supports dotted identifiers (table.column)
 * - Supports Placeholders (? and :name)
 * - Supports Negative Numbers (-5)
 * - Rich Error Reporting (Line numbers + Visual pointers)
 */
class SqlParser
{
    private string $sql;
    private array $tokens;
    private int $pos = 0;

    /**
     * Parse SQL string into AST
     *
     * @param string $sql SQL query to parse
     * @return SelectStatement|InsertStatement|UpdateStatement|DeleteStatement
     * @throws SqlSyntaxException
     */
    public function parse(string $sql): ASTNode
    {
        $this->sql = $sql;
        $lexer = new SqlLexer($sql);
        $this->tokens = $lexer->tokenize();
        $this->pos = 0;

        // Handle WITH clause (CTEs)
        if ($this->current()['type'] === SqlLexer::T_WITH) {
            return $this->parseWithStatement();
        }

        $token = $this->current();
        $stmt = match($token['type']) {
            SqlLexer::T_SELECT => $this->parseSelectOrUnion(),
            SqlLexer::T_INSERT => $this->parseInsertStatement(),
            SqlLexer::T_UPDATE => $this->parseUpdateStatement(),
            SqlLexer::T_DELETE => $this->parseDeleteStatement(),
            default => throw new SqlSyntaxException(
                "Unexpected start of query: " . $token['type'],
                $this->sql,
                $token['pos']
            )
        };

        if ($this->current()['type'] !== SqlLexer::T_EOF) {
            throw new SqlSyntaxException(
                "Unexpected trailing input",
                $this->sql,
                $this->current()['pos']
            );
        }

        return $stmt;
    }

    private function current(): array
    {
        return $this->tokens[$this->pos];
    }

    private function peek(): array
    {
        if (isset($this->tokens[$this->pos + 1])) {
            return $this->tokens[$this->pos + 1];
        }
        return ['type' => SqlLexer::T_EOF, 'pos' => strlen($this->sql)];
    }

    private function match(string $type): bool
    {
        if ($this->current()['type'] === $type) {
            $this->pos++;
            return true;
        }
        return false;
    }

    private function expect(string $type): array
    {
        if ($this->match($type)) {
            return $this->tokens[$this->pos - 1];
        }

        $curr = $this->current();
        throw new SqlSyntaxException(
            "Expected token $type but found " . $curr['type'] . " (" . $curr['value'] . ")",
            $this->sql,
            $curr['pos']
        );
    }

    private function expectOp(string $opValue): array
    {
        $token = $this->current();
        if ($token['type'] === SqlLexer::T_OP && $token['value'] === $opValue) {
            $this->pos++;
            return $token;
        }

        throw new SqlSyntaxException(
            "Expected operator '$opValue' but found " . $token['value'],
            $this->sql,
            $token['pos']
        );
    }

    // --- Statement Parsers ---

    private function parseSelectStatement(): SelectStatement
    {
        $this->expect(SqlLexer::T_SELECT);
        $stmt = new SelectStatement();

        // Handle DISTINCT
        if ($this->match(SqlLexer::T_DISTINCT)) {
            $stmt->distinct = true;
        }

        $stmt->columns = $this->parseColumnList();

        // FROM is optional (for SELECT 1, SELECT expression, etc.)
        if ($this->match(SqlLexer::T_FROM)) {
            // Check for derived table: (SELECT ...)
            if ($this->current()['type'] === SqlLexer::T_LPAREN) {
                $stmt->from = $this->parseDerivedTable();
                // Derived tables require an alias
                if ($this->match(SqlLexer::T_AS)) {
                    $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                    $stmt->fromAlias = $aliasToken['value'];
                } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                    $stmt->fromAlias = $this->current()['value'];
                    $this->pos++;
                } else {
                    throw new \RuntimeException("Derived table requires an alias");
                }
            } else {
                $stmt->from = $this->parseIdentifier();

                // Optional table alias
                if ($this->match(SqlLexer::T_AS)) {
                    $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                    $stmt->fromAlias = $aliasToken['value'];
                } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                    // Implicit alias (without AS)
                    $stmt->fromAlias = $this->current()['value'];
                    $this->pos++;
                }
            }

            // Parse JOINs
            while ($this->isJoinStart()) {
                $stmt->joins[] = $this->parseJoin();
            }
        } else {
            $stmt->from = null;
        }

        if ($this->match(SqlLexer::T_WHERE)) {
            $stmt->where = $this->parseExpression();
        }

        // GROUP BY
        if ($this->match(SqlLexer::T_GROUP)) {
            $this->expect(SqlLexer::T_BY);
            $stmt->groupBy = [];
            do {
                $stmt->groupBy[] = $this->parseExpression();
            } while ($this->match(SqlLexer::T_COMMA));
        }

        // HAVING (only valid after GROUP BY)
        if ($this->match(SqlLexer::T_HAVING)) {
            $stmt->having = $this->parseExpression();
        }

        if ($this->match(SqlLexer::T_ORDER)) {
            $this->expect(SqlLexer::T_BY);
            $stmt->orderBy = [];
            do {
                $expr = $this->parseExpression();
                $direction = 'ASC';
                if ($this->match(SqlLexer::T_DESC)) {
                    $direction = 'DESC';
                } elseif ($this->match(SqlLexer::T_ASC)) {
                    $direction = 'ASC';
                }

                $stmt->orderBy[] = ['column' => $expr, 'direction' => $direction];
            } while ($this->match(SqlLexer::T_COMMA));
        }

        if ($this->match(SqlLexer::T_LIMIT)) {
            $token = $this->current();
            if ($token['type'] === SqlLexer::T_NUMBER) {
                $this->pos++;
                $stmt->limit = new LiteralNode($token['value'], 'number');
            } elseif ($token['type'] === SqlLexer::T_PLACEHOLDER) {
                $this->pos++;
                $stmt->limit = new PlaceholderNode($token['value']);
            } else {
                throw new SqlSyntaxException(
                    "LIMIT requires a number or placeholder",
                    $this->sql,
                    $token['pos']
                );
            }
        }

        if ($this->match(SqlLexer::T_OFFSET)) {
            $token = $this->current();
            if ($token['type'] === SqlLexer::T_NUMBER) {
                $this->pos++;
                $stmt->offset = new LiteralNode($token['value'], 'number');
            } elseif ($token['type'] === SqlLexer::T_PLACEHOLDER) {
                $this->pos++;
                $stmt->offset = new PlaceholderNode($token['value']);
            } else {
                throw new SqlSyntaxException(
                    "OFFSET requires a number or placeholder",
                    $this->sql,
                    $token['pos']
                );
            }
        }

        return $stmt;
    }

    /**
     * Parse a query that may start with WITH or SELECT, and may include UNION/INTERSECT/EXCEPT
     * Used for subqueries in derived tables, IN clauses, EXISTS, etc.
     */
    private function parseSelectOrUnion(): ASTNode
    {
        // Handle WITH clause (CTE) in subquery context
        if ($this->current()['type'] === SqlLexer::T_WITH) {
            return $this->parseWithBody();
        }

        $stmt = $this->parseSelectStatement();

        while (true) {
            $operator = null;
            if ($this->match(SqlLexer::T_UNION)) {
                $operator = 'UNION';
            } elseif ($this->match(SqlLexer::T_INTERSECT)) {
                $operator = 'INTERSECT';
            } elseif ($this->match(SqlLexer::T_EXCEPT)) {
                $operator = 'EXCEPT';
            } else {
                break;
            }
            $all = $this->match(SqlLexer::T_ALL);
            $right = $this->parseSelectStatement();
            $stmt = new AST\UnionNode($stmt, $right, $all, $operator);
        }

        return $stmt;
    }

    /**
     * Parse WITH statement body (CTEs + main query) without EOF validation
     * Used for WITH inside subqueries where EOF check is inappropriate
     */
    private function parseWithBody(): WithStatement
    {
        $this->expect(SqlLexer::T_WITH);

        $recursive = $this->match(SqlLexer::T_RECURSIVE);

        $ctes = [];
        do {
            $cte = $this->parseCteDefinition();
            $ctes[] = $cte;
        } while ($this->match(SqlLexer::T_COMMA));

        // Parse the main query
        if ($this->current()['type'] !== SqlLexer::T_SELECT) {
            throw new SqlSyntaxException(
                "Expected SELECT after WITH clause",
                $this->sql,
                $this->current()['pos']
            );
        }

        $mainQuery = $this->parseSelectOrUnion();

        return new WithStatement($ctes, $recursive, $mainQuery);
    }

    private function parseInsertStatement(): InsertStatement
    {
        $this->expect(SqlLexer::T_INSERT);
        $this->expect(SqlLexer::T_INTO);

        $stmt = new InsertStatement();
        $stmt->table = $this->parseIdentifier();

        if ($this->match(SqlLexer::T_LPAREN)) {
            $stmt->columns = [];
            do {
                $stmt->columns[] = $this->parseIdentifier();
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
        }

        $this->expect(SqlLexer::T_VALUES);

        do {
            $this->expect(SqlLexer::T_LPAREN);
            $values = [];
            do {
                $values[] = $this->parseExpression();
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);

            $stmt->values[] = $values;
        } while ($this->match(SqlLexer::T_COMMA));

        return $stmt;
    }

    private function parseUpdateStatement(): UpdateStatement
    {
        $this->expect(SqlLexer::T_UPDATE);
        $stmt = new UpdateStatement();

        $stmt->table = $this->parseIdentifier();

        $this->expect(SqlLexer::T_SET);
        do {
            $col = $this->parseIdentifier();
            $this->expectOp('=');
            $val = $this->parseExpression();
            $stmt->updates[] = ['column' => $col, 'value' => $val];
        } while ($this->match(SqlLexer::T_COMMA));

        if ($this->match(SqlLexer::T_WHERE)) {
            $stmt->where = $this->parseExpression();
        }

        return $stmt;
    }

    private function parseDeleteStatement(): DeleteStatement
    {
        $this->expect(SqlLexer::T_DELETE);
        $this->expect(SqlLexer::T_FROM);

        $stmt = new DeleteStatement();
        $stmt->table = $this->parseIdentifier();

        if ($this->match(SqlLexer::T_WHERE)) {
            $stmt->where = $this->parseExpression();
        }

        return $stmt;
    }

    /**
     * Parse WITH statement at top level (with EOF validation)
     */
    private function parseWithStatement(): WithStatement
    {
        $stmt = $this->parseWithBody();

        if ($this->current()['type'] !== SqlLexer::T_EOF) {
            throw new SqlSyntaxException(
                "Unexpected trailing input",
                $this->sql,
                $this->current()['pos']
            );
        }

        return $stmt;
    }

    /**
     * Parse a single CTE definition: name [(columns)] AS (query)
     */
    private function parseCteDefinition(): array
    {
        $nameToken = $this->expect(SqlLexer::T_IDENTIFIER);
        $name = $nameToken['value'];

        // Optional column list: cte_name(col1, col2)
        $columns = null;
        if ($this->match(SqlLexer::T_LPAREN)) {
            $columns = [];
            do {
                $colToken = $this->expect(SqlLexer::T_IDENTIFIER);
                $columns[] = $colToken['value'];
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
        }

        $this->expect(SqlLexer::T_AS);
        $this->expect(SqlLexer::T_LPAREN);

        // Parse the CTE query (supports UNION/INTERSECT/EXCEPT)
        $query = $this->parseSelectOrUnion();

        $this->expect(SqlLexer::T_RPAREN);

        return [
            'name' => $name,
            'columns' => $columns,
            'query' => $query,
        ];
    }

    private function parseColumnList(): array
    {
        $columns = [];
        do {
            if ($this->match(SqlLexer::T_STAR)) {
                $columns[] = new ColumnNode(new IdentifierNode('*'));
            } else {
                $expr = $this->parseExpression();
                $alias = null;

                if ($this->match(SqlLexer::T_AS)) {
                    $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                    $alias = $aliasToken['value'];
                } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                    // Implicit alias
                    $this->pos++;
                    $alias = $this->tokens[$this->pos - 1]['value'];
                }

                $columns[] = new ColumnNode($expr, $alias);
            }
        } while ($this->match(SqlLexer::T_COMMA));

        return $columns;
    }

    // --- Expression Parsing (Precedence Handling) ---

    private function parseExpression(): ASTNode
    {
        $left = $this->parseAnd();
        while ($this->match(SqlLexer::T_OR)) {
            $right = $this->parseAnd();
            $left = new BinaryOperation($left, 'OR', $right);
        }
        return $left;
    }

    private function parseAnd(): ASTNode
    {
        $left = $this->parseComparison();
        while ($this->match(SqlLexer::T_AND)) {
            $right = $this->parseComparison();
            $left = new BinaryOperation($left, 'AND', $right);
        }
        return $left;
    }

    private const COMPARISON_OPS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    private function parseComparison(): ASTNode
    {
        // Handle NOT EXISTS
        if ($this->match(SqlLexer::T_NOT)) {
            if ($this->match(SqlLexer::T_EXISTS)) {
                return $this->parseExistsOperation(negated: true);
            }
            // Handle generic NOT (e.g., NOT is_active, NOT (a = b))
            $expr = $this->parseComparison();
            return new UnaryOperation('NOT', $expr);
        }

        // Handle EXISTS (SELECT ...)
        if ($this->match(SqlLexer::T_EXISTS)) {
            return $this->parseExistsOperation(negated: false);
        }

        $left = $this->parseAdditive();

        // Handle comparison operators only (not arithmetic)
        if ($this->current()['type'] === SqlLexer::T_OP &&
            in_array($this->current()['value'], self::COMPARISON_OPS, true)) {
            $op = $this->current()['value'];
            $this->pos++;

            // Check for ALL/ANY/SOME quantifier
            $quantifier = null;
            if ($this->match(SqlLexer::T_ALL)) {
                $quantifier = 'ALL';
            } elseif ($this->match(SqlLexer::T_ANY) || $this->match(SqlLexer::T_SOME)) {
                $quantifier = 'ANY';  // SOME is synonym for ANY
            }

            if ($quantifier !== null) {
                // Must be followed by subquery (supports UNION/INTERSECT/EXCEPT)
                $this->expect(SqlLexer::T_LPAREN);
                $subquery = $this->parseSelectOrUnion();
                $this->expect(SqlLexer::T_RPAREN);
                return new AST\QuantifiedComparisonNode($left, $op, $quantifier, new SubqueryNode($subquery));
            }

            $right = $this->parseAdditive();
            return new BinaryOperation($left, $op, $right);
        }

        // Handle IS NULL / IS NOT NULL
        if ($this->match(SqlLexer::T_IS)) {
            $negated = $this->match(SqlLexer::T_NOT);
            $this->expect(SqlLexer::T_NULL);
            return new IsNullOperation($left, $negated);
        }

        // Handle NOT IN / NOT LIKE / NOT BETWEEN
        if ($this->match(SqlLexer::T_NOT)) {
            if ($this->match(SqlLexer::T_IN)) {
                return $this->parseInOperation($left, negated: true);
            }
            if ($this->match(SqlLexer::T_LIKE)) {
                $pattern = $this->parseAdditive();
                return new LikeOperation($left, $pattern, negated: true);
            }
            if ($this->match(SqlLexer::T_BETWEEN)) {
                return $this->parseBetweenOperation($left, negated: true);
            }
            throw new SqlSyntaxException(
                "Expected IN, LIKE, or BETWEEN after NOT",
                $this->sql,
                $this->current()['pos']
            );
        }

        // Handle IN clause
        if ($this->match(SqlLexer::T_IN)) {
            return $this->parseInOperation($left, negated: false);
        }

        // Handle LIKE clause
        if ($this->match(SqlLexer::T_LIKE)) {
            $pattern = $this->parseAdditive();
            return new LikeOperation($left, $pattern, negated: false);
        }

        // Handle BETWEEN clause
        if ($this->match(SqlLexer::T_BETWEEN)) {
            return $this->parseBetweenOperation($left, negated: false);
        }

        return $left;
    }

    private function parseBetweenOperation(ASTNode $left, bool $negated): BetweenOperation
    {
        $low = $this->parseAdditive();
        $this->expect(SqlLexer::T_AND);
        $high = $this->parseAdditive();
        return new BetweenOperation($left, $low, $high, $negated);
    }

    private function parseInOperation(ASTNode $left, bool $negated): InOperation
    {
        $this->expect(SqlLexer::T_LPAREN);

        // Check for Subquery (supports UNION/INTERSECT/EXCEPT)
        if ($this->current()['type'] === SqlLexer::T_SELECT) {
            $subquery = $this->parseSelectOrUnion();
            $this->expect(SqlLexer::T_RPAREN);
            return new InOperation($left, new SubqueryNode($subquery), $negated);
        } else {
            // Simple List - only scalar/additive expressions, not comparisons or boolean
            $values = [];
            do {
                $values[] = $this->parseAdditive();
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
            return new InOperation($left, $values, $negated);
        }
    }

    private function parseExistsOperation(bool $negated): AST\ExistsOperation
    {
        $this->expect(SqlLexer::T_LPAREN);
        $subquery = $this->parseSelectOrUnion();
        $this->expect(SqlLexer::T_RPAREN);
        return new AST\ExistsOperation(new SubqueryNode($subquery), $negated);
    }

    /**
     * Parse a derived table: (SELECT ...) in FROM position
     * Supports UNION/INTERSECT/EXCEPT inside the subquery
     */
    private function parseDerivedTable(): SubqueryNode
    {
        $this->expect(SqlLexer::T_LPAREN);
        $subquery = $this->parseSelectOrUnion();
        $this->expect(SqlLexer::T_RPAREN);
        return new SubqueryNode($subquery);
    }

    private function parseAdditive(): ASTNode
    {
        $left = $this->parseMultiplicative();

        while ($this->current()['type'] === SqlLexer::T_OP &&
               in_array($this->current()['value'], ['+', '-'], true)) {
            $op = $this->current()['value'];
            $this->pos++;
            $right = $this->parseMultiplicative();
            $left = new BinaryOperation($left, $op, $right);
        }

        return $left;
    }

    private function parseMultiplicative(): ASTNode
    {
        $left = $this->parseConcatenation();

        while (true) {
            $token = $this->current();
            if ($token['type'] === SqlLexer::T_STAR) {
                $this->pos++;
                $right = $this->parseConcatenation();
                $left = new BinaryOperation($left, '*', $right);
            } elseif ($token['type'] === SqlLexer::T_OP && $token['value'] === '/') {
                $this->pos++;
                $right = $this->parseConcatenation();
                $left = new BinaryOperation($left, '/', $right);
            } elseif ($token['type'] === SqlLexer::T_OP && $token['value'] === '%') {
                $this->pos++;
                $right = $this->parseConcatenation();
                $left = new BinaryOperation($left, '%', $right);
            } else {
                break;
            }
        }

        return $left;
    }

    private function parseConcatenation(): ASTNode
    {
        $left = $this->parseAtom();

        while ($this->current()['type'] === SqlLexer::T_OP &&
               $this->current()['value'] === '||') {
            $this->pos++;
            $right = $this->parseAtom();
            $left = new BinaryOperation($left, '||', $right);
        }

        return $left;
    }

    private function parseAtom(): ASTNode
    {
        $token = $this->current();

        // Handle Unary Minus (Negative numbers)
        if ($token['type'] === SqlLexer::T_OP && $token['value'] === '-') {
            $this->pos++;
            $expr = $this->parseAtom();
            return new UnaryOperation('-', $expr);
        }

        // Handle Parentheses and Scalar Subqueries
        if ($this->match(SqlLexer::T_LPAREN)) {
            // Check for scalar subquery: (SELECT ...), (SELECT ... UNION ...), (WITH ... SELECT ...)
            $currentType = $this->current()['type'];
            if ($currentType === SqlLexer::T_SELECT || $currentType === SqlLexer::T_WITH) {
                $subquery = $this->parseSelectOrUnion();
                $this->expect(SqlLexer::T_RPAREN);
                return new SubqueryNode($subquery);
            }
            $expr = $this->parseExpression();
            $this->expect(SqlLexer::T_RPAREN);
            return $expr;
        }

        // Handle CASE expressions
        if ($this->match(SqlLexer::T_CASE)) {
            return $this->parseCaseExpression();
        }

        // Handle niladic functions (SQL standard functions without parentheses)
        if ($this->match(SqlLexer::T_CURRENT_DATE)) {
            return new AST\NiladicFunctionNode('CURRENT_DATE');
        }
        if ($this->match(SqlLexer::T_CURRENT_TIME)) {
            return new AST\NiladicFunctionNode('CURRENT_TIME');
        }
        if ($this->match(SqlLexer::T_CURRENT_TIMESTAMP)) {
            return new AST\NiladicFunctionNode('CURRENT_TIMESTAMP');
        }

        // Handle Identifiers, Qualified Names (table.column), and Function Calls
        if ($token['type'] === SqlLexer::T_IDENTIFIER) {
            $next = $this->peek();
            if ($next['type'] === SqlLexer::T_LPAREN) {
                return $this->parseFunctionCall();
            }

            // Delegate to parseIdentifier for qualified names (DRY)
            return $this->parseIdentifier();
        }

        // Handle Literals
        if ($this->match(SqlLexer::T_STRING)) {
            return new LiteralNode($token['value'], 'string');
        }

        if ($this->match(SqlLexer::T_NUMBER)) {
            return new LiteralNode($token['value'], 'number');
        }

        if ($this->match(SqlLexer::T_NULL)) {
            return new LiteralNode(null, 'null');
        }

        if ($this->match(SqlLexer::T_TRUE)) {
            return new LiteralNode(true, 'boolean');
        }

        if ($this->match(SqlLexer::T_FALSE)) {
            return new LiteralNode(false, 'boolean');
        }

        // Handle Placeholders
        if ($this->match(SqlLexer::T_PLACEHOLDER)) {
            return new PlaceholderNode($token['value']);
        }

        throw new SqlSyntaxException(
            "Unexpected token in expression: " . $token['type'],
            $this->sql,
            $token['pos']
        );
    }

    private function parseFunctionCall(): FunctionCallNode|WindowFunctionNode
    {
        $nameToken = $this->expect(SqlLexer::T_IDENTIFIER);
        $this->expect(SqlLexer::T_LPAREN);
        $args = [];
        $distinct = false;

        // Handle DISTINCT inside function: COUNT(DISTINCT col)
        if ($this->match(SqlLexer::T_DISTINCT)) {
            $distinct = true;
        }

        if ($this->current()['type'] !== SqlLexer::T_RPAREN) {
            do {
                if ($this->match(SqlLexer::T_STAR)) {
                    $args[] = new IdentifierNode('*');
                } else {
                    $args[] = $this->parseExpression();
                }
            } while ($this->match(SqlLexer::T_COMMA));
        }

        $this->expect(SqlLexer::T_RPAREN);
        $func = new FunctionCallNode($nameToken['value'], $args, $distinct);

        // Check for OVER clause (window function)
        if ($this->match(SqlLexer::T_OVER)) {
            return $this->parseWindowSpec($func);
        }

        return $func;
    }

    /**
     * Parse OVER (PARTITION BY ... ORDER BY ...) clause
     */
    private function parseWindowSpec(FunctionCallNode $func): WindowFunctionNode
    {
        $this->expect(SqlLexer::T_LPAREN);

        $partitionBy = [];
        $orderBy = [];

        // PARTITION BY
        if ($this->match(SqlLexer::T_PARTITION)) {
            $this->expect(SqlLexer::T_BY);
            do {
                $partitionBy[] = $this->parseExpression();
            } while ($this->match(SqlLexer::T_COMMA));
        }

        // ORDER BY
        if ($this->match(SqlLexer::T_ORDER)) {
            $this->expect(SqlLexer::T_BY);
            do {
                $expr = $this->parseExpression();
                $direction = 'ASC';
                if ($this->match(SqlLexer::T_ASC)) {
                    $direction = 'ASC';
                } elseif ($this->match(SqlLexer::T_DESC)) {
                    $direction = 'DESC';
                }
                $orderBy[] = ['expr' => $expr, 'direction' => $direction];
            } while ($this->match(SqlLexer::T_COMMA));
        }

        $this->expect(SqlLexer::T_RPAREN);

        return new WindowFunctionNode($func, $partitionBy, $orderBy);
    }

    /**
     * Parse CASE expression
     *
     * Two forms:
     * - Simple: CASE expr WHEN value THEN result [WHEN ...] [ELSE result] END
     * - Searched: CASE WHEN condition THEN result [WHEN ...] [ELSE result] END
     */
    private function parseCaseExpression(): CaseWhenNode
    {
        // Check for simple vs searched CASE
        $operand = null;
        if ($this->current()['type'] !== SqlLexer::T_WHEN) {
            // Simple CASE: CASE expr WHEN ...
            $operand = $this->parseExpression();
        }

        // Parse WHEN clauses
        $whenClauses = [];
        while ($this->match(SqlLexer::T_WHEN)) {
            $when = $this->parseExpression();
            $this->expect(SqlLexer::T_THEN);
            $then = $this->parseExpression();
            $whenClauses[] = ['when' => $when, 'then' => $then];
        }

        if (empty($whenClauses)) {
            throw new SqlSyntaxException(
                "CASE requires at least one WHEN clause",
                $this->sql,
                $this->current()['pos']
            );
        }

        // Optional ELSE clause
        $elseResult = null;
        if ($this->match(SqlLexer::T_ELSE)) {
            $elseResult = $this->parseExpression();
        }

        // Required END
        $this->expect(SqlLexer::T_END);

        return new CaseWhenNode($operand, $whenClauses, $elseResult);
    }

    private function parseIdentifier(): IdentifierNode
    {
        $token = $this->expect(SqlLexer::T_IDENTIFIER);
        $parts = [$token['value']];

        // Handle qualified identifiers (schema.table.column or table.*)
        while ($this->current()['type'] === SqlLexer::T_DOT) {
            $this->pos++; // consume dot
            $nextToken = $this->current();

            if ($nextToken['type'] === SqlLexer::T_IDENTIFIER) {
                $parts[] = $nextToken['value'];
                $this->pos++;
            } elseif ($nextToken['type'] === SqlLexer::T_STAR) {
                // Handle table.* wildcard
                $parts[] = '*';
                $this->pos++;
                break; // table.* is terminal - no further qualification allowed
            } else {
                throw new SqlSyntaxException(
                    "Expected identifier or * after dot",
                    $this->sql,
                    $nextToken['pos']
                );
            }
        }

        return new IdentifierNode($parts);
    }

    // --- JOIN Parsing ---

    private const JOIN_TYPE_TOKENS = [
        SqlLexer::T_JOIN,
        SqlLexer::T_LEFT,
        SqlLexer::T_RIGHT,
        SqlLexer::T_INNER,
        SqlLexer::T_FULL,
        SqlLexer::T_CROSS,
    ];

    private function isJoinStart(): bool
    {
        return in_array($this->current()['type'], self::JOIN_TYPE_TOKENS, true);
    }

    private function parseJoin(): JoinNode
    {
        $joinType = $this->parseJoinType();

        // Check for derived table in JOIN
        if ($this->current()['type'] === SqlLexer::T_LPAREN) {
            $table = $this->parseDerivedTable();
            // Derived tables require an alias
            $alias = null;
            if ($this->match(SqlLexer::T_AS)) {
                $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                $alias = $aliasToken['value'];
            } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                $alias = $this->current()['value'];
                $this->pos++;
            } else {
                throw new \RuntimeException("Derived table in JOIN requires an alias");
            }
        } else {
            $table = $this->parseIdentifier();

            // Optional alias
            $alias = null;
            if ($this->match(SqlLexer::T_AS)) {
                $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                $alias = $aliasToken['value'];
            } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                // Implicit alias - but be careful not to consume ON
                $alias = $this->current()['value'];
                $this->pos++;
            }
        }

        // ON condition (required for all except CROSS JOIN)
        $condition = null;
        if ($this->match(SqlLexer::T_ON)) {
            $condition = $this->parseExpression();
        } elseif ($joinType !== 'CROSS') {
            throw new SqlSyntaxException(
                "Expected ON after JOIN",
                $this->sql,
                $this->current()['pos']
            );
        }

        return new JoinNode($joinType, $table, $condition, $alias);
    }

    private function parseJoinType(): string
    {
        // Simple: JOIN or INNER JOIN
        if ($this->match(SqlLexer::T_JOIN)) {
            return 'INNER';
        }

        if ($this->match(SqlLexer::T_INNER)) {
            $this->expect(SqlLexer::T_JOIN);
            return 'INNER';
        }

        // LEFT [OUTER] JOIN
        if ($this->match(SqlLexer::T_LEFT)) {
            $this->match(SqlLexer::T_OUTER); // optional
            $this->expect(SqlLexer::T_JOIN);
            return 'LEFT';
        }

        // RIGHT [OUTER] JOIN
        if ($this->match(SqlLexer::T_RIGHT)) {
            $this->match(SqlLexer::T_OUTER); // optional
            $this->expect(SqlLexer::T_JOIN);
            return 'RIGHT';
        }

        // FULL [OUTER] JOIN
        if ($this->match(SqlLexer::T_FULL)) {
            $this->match(SqlLexer::T_OUTER); // optional
            $this->expect(SqlLexer::T_JOIN);
            return 'FULL';
        }

        // CROSS JOIN
        if ($this->match(SqlLexer::T_CROSS)) {
            $this->expect(SqlLexer::T_JOIN);
            return 'CROSS';
        }

        throw new SqlSyntaxException(
            "Expected JOIN keyword",
            $this->sql,
            $this->current()['pos']
        );
    }
}
