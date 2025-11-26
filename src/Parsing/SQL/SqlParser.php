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
    UnaryOperation,
    BinaryOperation,
    InOperation,
    LiteralNode,
    IdentifierNode,
    PlaceholderNode
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

        $token = $this->current();
        $stmt = match($token['type']) {
            SqlLexer::T_SELECT => $this->parseSelectStatement(),
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

        $stmt->columns = $this->parseColumnList();

        $this->expect(SqlLexer::T_FROM);
        $stmt->from = $this->parseIdentifier();

        if ($this->match(SqlLexer::T_WHERE)) {
            $stmt->where = $this->parseExpression();
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
            $val = $this->expect(SqlLexer::T_NUMBER);
            $stmt->limit = (int)$val['value'];
        }

        return $stmt;
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

    private function parseColumnList(): array
    {
        $columns = [];
        do {
            if ($this->current()['value'] === '*') {
                $this->pos++;
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

    private function parseComparison(): ASTNode
    {
        $left = $this->parseAdditive();

        // Handle Standard Operators
        if ($this->current()['type'] === SqlLexer::T_OP) {
            $op = $this->current()['value'];
            $this->pos++;
            $right = $this->parseAdditive();
            return new BinaryOperation($left, $op, $right);
        }

        // Handle IN clause
        if ($this->match(SqlLexer::T_IN)) {
            $this->expect(SqlLexer::T_LPAREN);

            // Check for Subquery
            if ($this->current()['type'] === SqlLexer::T_SELECT) {
                $subquery = $this->parseSelectStatement();
                $this->expect(SqlLexer::T_RPAREN);
                return new InOperation($left, true, $subquery);
            } else {
                // Simple List
                $values = [];
                do {
                    $values[] = $this->parseExpression();
                } while ($this->match(SqlLexer::T_COMMA));
                $this->expect(SqlLexer::T_RPAREN);
                return new InOperation($left, false, $values);
            }
        }

        return $left;
    }

    private function parseAdditive(): ASTNode
    {
        return $this->parseAtom();
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

        // Handle Parentheses
        if ($this->match(SqlLexer::T_LPAREN)) {
            $expr = $this->parseExpression();
            $this->expect(SqlLexer::T_RPAREN);
            return $expr;
        }

        // Handle Identifiers and Function Calls
        if ($token['type'] === SqlLexer::T_IDENTIFIER) {
            $next = $this->peek();
            if ($next['type'] === SqlLexer::T_LPAREN) {
                return $this->parseFunctionCall();
            }
            $this->pos++;
            return new IdentifierNode($token['value']);
        }

        // Handle Literals
        if ($this->match(SqlLexer::T_STRING)) {
            return new LiteralNode($token['value'], 'string');
        }

        if ($this->match(SqlLexer::T_NUMBER)) {
            return new LiteralNode($token['value'], 'number');
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

    private function parseFunctionCall(): FunctionCallNode
    {
        $nameToken = $this->expect(SqlLexer::T_IDENTIFIER);
        $this->expect(SqlLexer::T_LPAREN);
        $args = [];

        if ($this->current()['type'] !== SqlLexer::T_RPAREN) {
            do {
                if ($this->current()['value'] === '*') {
                    $this->pos++;
                    $args[] = new IdentifierNode('*');
                } else {
                    $args[] = $this->parseExpression();
                }
            } while ($this->match(SqlLexer::T_COMMA));
        }

        $this->expect(SqlLexer::T_RPAREN);
        return new FunctionCallNode($nameToken['value'], $args);
    }

    private function parseIdentifier(): IdentifierNode
    {
        $token = $this->expect(SqlLexer::T_IDENTIFIER);
        return new IdentifierNode($token['value']);
    }
}
