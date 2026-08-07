<?php

namespace mini\Parsing\SQL;

use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    CreateTableStatement,
    CreateIndexStatement,
    DropTableStatement,
    DropIndexStatement,
    ColumnDefinition,
    TableConstraint,
    IndexColumn,
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
    CastNode,
    ExtractNode,
    DistinctFromOperation,
    RowValueNode,
    UnionNode,
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

    /** @var array<string, ASTNode> Static cache of parsed ASTs keyed by SQL string */
    private static array $astCache = [];

    /** Maximum number of cached ASTs (simple LRU via array_shift when exceeded) */
    private const CACHE_MAX_SIZE = 256;

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
            // SQL:2003 <table value constructor> as a query of its own:
            // `VALUES (1, 'a'), (2, 'b')`
            SqlLexer::T_VALUES => $this->parseSelectOrUnion(),
            SqlLexer::T_INSERT => $this->parseInsertStatement(),
            SqlLexer::T_REPLACE => $this->parseReplaceStatement(),
            SqlLexer::T_UPDATE => $this->parseUpdateStatement(),
            SqlLexer::T_DELETE => $this->parseDeleteStatement(),
            SqlLexer::T_CREATE => $this->parseCreateStatement(),
            SqlLexer::T_DROP => $this->parseDropStatement(),
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

    /**
     * Build one ORDER BY item, consuming an optional `NULLS FIRST` / `NULLS LAST`
     *
     * SQL:2003 leaves the default null ordering implementation-defined; this
     * engine (like SQLite) sorts NULL as smaller than every value, so the
     * default is NULLS FIRST for ASC and NULLS LAST for DESC. The explicit
     * clause overrides that.
     *
     * NULLS/FIRST/LAST are not reserved words - a column may legitimately be
     * called "nulls" or "last" - so they are matched as soft keywords, only in
     * the one position where they can occur.
     *
     * @return array{column: ASTNode, direction: string, nulls?: string}
     */
    private function makeOrderByItem(ASTNode $expr, string $direction): array
    {
        $item = ['column' => $expr, 'direction' => $direction];

        $nulls = $this->parseNullsOption();
        if ($nulls !== null) {
            $item['nulls'] = $nulls;
        }

        return $item;
    }

    /**
     * Consume an optional `NULLS FIRST` / `NULLS LAST` null ordering
     *
     * Shared by the statement-level ORDER BY and the ORDER BY of a window
     * specification, which are built into differently-shaped arrays but take
     * the same clause.
     */
    private function parseNullsOption(): ?string
    {
        if (!$this->matchSoftKeyword('NULLS')) {
            return null;
        }

        if ($this->match(SqlLexer::T_FIRST)) {
            return 'FIRST';
        }
        if ($this->matchSoftKeyword('LAST')) {
            return 'LAST';
        }

        throw new SqlSyntaxException(
            "Expected FIRST or LAST after NULLS",
            $this->sql,
            $this->current()['pos']
        );
    }

    /**
     * Parse a SQL expression fragment into AST
     *
     * Use this for parsing WHERE clause fragments like:
     * - "userId = ?"
     * - "age > 18 AND status = 'active'"
     * - "name LIKE '%john%'"
     *
     * @param string $expression SQL expression to parse
     * @return ASTNode The parsed expression AST
     * @throws SqlSyntaxException
     */
    public function parseExpressionFragment(string $expression): ASTNode
    {
        $this->sql = $expression;
        $lexer = new SqlLexer($expression);
        $this->tokens = $lexer->tokenize();
        $this->pos = 0;

        $ast = $this->parseExpression();

        if ($this->current()['type'] !== SqlLexer::T_EOF) {
            throw new SqlSyntaxException(
                "Unexpected trailing input in expression",
                $this->sql,
                $this->current()['pos']
            );
        }

        return $ast;
    }

    /**
     * Parse an ORDER BY clause fragment into AST
     *
     * Use this for parsing ORDER BY specifications like:
     * - "name"
     * - "name DESC"
     * - "created_at DESC, name ASC"
     *
     * @param string $orderBy ORDER BY specification to parse
     * @return array<int, array{column: ASTNode, direction: string}> Parsed order items
     * @throws SqlSyntaxException
     */
    public function parseOrderByFragment(string $orderBy): array
    {
        $this->sql = $orderBy;
        $lexer = new SqlLexer($orderBy);
        $this->tokens = $lexer->tokenize();
        $this->pos = 0;

        $result = [];
        do {
            $expr = $this->parseExpression();
            $direction = 'ASC';
            if ($this->match(SqlLexer::T_DESC)) {
                $direction = 'DESC';
            } elseif ($this->match(SqlLexer::T_ASC)) {
                $direction = 'ASC';
            }
            $result[] = $this->makeOrderByItem($expr, $direction);
        } while ($this->match(SqlLexer::T_COMMA));

        if ($this->current()['type'] !== SqlLexer::T_EOF) {
            throw new SqlSyntaxException(
                "Unexpected trailing input in ORDER BY",
                $this->sql,
                $this->current()['pos']
            );
        }

        return $result;
    }

    /**
     * Parse a SET clause fragment into column/value pairs
     *
     * Use this for parsing UPDATE SET specifications like:
     * - "name = 'John'"
     * - "login_count = login_count + 1"
     * - "name = ?, age = ?"
     *
     * @param string $setClause SET clause to parse (without the SET keyword)
     * @return array[] Array of ['column' => IdentifierNode, 'value' => ASTNode]
     * @throws SqlSyntaxException
     */
    public function parseSetFragment(string $setClause): array
    {
        $this->sql = $setClause;
        $lexer = new SqlLexer($setClause);
        $this->tokens = $lexer->tokenize();
        $this->pos = 0;

        $updates = [];
        do {
            $col = $this->parseIdentifier();
            $this->expectOp('=');
            $val = $this->parseExpression();
            $updates[] = ['column' => $col, 'value' => $val];
        } while ($this->match(SqlLexer::T_COMMA));

        if ($this->current()['type'] !== SqlLexer::T_EOF) {
            throw new SqlSyntaxException(
                "Unexpected trailing input in SET clause",
                $this->sql,
                $this->current()['pos']
            );
        }

        return $updates;
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

                // Optional derived column list: (SELECT ...) AS v(x, y)
                $columnListPos = $this->current()['pos'];
                $columnList = $this->parseOptionalDerivedColumnList();
                if ($columnList !== null) {
                    $this->applyDerivedColumnList($stmt->from, $columnList, $columnListPos);
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

            // Parse comma-separated tables as implicit CROSS JOINs
            while ($this->match(SqlLexer::T_COMMA)) {
                $table = $this->parseIdentifier();
                $alias = null;
                if ($this->match(SqlLexer::T_AS)) {
                    $aliasToken = $this->expect(SqlLexer::T_IDENTIFIER);
                    $alias = $aliasToken['value'];
                } elseif ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
                    // Implicit alias (keywords have their own token types)
                    $alias = $this->current()['value'];
                    $this->pos++;
                }
                $stmt->joins[] = new AST\JoinNode('CROSS', $table, null, $alias);
            }

            // Parse explicit JOINs
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

                $stmt->orderBy[] = $this->makeOrderByItem($expr, $direction);
            } while ($this->match(SqlLexer::T_COMMA));
        }

        // Parse LIMIT/OFFSET - supports two syntaxes:
        // 1. MySQL/PostgreSQL: LIMIT n [OFFSET m]
        // 2. SQL:2008: OFFSET n {ROW|ROWS} [FETCH {FIRST|NEXT} m {ROW|ROWS} ONLY]
        if ($this->match(SqlLexer::T_LIMIT)) {
            $stmt->limit = $this->parseNumberOrPlaceholder('LIMIT');

            if ($this->match(SqlLexer::T_OFFSET)) {
                $stmt->offset = $this->parseNumberOrPlaceholder('OFFSET');
            }
        } elseif ($this->match(SqlLexer::T_OFFSET)) {
            // Two syntaxes:
            // - Simple: OFFSET n (PostgreSQL/SQLite style)
            // - SQL:2008: OFFSET n {ROW|ROWS} [FETCH {FIRST|NEXT} m {ROW|ROWS} ONLY]
            $stmt->offset = $this->parseNumberOrPlaceholder('OFFSET');

            // ROW/ROWS is optional - if present, we may have FETCH clause
            if ($this->match(SqlLexer::T_ROW) || $this->match(SqlLexer::T_ROWS)) {
                // SQL:2008 style - check for optional FETCH
                if ($this->match(SqlLexer::T_FETCH)) {
                    if (!$this->match(SqlLexer::T_FIRST) && !$this->match(SqlLexer::T_NEXT)) {
                        throw new SqlSyntaxException(
                            "FETCH requires FIRST or NEXT",
                            $this->sql,
                            $this->current()['pos'] ?? strlen($this->sql)
                        );
                    }

                    $stmt->limit = $this->parseNumberOrPlaceholder('FETCH');

                    if (!$this->match(SqlLexer::T_ROW) && !$this->match(SqlLexer::T_ROWS)) {
                        throw new SqlSyntaxException(
                            "FETCH requires ROW or ROWS",
                            $this->sql,
                            $this->current()['pos'] ?? strlen($this->sql)
                        );
                    }

                    $this->expect(SqlLexer::T_ONLY);
                }
            }
        } elseif ($this->match(SqlLexer::T_FETCH)) {
            // SQL:2008 without OFFSET: FETCH {FIRST|NEXT} n {ROW|ROWS} ONLY
            if (!$this->match(SqlLexer::T_FIRST) && !$this->match(SqlLexer::T_NEXT)) {
                throw new SqlSyntaxException(
                    "FETCH requires FIRST or NEXT",
                    $this->sql,
                    $this->current()['pos'] ?? strlen($this->sql)
                );
            }

            $stmt->limit = $this->parseNumberOrPlaceholder('FETCH');

            if (!$this->match(SqlLexer::T_ROW) && !$this->match(SqlLexer::T_ROWS)) {
                throw new SqlSyntaxException(
                    "FETCH requires ROW or ROWS",
                    $this->sql,
                    $this->current()['pos'] ?? strlen($this->sql)
                );
            }

            $this->expect(SqlLexer::T_ONLY);
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

        $stmt = $this->parseQueryTerm();

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
            $right = $this->parseQueryTerm();
            $stmt = new AST\UnionNode($stmt, $right, $all, $operator);
        }

        if ($stmt instanceof AST\UnionNode) {
            $this->liftTrailingSort($stmt);
        }

        return $stmt;
    }

    /**
     * A single `<query term>`: either a SELECT or a VALUES table constructor
     */
    private function parseQueryTerm(): ASTNode
    {
        if ($this->current()['type'] === SqlLexer::T_VALUES) {
            return $this->parseValuesConstructor();
        }

        return $this->parseSelectStatement();
    }

    /**
     * SQL:2003 `<table value constructor>`: `VALUES (1, 'a'), (2, 'b')`
     *
     * Desugared into the equivalent `SELECT ... UNION ALL SELECT ...` chain, so
     * that every existing execution path (derived tables, CTEs, IN subqueries,
     * set operations) handles it without new machinery. UNION *ALL* because a
     * table value constructor keeps duplicate rows.
     *
     * The columns are named `column1`, `column2`, ... — the same defaults
     * SQLite and PostgreSQL use. A derived column list (`AS v(x, y)`) or a CTE
     * column list renames them.
     */
    private function parseValuesConstructor(): ASTNode
    {
        $startPos = $this->current()['pos'];
        $this->expect(SqlLexer::T_VALUES);

        $rows = [];
        do {
            $this->expect(SqlLexer::T_LPAREN);
            $values = [];
            do {
                $values[] = $this->parseExpression();
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
            $rows[] = $values;
        } while ($this->match(SqlLexer::T_COMMA));

        $degree = count($rows[0]);

        $query = null;
        foreach ($rows as $row) {
            if (count($row) !== $degree) {
                throw new SqlSyntaxException(
                    "All rows of a VALUES constructor must have the same number of columns"
                    . " (expected {$degree}, got " . count($row) . ")",
                    $this->sql,
                    $startPos
                );
            }

            $select = new SelectStatement();
            foreach ($row as $i => $expr) {
                $select->columns[] = new ColumnNode($expr, 'column' . ($i + 1));
            }

            $query = $query === null ? $select : new UnionNode($query, $select, true, 'UNION');
        }

        return $query;
    }

    /**
     * Apply a derived column list — `FROM (...) AS v(x, y)` — to a derived table
     *
     * SQL:2003 renames the derived table's columns positionally. Implemented by
     * rewriting the aliases of the underlying select list, which is why the
     * degree has to match exactly and `SELECT *` cannot be renamed: neither can
     * be resolved without a schema the parser does not have.
     *
     * @param string[] $names
     */
    private function applyDerivedColumnList(SubqueryNode $subquery, array $names, int $pos): void
    {
        $this->renameSelectList($subquery->query, $names, $pos);
    }

    /** @param string[] $names */
    private function renameSelectList(ASTNode $query, array $names, int $pos): void
    {
        if ($query instanceof UnionNode) {
            $this->renameSelectList($query->left, $names, $pos);
            $this->renameSelectList($query->right, $names, $pos);
            return;
        }

        if (!$query instanceof SelectStatement) {
            throw new SqlSyntaxException(
                "A derived column list is not supported on this kind of derived table",
                $this->sql,
                $pos
            );
        }

        if (count($query->columns) !== count($names)) {
            throw new SqlSyntaxException(
                "Derived column list has " . count($names) . " name(s) but the derived table has "
                . count($query->columns) . " column(s)",
                $this->sql,
                $pos
            );
        }

        foreach ($query->columns as $i => $column) {
            if ($column->expression instanceof IdentifierNode && $column->expression->isWildcard()) {
                throw new SqlSyntaxException(
                    "A derived column list requires an explicit select list, not *",
                    $this->sql,
                    $pos
                );
            }
            $column->alias = $names[$i];
        }
    }

    /**
     * Optional derived column list after a derived table alias: `AS v(x, y)`
     *
     * @return string[]|null
     */
    private function parseOptionalDerivedColumnList(): ?array
    {
        if (!$this->match(SqlLexer::T_LPAREN)) {
            return null;
        }

        $names = [];
        $seen = [];
        do {
            $token = $this->expect(SqlLexer::T_IDENTIFIER);
            $name = $token['value'];

            // SQL:2003 requires the column names of a derived table to be
            // unique. Accepting a duplicate silently loses a column: the later
            // alias wins at lookup time and the earlier one becomes
            // unreachable, so fail fast instead.
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new SqlSyntaxException(
                    "Derived column list has a duplicate column name \"$name\"",
                    $this->sql,
                    $token['pos']
                );
            }
            $seen[$key] = true;

            $names[] = $name;
        } while ($this->match(SqlLexer::T_COMMA));
        $this->expect(SqlLexer::T_RPAREN);

        return $names;
    }

    /**
     * Move a trailing ORDER BY / LIMIT / OFFSET from the last operand onto the
     * set operation itself.
     *
     * `parseSelectStatement()` greedily consumes the trailing sort/pagination,
     * so in `A UNION B ORDER BY x LIMIT 1` they end up parsed as part of `B`.
     * SQL:2003 says they apply to the whole set result: the operands of a set
     * operator are `<query term>`s and cannot carry their own ORDER BY. Leaving
     * them on `B` sorts and limits only the last branch — a silently wrong
     * answer.
     */
    private function liftTrailingSort(AST\UnionNode $node): void
    {
        $right = $node->right;
        if (!$right instanceof SelectStatement) {
            return;
        }
        if ($right->orderBy === null && $right->limit === null && $right->offset === null) {
            return;
        }

        $node->orderBy = $right->orderBy;
        $node->limit = $right->limit;
        $node->offset = $right->offset;

        $right->orderBy = null;
        $right->limit = null;
        $right->offset = null;
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

        // Check for INSERT OR REPLACE
        $replace = false;
        if ($this->match(SqlLexer::T_OR)) {
            $this->expect(SqlLexer::T_REPLACE);
            $replace = true;
        }

        $this->expect(SqlLexer::T_INTO);

        return $this->parseInsertBody($replace);
    }

    private function parseReplaceStatement(): InsertStatement
    {
        $this->expect(SqlLexer::T_REPLACE);
        $this->expect(SqlLexer::T_INTO);

        return $this->parseInsertBody(true);
    }

    private function parseInsertBody(bool $replace): InsertStatement
    {
        $stmt = new InsertStatement();
        $stmt->replace = $replace;
        $stmt->table = $this->parseIdentifier();

        if ($this->match(SqlLexer::T_LPAREN)) {
            $stmt->columns = [];
            do {
                $stmt->columns[] = $this->parseIdentifier();
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
        }

        // INSERT INTO table SELECT ... syntax
        if ($this->current()['type'] === SqlLexer::T_SELECT) {
            $stmt->select = $this->parseSelectStatement();
            return $stmt;
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

        if ($this->match(SqlLexer::T_LIMIT)) {
            $stmt->limit = $this->parseNumberOrPlaceholder('LIMIT');
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

        if ($this->match(SqlLexer::T_LIMIT)) {
            $stmt->limit = $this->parseNumberOrPlaceholder('LIMIT');
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

        // Handle IS NULL / IS NOT NULL / IS [NOT] DISTINCT FROM
        if ($this->match(SqlLexer::T_IS)) {
            $negated = $this->match(SqlLexer::T_NOT);
            if ($this->match(SqlLexer::T_DISTINCT)) {
                $this->expectKeyword('FROM');
                return new DistinctFromOperation($left, $this->parseAdditive(), $negated);
            }
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
                return new LikeOperation($left, $pattern, negated: true, escape: $this->parseOptionalEscape());
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
            return new LikeOperation($left, $pattern, negated: false, escape: $this->parseOptionalEscape());
        }

        // Handle BETWEEN clause
        if ($this->match(SqlLexer::T_BETWEEN)) {
            return $this->parseBetweenOperation($left, negated: false);
        }

        return $left;
    }

    /**
     * Optional ESCAPE '<char>' after a LIKE pattern
     *
     * ESCAPE is not a reserved word in the lexer - a column may legitimately be
     * called "escape" - so it is recognised as a soft keyword, only in the one
     * position where it can occur.
     */
    private function parseOptionalEscape(): ?ASTNode
    {
        return $this->matchSoftKeyword('ESCAPE') ? $this->parseAtom() : null;
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
        // SQLite shorthand: IN table_name means IN (SELECT * FROM table_name)
        if ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
            $tableName = $this->current()['value'];
            $this->pos++;
            // Build: SELECT * FROM table_name
            $select = new SelectStatement();
            $select->columns = [new ColumnNode(new IdentifierNode('*'))];
            $select->from = new IdentifierNode($tableName);
            return new InOperation($left, new SubqueryNode($select), $negated);
        }

        $this->expect(SqlLexer::T_LPAREN);

        // Check for Subquery (supports UNION/INTERSECT/EXCEPT, a leading WITH and
        // a VALUES table constructor - SQL:2003 allows a full <query expression>
        // here, not just a SELECT)
        if ($this->current()['type'] === SqlLexer::T_SELECT
            || $this->current()['type'] === SqlLexer::T_WITH
            || $this->current()['type'] === SqlLexer::T_VALUES) {
            $subquery = $this->parseSelectOrUnion();
            $this->expect(SqlLexer::T_RPAREN);
            return new InOperation($left, new SubqueryNode($subquery), $negated);
        } else {
            // Simple List - only scalar/additive expressions, not comparisons or boolean
            // Handle empty list: IN () is valid SQL (always false)
            $values = [];
            if ($this->current()['type'] !== SqlLexer::T_RPAREN) {
                do {
                    $values[] = $this->parseAdditive();
                } while ($this->match(SqlLexer::T_COMMA));
            }
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

        // Handle Unary Plus
        if ($token['type'] === SqlLexer::T_OP && $token['value'] === '+') {
            $this->pos++;
            $expr = $this->parseAtom();
            return new UnaryOperation('+', $expr);
        }

        // Handle Parentheses and Scalar Subqueries
        if ($this->match(SqlLexer::T_LPAREN)) {
            // Check for scalar subquery: (SELECT ...), (SELECT ... UNION ...),
            // (WITH ... SELECT ...), (VALUES ...)
            $currentType = $this->current()['type'];
            if ($currentType === SqlLexer::T_SELECT
                || $currentType === SqlLexer::T_WITH
                || $currentType === SqlLexer::T_VALUES) {
                $subquery = $this->parseSelectOrUnion();
                $this->expect(SqlLexer::T_RPAREN);
                return new SubqueryNode($subquery);
            }
            $expr = $this->parseExpression();

            // Row value constructor: (a, b). A single parenthesised expression
            // stays a plain grouped expression, per SQL:2003.
            if ($this->current()['type'] === SqlLexer::T_COMMA) {
                $values = [$expr];
                while ($this->match(SqlLexer::T_COMMA)) {
                    $values[] = $this->parseExpression();
                }
                $this->expect(SqlLexer::T_RPAREN);
                return new RowValueNode($values);
            }

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

        // REPLACE is a statement keyword (REPLACE INTO ...) but also a built-in
        // function; in expression position REPLACE( is always the function call.
        if ($token['type'] === SqlLexer::T_REPLACE && $this->peek()['type'] === SqlLexer::T_LPAREN) {
            $this->tokens[$this->pos]['type'] = SqlLexer::T_IDENTIFIER;
            return $this->parseFunctionCall();
        }

        // Handle Identifiers, Qualified Names (table.column), and Function Calls
        if ($token['type'] === SqlLexer::T_IDENTIFIER) {
            $next = $this->peek();

            // Typed datetime literal: DATE '2020-01-01' and friends. DATE,
            // TIME and TIMESTAMP stay ordinary identifiers (they are common
            // column and type names); only a string literal immediately after
            // one of them can be a typed literal, and nothing else can.
            if ($next['type'] === SqlLexer::T_STRING
                && \in_array(strtoupper($token['value']), ['DATE', 'TIME', 'TIMESTAMP'], true)
            ) {
                return $this->parseDatetimeLiteral();
            }

            if ($next['type'] === SqlLexer::T_LPAREN) {
                // Two "functions" whose arguments are separated by a keyword
                // rather than a comma. They cannot be expressed as an ordinary
                // call, so the grammar carries them.
                $upper = strtoupper($token['value']);
                if ($upper === 'CAST') {
                    return $this->parseCastExpression();
                }
                if ($upper === 'EXTRACT') {
                    return $this->parseExtractExpression();
                }
                if ($upper === 'POSITION') {
                    return $this->parsePositionExpression();
                }
                if ($upper === 'SUBSTRING' || $upper === 'SUBSTR') {
                    return $this->parseSubstringExpression();
                }
                if ($upper === 'TRIM') {
                    return $this->parseTrimExpression();
                }
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

    /**
     * Parse a number or placeholder (for LIMIT/OFFSET/FETCH)
     */
    private function parseNumberOrPlaceholder(string $context): ASTNode
    {
        $token = $this->current();
        if ($token['type'] === SqlLexer::T_NUMBER) {
            $this->pos++;
            return new LiteralNode($token['value'], 'number');
        }
        if ($token['type'] === SqlLexer::T_PLACEHOLDER) {
            $this->pos++;
            return new PlaceholderNode($token['value']);
        }
        throw new SqlSyntaxException(
            "$context requires a number or placeholder",
            $this->sql,
            $token['pos'] ?? strlen($this->sql)
        );
    }

    /**
     * CAST(expr AS type)
     *
     * The type name is kept as written (length and precision included) and
     * resolved to an affinity when the cast is evaluated.
     */
    private function parseCastExpression(): CastNode
    {
        $this->expect(SqlLexer::T_IDENTIFIER); // CAST
        $this->expect(SqlLexer::T_LPAREN);
        $expr = $this->parseExpression();
        $this->expect(SqlLexer::T_AS);
        $type = $this->parseTypeName();
        $this->expect(SqlLexer::T_RPAREN);

        return new CastNode($expr, $type);
    }

    /**
     * A type name in CAST: INTEGER, VARCHAR(255), DECIMAL(10,2), DOUBLE PRECISION
     */
    private function parseTypeName(): string
    {
        $token = $this->current();
        if ($token['type'] !== SqlLexer::T_IDENTIFIER) {
            throw new SqlSyntaxException(
                "Expected a type name after AS in CAST(...)",
                $this->sql,
                $token['pos']
            );
        }

        $name = strtoupper($token['value']);
        $this->pos++;

        // Multi-word type names: DOUBLE PRECISION, UNSIGNED BIG INT
        while ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
            $name .= ' ' . strtoupper($this->current()['value']);
            $this->pos++;
        }

        // Length / precision, which SQLite ignores for CAST but must accept
        if ($this->match(SqlLexer::T_LPAREN)) {
            $args = [];
            do {
                $args[] = $this->expect(SqlLexer::T_NUMBER)['value'];
            } while ($this->match(SqlLexer::T_COMMA));
            $this->expect(SqlLexer::T_RPAREN);
            $name .= '(' . implode(',', $args) . ')';
        }

        return $name;
    }

    /**
     * POSITION(substring IN string) - SQL:2003 syntax for INSTR(string, substring)
     *
     * Lowered onto a plain two-argument POSITION call so the standard function
     * registry can implement it; only the argument separator is grammar.
     */
    private function parsePositionExpression(): FunctionCallNode
    {
        $this->expect(SqlLexer::T_IDENTIFIER); // POSITION
        $this->expect(SqlLexer::T_LPAREN);

        // parseAdditive, not parseExpression: the latter would swallow the IN
        $needle = $this->parseAdditive();

        if (!$this->match(SqlLexer::T_IN)) {
            $curr = $this->current();
            throw new SqlSyntaxException(
                "POSITION requires the form POSITION(substring IN string)",
                $this->sql,
                $curr['pos']
            );
        }

        $haystack = $this->parseAdditive();
        $this->expect(SqlLexer::T_RPAREN);

        return new FunctionCallNode('POSITION', [$needle, $haystack]);
    }

    /**
     * SUBSTRING(x FROM y [FOR z]) - SQL:2003 spelling of SUBSTR(x, y[, z])
     *
     * Both spellings produce the same call, so there is one implementation.
     */
    private function parseSubstringExpression(): FunctionCallNode
    {
        $name = $this->expect(SqlLexer::T_IDENTIFIER)['value'];
        $this->expect(SqlLexer::T_LPAREN);

        $args = [$this->parseExpression()];

        if ($this->match(SqlLexer::T_FROM)) {
            $args[] = $this->parseExpression();
            if ($this->matchSoftKeyword('FOR')) {
                $args[] = $this->parseExpression();
            }
        } else {
            while ($this->match(SqlLexer::T_COMMA)) {
                $args[] = $this->parseExpression();
            }
        }

        $this->expect(SqlLexer::T_RPAREN);

        return new FunctionCallNode($name, $args);
    }

    /**
     * TRIM([LEADING|TRAILING|BOTH] [chars] FROM x) - SQL:2003 spelling
     *
     * Lowered onto the existing TRIM/LTRIM/RTRIM functions: the specification
     * picks the function and the operands are put in (string, chars) order.
     * The comma form TRIM(x, chars) keeps working.
     */
    private function parseTrimExpression(): FunctionCallNode
    {
        $this->expect(SqlLexer::T_IDENTIFIER); // TRIM
        $this->expect(SqlLexer::T_LPAREN);

        $spec = null;
        foreach (['LEADING', 'TRAILING', 'BOTH'] as $candidate) {
            if ($this->matchSoftKeyword($candidate)) {
                $spec = $candidate;
                break;
            }
        }

        $chars = null;

        if ($this->match(SqlLexer::T_FROM)) {
            // TRIM(BOTH FROM x) / TRIM(FROM x)
            $target = $this->parseExpression();
        } else {
            $first = $this->parseExpression();
            if ($this->match(SqlLexer::T_FROM)) {
                // TRIM([spec] chars FROM x)
                $chars = $first;
                $target = $this->parseExpression();
            } elseif ($spec !== null) {
                $curr = $this->current();
                throw new SqlSyntaxException(
                    "Expected FROM in TRIM($spec ... FROM ...)",
                    $this->sql,
                    $curr['pos']
                );
            } else {
                // TRIM(x) / TRIM(x, chars)
                $target = $first;
                if ($this->match(SqlLexer::T_COMMA)) {
                    $chars = $this->parseExpression();
                }
            }
        }

        $this->expect(SqlLexer::T_RPAREN);

        $function = match ($spec) {
            'LEADING' => 'LTRIM',
            'TRAILING' => 'RTRIM',
            default => 'TRIM',
        };

        return new FunctionCallNode($function, $chars === null ? [$target] : [$target, $chars]);
    }

    /**
     * DATE 'x' / TIME 'x' / TIMESTAMP 'x' - SQL:2003 F051-01/02/03
     *
     * Mini has no date *type*: the engine stores datetimes as text in the
     * canonical `Y-m-d H:i:s` shape, and comparison, ordering and grouping all
     * work on those strings because the format sorts lexicographically. So a
     * typed literal is not a conversion - it is an assertion about the format,
     * checked here and then carried as an ordinary text value.
     *
     * The check is deliberately strict: only the standard's own spelling is
     * accepted (zero-padded fields, a space between date and time), and the
     * date has to exist on the calendar. `DATE '2020-2-3'` and
     * `DATE '2020-02-30'` are errors rather than guesses. The rules live in
     * {@see DatetimeText} because the evaluator has to apply the identical
     * ones to text arriving from a column - a parser that rejects
     * `DATE '2020-13-45'` while EXTRACT reports month 13 for the same
     * characters would be worse than either behaviour alone.
     *
     * The value type is kept on the node for introspection, but the literal
     * renders as a plain quoted string: the engine's reference backend
     * (SQLite) has no typed literal syntax, and a quoted string means exactly
     * the same thing everywhere the text form is stored.
     */
    private function parseDatetimeLiteral(): LiteralNode
    {
        $keyword = strtoupper($this->expect(SqlLexer::T_IDENTIFIER)['value']);
        $token = $this->expect(SqlLexer::T_STRING);
        $value = $token['value'];

        try {
            DatetimeText::parse($value, $keyword);
        } catch (\InvalidArgumentException $e) {
            throw new SqlSyntaxException(
                "$keyword literal " . $e->getMessage(),
                $this->sql,
                $token['pos']
            );
        }

        return new LiteralNode($value, strtolower($keyword));
    }

    /**
     * EXTRACT(field FROM source) - SQL:2003 F052
     *
     * The field is a keyword rather than an expression, so this is grammar,
     * not a registrable function. The source is evaluated normally and the
     * field is checked here so that `EXTRACT(WEEK FROM x)` fails at parse
     * time, where the position of the mistake is still known.
     */
    private function parseExtractExpression(): ExtractNode
    {
        $this->expect(SqlLexer::T_IDENTIFIER); // EXTRACT
        $this->expect(SqlLexer::T_LPAREN);

        $fieldToken = $this->current();
        $field = $fieldToken['type'] === SqlLexer::T_IDENTIFIER
            ? strtoupper($fieldToken['value'])
            : '';

        if (!\in_array($field, ExtractNode::FIELDS, true)) {
            throw new SqlSyntaxException(
                'EXTRACT requires one of ' . implode(', ', ExtractNode::FIELDS)
                    . ' as its field, in the form EXTRACT(field FROM source)',
                $this->sql,
                $fieldToken['pos'] ?? strlen($this->sql)
            );
        }
        $this->pos++;

        if (!$this->match(SqlLexer::T_FROM)) {
            $curr = $this->current();
            throw new SqlSyntaxException(
                'EXTRACT requires the form EXTRACT(field FROM source)',
                $this->sql,
                $curr['pos'] ?? strlen($this->sql)
            );
        }

        $source = $this->parseExpression();
        $this->expect(SqlLexer::T_RPAREN);

        return new ExtractNode($field, $source);
    }

    /**
     * Consume an identifier used as a keyword (FOR, LEADING, ...) if present
     */
    private function matchSoftKeyword(string $keyword): bool
    {
        $token = $this->current();
        if ($token['type'] === SqlLexer::T_IDENTIFIER && strtoupper($token['value']) === $keyword) {
            $this->pos++;
            return true;
        }
        return false;
    }

    private function parseFunctionCall(): FunctionCallNode|WindowFunctionNode
    {
        $nameToken = $this->expect(SqlLexer::T_IDENTIFIER);
        $this->expect(SqlLexer::T_LPAREN);
        $args = [];
        $distinct = false;

        // Set quantifier inside an aggregate: COUNT(DISTINCT col) / SUM(ALL col).
        // ALL is the SQL:2003 default, so it only has to be accepted.
        if ($this->match(SqlLexer::T_DISTINCT)) {
            $distinct = true;
        } elseif ($this->current()['type'] === SqlLexer::T_ALL
            && $this->peek()['type'] !== SqlLexer::T_RPAREN
        ) {
            $this->match(SqlLexer::T_ALL);
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
                $item = ['expr' => $expr, 'direction' => $direction];
                $nulls = $this->parseNullsOption();
                if ($nulls !== null) {
                    $item['nulls'] = $nulls;
                }
                $orderBy[] = $item;
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
        SqlLexer::T_NATURAL,
    ];

    private function isJoinStart(): bool
    {
        return in_array($this->current()['type'], self::JOIN_TYPE_TOKENS, true);
    }

    private function parseJoin(): JoinNode
    {
        // NATURAL [INNER|LEFT|RIGHT|FULL [OUTER]] JOIN - the join columns are
        // the columns the two operands have in common, so the join carries no
        // ON/USING of its own.
        $natural = $this->match(SqlLexer::T_NATURAL);
        $joinType = $this->parseJoinType();

        if ($natural && $joinType === 'CROSS') {
            throw new SqlSyntaxException(
                "NATURAL CROSS JOIN is not valid SQL",
                $this->sql,
                $this->current()['pos']
            );
        }

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

            // Optional derived column list: JOIN (VALUES (1)) AS v(x) ON ...
            $columnListPos = $this->current()['pos'];
            $columnList = $this->parseOptionalDerivedColumnList();
            if ($columnList !== null) {
                $this->applyDerivedColumnList($table, $columnList, $columnListPos);
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

        // Join specification: ON <condition> | USING (col, ...).
        // Required for every join type except CROSS and NATURAL, which have
        // an implicit one.
        $condition = null;
        $using = null;

        if ($natural) {
            if ($this->current()['type'] === SqlLexer::T_ON
                || $this->current()['type'] === SqlLexer::T_USING
            ) {
                throw new SqlSyntaxException(
                    "NATURAL JOIN cannot have an ON or USING clause",
                    $this->sql,
                    $this->current()['pos']
                );
            }
        } elseif ($this->current()['type'] === SqlLexer::T_ON
            || $this->current()['type'] === SqlLexer::T_USING
        ) {
            // A CROSS JOIN is a cartesian product by definition and takes no
            // join specification (SQL:2003 7.7 <cross join>). Accepting one
            // and then ignoring it - which is what applyJoin() did, since it
            // builds a CrossJoinTable before ever looking at the condition -
            // answers a cartesian product to a query that asked for a join.
            if ($joinType === 'CROSS') {
                $keyword = $this->current()['type'] === SqlLexer::T_ON ? 'ON' : 'USING';
                throw new SqlSyntaxException(
                    "CROSS JOIN cannot have a $keyword clause; write "
                    . ($keyword === 'ON' ? 'JOIN ... ON' : 'JOIN ... USING') . ' instead',
                    $this->sql,
                    $this->current()['pos']
                );
            }
            if ($this->match(SqlLexer::T_ON)) {
                $condition = $this->parseExpression();
            } else {
                $this->expect(SqlLexer::T_USING);
                $using = $this->parseUsingColumns();
            }
        } elseif ($joinType !== 'CROSS') {
            throw new SqlSyntaxException(
                "Expected ON or USING after JOIN",
                $this->sql,
                $this->current()['pos']
            );
        }

        return new JoinNode($joinType, $table, $condition, $alias, $using, $natural);
    }

    /**
     * Parse the parenthesised column list of a USING clause
     *
     * SQL:2003 restricts these to unqualified column names - they name a column
     * of both operands at once, so a qualifier would be meaningless.
     *
     * @return list<string>
     */
    private function parseUsingColumns(): array
    {
        $this->expect(SqlLexer::T_LPAREN);

        $columns = [];
        do {
            $token = $this->expect(SqlLexer::T_IDENTIFIER);
            if ($this->current()['type'] === SqlLexer::T_DOT) {
                throw new SqlSyntaxException(
                    "USING column names must be unqualified",
                    $this->sql,
                    $this->current()['pos']
                );
            }
            $columns[] = $token['value'];
        } while ($this->match(SqlLexer::T_COMMA));

        $this->expect(SqlLexer::T_RPAREN);

        return $columns;
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

    /**
     * Parse SQL and bind parameters, returning the AST
     *
     * Convenience method that parses SQL and binds parameter values to
     * PlaceholderNodes in a single call.
     *
     * @param string $sql SQL to parse
     * @param array $params Parameters to bind (positional or named)
     * @return ASTNode Parsed AST with bound PlaceholderNodes
     */
    public static function parseWithParams(string $sql, array $params = []): ASTNode
    {
        // Get AST from cache (may be shared)
        $wasCached = false;
        $ast = self::parseCached($sql, $wasCached);

        // If we have params to bind, we'll mutate the AST - must clone if shared
        if (!empty($params)) {
            if ($wasCached) {
                $ast = $ast->deepClone();
            }
            $paramsCopy = $params;
            self::bindParams($ast, $paramsCopy);
        }

        return $ast;
    }

    /**
     * Parse SQL with caching - returns AST from cache (possibly shared)
     *
     * Returns the cached AST directly. Callers should treat it as shared
     * (read-only) and deep-clone before mutation. This avoids cloning
     * overhead for read-only query execution paths.
     *
     * For queries that will be mutated, use the instance parse() method
     * which always returns a fresh AST.
     *
     * @param string $sql SQL query to parse
     * @param bool &$wasCached Output: true if AST was from cache
     * @return ASTNode Parsed AST (may be shared - treat as read-only)
     * @throws SqlSyntaxException
     */
    public static function parseCached(string $sql, bool &$wasCached = false): ASTNode
    {
        // Check cache first
        if (isset(self::$astCache[$sql])) {
            $wasCached = true;
            return self::$astCache[$sql];
        }

        $wasCached = false;

        // Parse fresh
        $parser = new self();
        $ast = $parser->parse($sql);

        // Evict oldest entry if cache is full
        if (count(self::$astCache) >= self::CACHE_MAX_SIZE) {
            array_shift(self::$astCache);
        }

        // Cache and return (caller should treat as shared)
        self::$astCache[$sql] = $ast;
        return $ast;
    }

    /**
     * Clear the AST cache (for testing or memory management)
     */
    public static function clearCache(): void
    {
        self::$astCache = [];
    }

    // =========================================================================
    // DDL Parsing (CREATE, DROP, etc.)
    // =========================================================================

    /**
     * Parse CREATE statement (TABLE, INDEX, VIEW)
     */
    private function parseCreateStatement(): ASTNode
    {
        $this->expect(SqlLexer::T_CREATE);
        $token = $this->current();

        // CREATE UNIQUE INDEX
        if ($token['type'] === SqlLexer::T_UNIQUE) {
            $this->pos++;
            $this->expect(SqlLexer::T_INDEX);
            return $this->parseCreateIndex(unique: true);
        }

        if ($token['type'] === SqlLexer::T_TABLE) {
            $this->pos++; // consume TABLE token
            return $this->parseCreateTable(temporary: false);
        }

        // CREATE TEMPORARY TABLE or CREATE TEMP TABLE
        if ($token['type'] === SqlLexer::T_TEMPORARY || $token['type'] === SqlLexer::T_TEMP) {
            $this->pos++;
            $this->expect(SqlLexer::T_TABLE);
            return $this->parseCreateTable(temporary: true);
        }

        if ($token['type'] === SqlLexer::T_INDEX) {
            $this->pos++; // consume INDEX
            return $this->parseCreateIndex(unique: false);
        }

        throw new SqlSyntaxException(
            "Expected TABLE or INDEX after CREATE",
            $this->sql,
            $token['pos']
        );
    }

    /**
     * Parse CREATE TABLE statement
     *
     * Syntax: CREATE [TEMPORARY|TEMP] TABLE [IF NOT EXISTS] name (column_def, ..., [constraint, ...])
     */
    private function parseCreateTable(bool $temporary = false): CreateTableStatement
    {
        $stmt = new CreateTableStatement();
        $stmt->temporary = $temporary;

        // IF NOT EXISTS
        if ($this->match(SqlLexer::T_IF)) {
            $this->expect(SqlLexer::T_NOT);
            $this->expectKeyword('EXISTS');
            $stmt->ifNotExists = true;
        }

        // Table name
        $stmt->table = $this->parseIdentifier();

        // Column definitions and constraints
        $this->expect(SqlLexer::T_LPAREN);

        do {
            // Check for table-level constraint
            $token = $this->current();
            if (in_array($token['type'], [SqlLexer::T_PRIMARY, SqlLexer::T_UNIQUE, SqlLexer::T_FOREIGN, SqlLexer::T_CHECK, SqlLexer::T_CONSTRAINT])) {
                $stmt->constraints[] = $this->parseTableConstraint();
            } else {
                $stmt->columns[] = $this->parseColumnDefinition();
            }
        } while ($this->match(SqlLexer::T_COMMA));

        $this->expect(SqlLexer::T_RPAREN);

        return $stmt;
    }

    /**
     * Parse column definition
     */
    private function parseColumnDefinition(): ColumnDefinition
    {
        $col = new ColumnDefinition();

        // Column name
        $col->name = $this->expectIdentifierName();

        // Data type (optional in SQLite)
        if ($this->current()['type'] === SqlLexer::T_IDENTIFIER) {
            $col->dataType = strtoupper($this->current()['value']);
            $this->pos++;

            // Type parameters: VARCHAR(255), DECIMAL(10,2)
            if ($this->match(SqlLexer::T_LPAREN)) {
                $col->length = (int) $this->current()['value'];
                $this->expect(SqlLexer::T_NUMBER);

                if ($this->match(SqlLexer::T_COMMA)) {
                    $col->scale = (int) $this->current()['value'];
                    $this->expect(SqlLexer::T_NUMBER);
                    $col->precision = $col->length;
                    $col->length = null;
                }

                $this->expect(SqlLexer::T_RPAREN);
            }
        }

        // Column constraints
        while ($this->parseColumnConstraint($col)) {
            // Keep parsing constraints
        }

        return $col;
    }

    /**
     * Parse a single column constraint
     * @return bool True if a constraint was parsed
     */
    private function parseColumnConstraint(ColumnDefinition $col): bool
    {
        $token = $this->current();

        // PRIMARY KEY
        if ($token['type'] === SqlLexer::T_PRIMARY) {
            $this->pos++;
            $this->expect(SqlLexer::T_KEY);
            $col->primaryKey = true;

            // AUTOINCREMENT
            if ($this->match(SqlLexer::T_AUTOINCREMENT)) {
                $col->autoIncrement = true;
            }
            return true;
        }

        // NOT NULL
        if ($token['type'] === SqlLexer::T_NOT) {
            $this->pos++;
            $this->expect(SqlLexer::T_NULL);
            $col->notNull = true;
            return true;
        }

        // NULL (explicit nullable)
        if ($token['type'] === SqlLexer::T_NULL) {
            $this->pos++;
            return true;
        }

        // UNIQUE
        if ($token['type'] === SqlLexer::T_UNIQUE) {
            $this->pos++;
            $col->unique = true;
            return true;
        }

        // DEFAULT value
        if ($token['type'] === SqlLexer::T_DEFAULT) {
            $this->pos++;
            $col->default = $this->parseDefaultValue();
            return true;
        }

        // REFERENCES table(column)
        if ($token['type'] === SqlLexer::T_REFERENCES) {
            $this->pos++;
            $col->references = $this->expectIdentifierName();
            if ($this->match(SqlLexer::T_LPAREN)) {
                $col->referencesColumn = $this->expectIdentifierName();
                $this->expect(SqlLexer::T_RPAREN);
            }
            return true;
        }

        // CHECK (expression)
        if ($token['type'] === SqlLexer::T_CHECK) {
            $this->pos++;
            $this->expect(SqlLexer::T_LPAREN);
            // Skip the check expression for now (complex parsing)
            $depth = 1;
            while ($depth > 0 && $this->current()['type'] !== SqlLexer::T_EOF) {
                if ($this->match(SqlLexer::T_LPAREN)) $depth++;
                elseif ($this->match(SqlLexer::T_RPAREN)) $depth--;
                else $this->pos++;
            }
            return true;
        }

        // AUTOINCREMENT (standalone, SQLite style)
        if ($token['type'] === SqlLexer::T_AUTOINCREMENT) {
            $this->pos++;
            $col->autoIncrement = true;
            return true;
        }

        return false;
    }

    /**
     * Parse default value expression
     */
    private function parseDefaultValue(): ASTNode
    {
        $token = $this->current();

        // NULL
        if ($this->match(SqlLexer::T_NULL)) {
            return new LiteralNode(null, 'null');
        }

        // String literal
        if ($token['type'] === SqlLexer::T_STRING) {
            $this->pos++;
            return new LiteralNode($token['value'], 'string');
        }

        // Number literal
        if ($token['type'] === SqlLexer::T_NUMBER) {
            $this->pos++;
            return new LiteralNode($token['value'], 'number');
        }

        // TRUE/FALSE
        if ($this->match(SqlLexer::T_TRUE)) {
            return new LiteralNode(true, 'boolean');
        }
        if ($this->match(SqlLexer::T_FALSE)) {
            return new LiteralNode(false, 'boolean');
        }

        // CURRENT_TIMESTAMP, etc.
        if ($token['type'] === SqlLexer::T_CURRENT_TIMESTAMP) {
            $this->pos++;
            return new LiteralNode('CURRENT_TIMESTAMP', 'keyword');
        }

        // Parenthesized expression
        if ($this->match(SqlLexer::T_LPAREN)) {
            $expr = $this->parseExpression();
            $this->expect(SqlLexer::T_RPAREN);
            return $expr;
        }

        throw new SqlSyntaxException(
            "Expected default value",
            $this->sql,
            $token['pos']
        );
    }

    /**
     * Parse table-level constraint
     */
    private function parseTableConstraint(): TableConstraint
    {
        $constraint = new TableConstraint();

        // CONSTRAINT name
        if ($this->match(SqlLexer::T_CONSTRAINT)) {
            $constraint->name = $this->expectIdentifierName();
        }

        $token = $this->current();

        // PRIMARY KEY (col1, col2, ...)
        if ($token['type'] === SqlLexer::T_PRIMARY) {
            $this->pos++;
            $this->expect(SqlLexer::T_KEY);
            $constraint->constraintType = 'PRIMARY KEY';
            $constraint->columns = $this->parseConstraintColumnList();
            return $constraint;
        }

        // UNIQUE (col1, col2, ...)
        if ($token['type'] === SqlLexer::T_UNIQUE) {
            $this->pos++;
            $constraint->constraintType = 'UNIQUE';
            $constraint->columns = $this->parseConstraintColumnList();
            return $constraint;
        }

        // FOREIGN KEY (col1, ...) REFERENCES table(col1, ...)
        if ($token['type'] === SqlLexer::T_FOREIGN) {
            $this->pos++;
            $this->expect(SqlLexer::T_KEY);
            $constraint->constraintType = 'FOREIGN KEY';
            $constraint->columns = $this->parseConstraintColumnList();

            $this->expect(SqlLexer::T_REFERENCES);
            $constraint->references = $this->expectIdentifierName();
            $constraint->referencesColumns = $this->parseConstraintColumnList();

            // ON DELETE / ON UPDATE
            while ($this->match(SqlLexer::T_ON)) {
                if ($this->match(SqlLexer::T_DELETE)) {
                    $constraint->onDelete = $this->parseReferentialAction();
                } elseif ($this->match(SqlLexer::T_UPDATE)) {
                    $constraint->onUpdate = $this->parseReferentialAction();
                }
            }

            return $constraint;
        }

        // CHECK (expression)
        if ($token['type'] === SqlLexer::T_CHECK) {
            $this->pos++;
            $constraint->constraintType = 'CHECK';
            $this->expect(SqlLexer::T_LPAREN);
            $constraint->checkExpression = $this->parseExpression();
            $this->expect(SqlLexer::T_RPAREN);
            return $constraint;
        }

        throw new SqlSyntaxException(
            "Expected constraint type (PRIMARY KEY, UNIQUE, FOREIGN KEY, CHECK)",
            $this->sql,
            $token['pos']
        );
    }

    /**
     * Parse (col1, col2, ...) list for constraints
     */
    private function parseConstraintColumnList(): array
    {
        $this->expect(SqlLexer::T_LPAREN);
        $columns = [];
        do {
            $columns[] = $this->expectIdentifierName();
        } while ($this->match(SqlLexer::T_COMMA));
        $this->expect(SqlLexer::T_RPAREN);
        return $columns;
    }

    /**
     * Parse referential action (CASCADE, RESTRICT, SET NULL, etc.)
     */
    private function parseReferentialAction(): string
    {
        if ($this->match(SqlLexer::T_CASCADE)) return 'CASCADE';
        if ($this->match(SqlLexer::T_RESTRICT)) return 'RESTRICT';
        if ($this->match(SqlLexer::T_NO)) {
            $this->expect(SqlLexer::T_ACTION);
            return 'NO ACTION';
        }
        if ($this->match(SqlLexer::T_SET)) {
            if ($this->match(SqlLexer::T_NULL)) return 'SET NULL';
            if ($this->match(SqlLexer::T_DEFAULT)) return 'SET DEFAULT';
        }

        throw new SqlSyntaxException(
            "Expected referential action",
            $this->sql,
            $this->current()['pos']
        );
    }

    /**
     * Parse CREATE INDEX statement
     */
    private function parseCreateIndex(bool $unique): CreateIndexStatement
    {
        $stmt = new CreateIndexStatement();
        $stmt->unique = $unique;

        // IF NOT EXISTS
        if ($this->match(SqlLexer::T_IF)) {
            $this->expect(SqlLexer::T_NOT);
            $this->expectKeyword('EXISTS');
            $stmt->ifNotExists = true;
        }

        // Index name
        $stmt->name = $this->expectIdentifierName();

        // ON table
        $this->expect(SqlLexer::T_ON);
        $stmt->table = $this->parseIdentifier();

        // (col1 [ASC|DESC], col2 [ASC|DESC], ...)
        $this->expect(SqlLexer::T_LPAREN);
        do {
            $col = new IndexColumn();
            $col->name = $this->expectIdentifierName();

            if ($this->match(SqlLexer::T_ASC)) {
                $col->order = 'ASC';
            } elseif ($this->match(SqlLexer::T_DESC)) {
                $col->order = 'DESC';
            }

            $stmt->columns[] = $col;
        } while ($this->match(SqlLexer::T_COMMA));
        $this->expect(SqlLexer::T_RPAREN);

        return $stmt;
    }

    /**
     * Parse DROP statement (TABLE, INDEX)
     */
    private function parseDropStatement(): ASTNode
    {
        $this->expect(SqlLexer::T_DROP);
        $token = $this->current();

        if ($token['type'] === SqlLexer::T_TABLE) {
            return $this->parseDropTable();
        }

        if ($token['type'] === SqlLexer::T_INDEX) {
            $this->pos++; // consume INDEX
            return $this->parseDropIndex();
        }

        throw new SqlSyntaxException(
            "Expected TABLE or INDEX after DROP",
            $this->sql,
            $token['pos']
        );
    }

    /**
     * Parse DROP TABLE statement
     */
    private function parseDropTable(): DropTableStatement
    {
        $this->expect(SqlLexer::T_TABLE);
        $stmt = new DropTableStatement();

        // IF EXISTS
        if ($this->match(SqlLexer::T_IF)) {
            $this->expectKeyword('EXISTS');
            $stmt->ifExists = true;
        }

        $stmt->table = $this->parseIdentifier();
        return $stmt;
    }

    /**
     * Parse DROP INDEX statement
     */
    private function parseDropIndex(): DropIndexStatement
    {
        // INDEX token already consumed by parseDropStatement
        $stmt = new DropIndexStatement();

        // IF EXISTS
        if ($this->match(SqlLexer::T_IF)) {
            $this->expectKeyword('EXISTS');
            $stmt->ifExists = true;
        }

        $stmt->name = $this->expectIdentifierName();

        // ON table (optional, for MySQL compatibility)
        if ($this->match(SqlLexer::T_ON)) {
            $stmt->table = $this->parseIdentifier();
        }

        return $stmt;
    }

    /**
     * Expect a specific keyword (may be token or identifier)
     */
    private function expectKeyword(string $keyword): void
    {
        $token = $this->current();
        $upper = strtoupper($keyword);

        // Check if it matches as a token type
        if ($token['type'] === $upper) {
            $this->pos++;
            return;
        }

        // Check if it's an identifier with the keyword name
        if ($token['type'] === SqlLexer::T_IDENTIFIER && strtoupper($token['value']) === $upper) {
            $this->pos++;
            return;
        }

        throw new SqlSyntaxException(
            "Expected $keyword",
            $this->sql,
            $token['pos']
        );
    }

    /**
     * Expect an identifier token and return its name
     */
    private function expectIdentifierName(): string
    {
        $token = $this->current();
        if ($token['type'] !== SqlLexer::T_IDENTIFIER) {
            throw new SqlSyntaxException(
                "Expected identifier",
                $this->sql,
                $token['pos']
            );
        }
        $this->pos++;
        return $token['value'];
    }

    /**
     * Bind parameter values to PlaceholderNodes in an AST
     *
     * Supports both positional (?) and named (:name) placeholders.
     * For positional, params are consumed in order.
     * For named, params are looked up by name (without the colon).
     *
     * @param ASTNode $node The AST to bind params to
     * @param array $params Values to bind (modified by reference for positional)
     * @return int Number of params bound
     */
    public static function bindParams(ASTNode $node, array &$params): int
    {
        $bound = 0;

        if ($node instanceof PlaceholderNode) {
            if (str_starts_with($node->token, ':')) {
                $name = substr($node->token, 1);
                if (!array_key_exists($name, $params)) {
                    throw new \RuntimeException("Missing parameter for placeholder :$name");
                }
                $node->bind($params[$name]);
            } else {
                if (empty($params)) {
                    throw new \RuntimeException('Not enough parameters for placeholders in query');
                }
                $node->bind(array_shift($params));
            }
            return 1;
        }

        foreach (get_object_vars($node) as $value) {
            if ($value instanceof ASTNode) {
                $bound += self::bindParams($value, $params);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof ASTNode) {
                        $bound += self::bindParams($item, $params);
                    } elseif (is_array($item)) {
                        foreach ($item as $subItem) {
                            if ($subItem instanceof ASTNode) {
                                $bound += self::bindParams($subItem, $params);
                            }
                        }
                    }
                }
            }
        }

        return $bound;
    }
}
