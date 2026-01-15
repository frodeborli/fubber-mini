<?php

namespace mini\Parsing\SQL;

use mini\Database\SqlDialect;
use mini\Parsing\SQL\AST\{
    ASTNode,
    SelectStatement,
    WithStatement,
    UnionNode,
    SubqueryNode,
    ColumnNode,
    JoinNode,
    IdentifierNode,
    LiteralNode,
    PlaceholderNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    BetweenOperation,
    ExistsOperation,
    FunctionCallNode,
    CaseWhenNode,
    WindowFunctionNode,
    NiladicFunctionNode,
    QuantifiedComparisonNode
};

/**
 * Renders AST nodes back to SQL strings
 *
 * This is the inverse of SqlParser - it takes an AST and produces SQL.
 * Used by PartialQuery to generate SQL from its internal AST representation.
 *
 * Usage:
 * ```php
 * $renderer = new SqlRenderer();
 * $sql = $renderer->render($ast);
 *
 * // Or with params collection (for prepared statements):
 * [$sql, $params] = $renderer->renderWithParams($ast);
 *
 * // For dialect-specific SQL:
 * $renderer = SqlRenderer::forDialect(SqlDialect::MySQL);
 * ```
 */
class SqlRenderer
{
    private SqlDialect $dialect;

    public function __construct(SqlDialect $dialect = SqlDialect::Generic)
    {
        $this->dialect = $dialect;
    }

    /**
     * Create a renderer for a specific SQL dialect
     */
    public static function forDialect(SqlDialect $dialect): self
    {
        return new self($dialect);
    }

    /**
     * Get a cached renderer for the specified dialect
     *
     * Reuses renderer instances per dialect for efficiency.
     */
    public static function get(SqlDialect $dialect = SqlDialect::Generic): self
    {
        static $renderers = [];
        $key = $dialect->name;
        return $renderers[$key] ??= new self($dialect);
    }

    /**
     * Collected params during renderWithParams()
     * @var array<int, mixed>|null
     */
    private ?array $collectingParams = null;

    /**
     * Render an AST node to SQL string
     *
     * @param ASTNode $node The AST node to render
     * @return string The SQL string
     */
    public function render(ASTNode $node): string
    {
        return $this->doRender($node);
    }

    /**
     * Render an AST node to SQL string and collect bound parameter values
     *
     * Returns both the SQL (with ? placeholders) and an array of bound values
     * in the order they appear. This ensures params are always correctly
     * ordered to match their placeholders.
     *
     * @param ASTNode $node The AST node to render
     * @return array{string, array<int, mixed>} [sql, params]
     * @throws \RuntimeException If any placeholder is unbound
     */
    public function renderWithParams(ASTNode $node): array
    {
        $this->collectingParams = [];
        try {
            $sql = $this->doRender($node);
            return [$sql, $this->collectingParams];
        } finally {
            $this->collectingParams = null;
        }
    }

    /**
     * Render ORDER BY items to SQL string (without ORDER BY keywords)
     *
     * @param array<int, array{column: ASTNode, direction: string}> $orderBy
     * @return string The rendered ORDER BY clause body
     */
    public function renderOrderByItems(array $orderBy): string
    {
        $parts = [];
        foreach ($orderBy as $order) {
            $part = $this->doRender($order['column']);
            if (isset($order['direction']) && strtoupper($order['direction']) === 'DESC') {
                $part .= ' DESC';
            }
            $parts[] = $part;
        }
        return implode(', ', $parts);
    }

    private function doRender(ASTNode $node): string
    {
        return match (true) {
            $node instanceof WithStatement => $this->renderWith($node),
            $node instanceof SelectStatement => $this->renderSelect($node),
            $node instanceof UnionNode => $this->renderUnion($node),
            $node instanceof SubqueryNode => $this->renderSubquery($node),
            $node instanceof ColumnNode => $this->renderColumn($node),
            $node instanceof JoinNode => $this->renderJoin($node),
            $node instanceof IdentifierNode => $this->renderIdentifier($node),
            $node instanceof LiteralNode => $this->renderLiteral($node),
            $node instanceof PlaceholderNode => $this->renderPlaceholder($node),
            $node instanceof BinaryOperation => $this->renderBinary($node),
            $node instanceof UnaryOperation => $this->renderUnary($node),
            $node instanceof InOperation => $this->renderIn($node),
            $node instanceof IsNullOperation => $this->renderIsNull($node),
            $node instanceof LikeOperation => $this->renderLike($node),
            $node instanceof BetweenOperation => $this->renderBetween($node),
            $node instanceof ExistsOperation => $this->renderExists($node),
            $node instanceof FunctionCallNode => $this->renderFunction($node),
            $node instanceof CaseWhenNode => $this->renderCase($node),
            $node instanceof WindowFunctionNode => $this->renderWindow($node),
            $node instanceof NiladicFunctionNode => $node->name,
            $node instanceof QuantifiedComparisonNode => $this->renderQuantified($node),
            default => throw new \RuntimeException('Unknown AST node type: ' . get_class($node)),
        };
    }

    private function renderWith(WithStatement $node): string
    {
        $sql = 'WITH ';
        if ($node->recursive) {
            $sql .= 'RECURSIVE ';
        }

        $cteParts = [];
        foreach ($node->ctes as $cte) {
            $name = $cte['name'];
            $columns = $cte['columns'] ?? null;
            $query = $this->render($cte['query']);

            $cteDef = $name;
            if ($columns) {
                $cteDef .= ' (' . implode(', ', $columns) . ')';
            }
            $cteDef .= ' AS (' . $query . ')';
            $cteParts[] = $cteDef;
        }

        $sql .= implode(', ', $cteParts);
        $sql .= ' ' . $this->render($node->query);

        return $sql;
    }

    private function renderSelect(SelectStatement $node): string
    {
        $sql = 'SELECT ';

        if ($node->distinct) {
            $sql .= 'DISTINCT ';
        }

        // Columns
        $columnParts = [];
        foreach ($node->columns as $col) {
            $columnParts[] = $this->render($col);
        }
        $sql .= implode(', ', $columnParts);

        // FROM
        if ($node->from !== null) {
            $sql .= ' FROM ';
            if ($node->from instanceof SubqueryNode) {
                $sql .= '(' . $this->render($node->from->query) . ')';
            } else {
                $sql .= $this->render($node->from);
            }
            if ($node->fromAlias !== null) {
                $sql .= ' AS ' . $node->fromAlias;
            }
        }

        // JOINs
        foreach ($node->joins as $join) {
            $sql .= ' ' . $this->renderJoin($join);
        }

        // WHERE
        if ($node->where !== null) {
            $sql .= ' WHERE ' . $this->render($node->where);
        }

        // GROUP BY
        if ($node->groupBy !== null && !empty($node->groupBy)) {
            $groupParts = array_map(fn($g) => $this->render($g), $node->groupBy);
            $sql .= ' GROUP BY ' . implode(', ', $groupParts);
        }

        // HAVING
        if ($node->having !== null) {
            $sql .= ' HAVING ' . $this->render($node->having);
        }

        // ORDER BY
        if ($node->orderBy !== null && !empty($node->orderBy)) {
            $orderParts = [];
            foreach ($node->orderBy as $order) {
                $part = $this->render($order['column']);
                if (isset($order['direction']) && strtoupper($order['direction']) === 'DESC') {
                    $part .= ' DESC';
                }
                $orderParts[] = $part;
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        // LIMIT/OFFSET - dialect-specific
        $sql .= $this->renderLimitOffset($node->limit, $node->offset);

        return $sql;
    }

    /**
     * Render LIMIT/OFFSET clause based on dialect
     */
    private function renderLimitOffset(?ASTNode $limit, ?ASTNode $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        // SQL Server uses OFFSET/FETCH syntax (requires ORDER BY)
        if ($this->dialect === SqlDialect::SqlServer) {
            $sql = '';
            // OFFSET is required for FETCH in SQL Server
            $sql .= ' OFFSET ' . ($offset !== null ? $this->render($offset) : '0') . ' ROWS';
            if ($limit !== null) {
                $sql .= ' FETCH NEXT ' . $this->render($limit) . ' ROWS ONLY';
            }
            return $sql;
        }

        // Standard LIMIT/OFFSET syntax (PostgreSQL, SQLite, MySQL, etc.)
        $sql = '';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $this->render($limit);
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $this->render($offset);
        }
        return $sql;
    }

    private function renderUnion(UnionNode $node): string
    {
        $op = $node->operator;

        // Check dialect support for EXCEPT/INTERSECT
        if (($op === 'EXCEPT' || $op === 'INTERSECT') && !$this->dialect->supportsExcept()) {
            throw new \RuntimeException(
                "{$op} is not supported by {$this->dialect->getName()}. " .
                "Use alternative query patterns (e.g., NOT EXISTS for EXCEPT)."
            );
        }

        // Render without parentheses for simple SELECTs
        // Only add parentheses if needed for nested UNIONs
        $left = $this->render($node->left);
        $right = $this->render($node->right);

        if ($node->all) {
            $op .= ' ALL';
        }

        // Wrap in parens only if nested UNION
        $needsLeftParen = $node->left instanceof UnionNode;
        $needsRightParen = $node->right instanceof UnionNode;

        $leftSql = $needsLeftParen ? "($left)" : $left;
        $rightSql = $needsRightParen ? "($right)" : $right;

        return "$leftSql $op $rightSql";
    }

    private function renderSubquery(SubqueryNode $node): string
    {
        return '(' . $this->render($node->query) . ')';
    }

    private function renderColumn(ColumnNode $node): string
    {
        $sql = $this->render($node->expression);
        if ($node->alias !== null) {
            $sql .= ' AS ' . $node->alias;
        }
        return $sql;
    }

    private function renderJoin(JoinNode $node): string
    {
        $sql = $node->joinType . ' JOIN ';

        if ($node->table instanceof SubqueryNode) {
            $sql .= '(' . $this->render($node->table->query) . ')';
        } else {
            $sql .= $this->render($node->table);
        }

        if ($node->alias !== null) {
            $sql .= ' AS ' . $node->alias;
        }

        if ($node->condition !== null) {
            $sql .= ' ON ' . $this->render($node->condition);
        }

        return $sql;
    }

    private function renderIdentifier(IdentifierNode $node): string
    {
        $parts = [];
        foreach ($node->parts as $part) {
            // Asterisk is the wildcard, not an identifier - don't quote
            if ($part === '*') {
                $parts[] = $part;
            } elseif (preg_match('/[^a-zA-Z0-9_]/', $part)) {
                // Quote if contains special characters (spaces, etc.)
                $parts[] = '"' . str_replace('"', '""', $part) . '"';
            } else {
                $parts[] = $part;
            }
        }
        return implode('.', $parts);
    }

    private function renderLiteral(LiteralNode $node): string
    {
        if ($node->value === null || $node->valueType === 'null') {
            return 'NULL';
        }

        if ($node->valueType === 'number') {
            return (string) $node->value;
        }

        // String - quote with single quotes, escape internal quotes
        $escaped = str_replace("'", "''", (string) $node->value);
        return "'" . $escaped . "'";
    }

    private function renderPlaceholder(PlaceholderNode $node): string
    {
        // If we're collecting params, add the bound value to the collection
        if ($this->collectingParams !== null) {
            if (!$node->isBound) {
                throw new \RuntimeException(
                    'Unbound placeholder encountered during renderWithParams(). ' .
                    'All placeholders must have bound values.'
                );
            }
            $this->collectingParams[] = $node->boundValue;
        }

        // Always emit positional placeholder when value is bound (normalize named to positional)
        // This ensures SQL and params stay consistent
        if ($node->isBound) {
            return '?';
        }

        // Not bound - output original token
        return $node->token;
    }

    private function renderBinary(BinaryOperation $node): string
    {
        $left = $this->render($node->left);
        $right = $this->render($node->right);
        $op = strtoupper($node->operator);

        // Add parentheses around sub-expressions for safety
        if ($node->left instanceof BinaryOperation) {
            $left = "($left)";
        }
        if ($node->right instanceof BinaryOperation) {
            $right = "($right)";
        }

        return "$left $op $right";
    }

    private function renderUnary(UnaryOperation $node): string
    {
        $expr = $this->render($node->expression);
        $op = strtoupper($node->operator);

        // NOT needs space, - doesn't
        if ($op === 'NOT') {
            return "NOT ($expr)";
        }

        return $op . $expr;
    }

    private function renderIn(InOperation $node): string
    {
        $left = $this->render($node->left);
        $op = $node->negated ? 'NOT IN' : 'IN';

        if ($node->isSubquery()) {
            $values = $this->render($node->values);
        } else {
            $valueParts = array_map(fn($v) => $this->render($v), $node->values);
            $values = '(' . implode(', ', $valueParts) . ')';
        }

        return "$left $op $values";
    }

    private function renderIsNull(IsNullOperation $node): string
    {
        $expr = $this->render($node->expression);
        $op = $node->negated ? 'IS NOT NULL' : 'IS NULL';
        return "$expr $op";
    }

    private function renderLike(LikeOperation $node): string
    {
        $left = $this->render($node->left);
        $pattern = $this->render($node->pattern);
        $op = $node->negated ? 'NOT LIKE' : 'LIKE';
        return "$left $op $pattern";
    }

    private function renderBetween(BetweenOperation $node): string
    {
        $expr = $this->render($node->expression);
        $low = $this->render($node->low);
        $high = $this->render($node->high);
        $op = $node->negated ? 'NOT BETWEEN' : 'BETWEEN';
        return "$expr $op $low AND $high";
    }

    private function renderExists(ExistsOperation $node): string
    {
        $subquery = $this->render($node->subquery);
        $op = $node->negated ? 'NOT EXISTS' : 'EXISTS';
        return "$op $subquery";
    }

    private function renderFunction(FunctionCallNode $node): string
    {
        $name = strtoupper($node->name);

        if (empty($node->arguments)) {
            // Functions like COUNT(*) have no real arguments
            return "$name()";
        }

        $distinct = $node->distinct ? 'DISTINCT ' : '';
        $args = array_map(fn($a) => $this->render($a), $node->arguments);

        return "$name($distinct" . implode(', ', $args) . ')';
    }

    private function renderCase(CaseWhenNode $node): string
    {
        $sql = 'CASE';

        // Simple CASE has operand
        if ($node->operand !== null) {
            $sql .= ' ' . $this->render($node->operand);
        }

        foreach ($node->whenClauses as $when) {
            $sql .= ' WHEN ' . $this->render($when['when']);
            $sql .= ' THEN ' . $this->render($when['then']);
        }

        if ($node->elseResult !== null) {
            $sql .= ' ELSE ' . $this->render($node->elseResult);
        }

        $sql .= ' END';
        return $sql;
    }

    private function renderWindow(WindowFunctionNode $node): string
    {
        $sql = $this->render($node->function) . ' OVER (';

        $overParts = [];

        if (!empty($node->partitionBy)) {
            $parts = array_map(fn($p) => $this->render($p), $node->partitionBy);
            $overParts[] = 'PARTITION BY ' . implode(', ', $parts);
        }

        if (!empty($node->orderBy)) {
            $orderParts = [];
            foreach ($node->orderBy as $order) {
                $part = $this->render($order['expr']);
                if (isset($order['direction']) && strtoupper($order['direction']) === 'DESC') {
                    $part .= ' DESC';
                }
                $orderParts[] = $part;
            }
            $overParts[] = 'ORDER BY ' . implode(', ', $orderParts);
        }

        $sql .= implode(' ', $overParts) . ')';
        return $sql;
    }

    private function renderQuantified(QuantifiedComparisonNode $node): string
    {
        $left = $this->render($node->left);
        $subquery = $this->render($node->subquery);
        return "$left {$node->operator} {$node->quantifier} $subquery";
    }

    /**
     * Deep clone an AST node and all its children
     *
     * Creates a completely independent copy of the AST tree.
     * Used when adopting AST from another PartialQuery to ensure
     * mutations don't leak across query instances.
     *
     * @param ASTNode $node The node to clone
     * @return ASTNode The cloned node
     */
    public function deepClone(ASTNode $node): ASTNode
    {
        return match (true) {
            $node instanceof WithStatement => $this->cloneWith($node),
            $node instanceof SelectStatement => $this->cloneSelect($node),
            $node instanceof UnionNode => $this->cloneUnion($node),
            $node instanceof SubqueryNode => new SubqueryNode($this->deepClone($node->query)),
            $node instanceof ColumnNode => new ColumnNode($this->deepClone($node->expression), $node->alias),
            $node instanceof JoinNode => $this->cloneJoin($node),
            $node instanceof IdentifierNode => new IdentifierNode($node->parts),
            $node instanceof LiteralNode => new LiteralNode($node->value, $node->valueType),
            $node instanceof PlaceholderNode => $this->clonePlaceholder($node),
            $node instanceof BinaryOperation => new BinaryOperation(
                $this->deepClone($node->left),
                $node->operator,
                $this->deepClone($node->right)
            ),
            $node instanceof UnaryOperation => new UnaryOperation(
                $node->operator,
                $this->deepClone($node->expression)
            ),
            $node instanceof InOperation => $this->cloneIn($node),
            $node instanceof IsNullOperation => new IsNullOperation(
                $this->deepClone($node->expression),
                $node->negated
            ),
            $node instanceof LikeOperation => new LikeOperation(
                $this->deepClone($node->left),
                $this->deepClone($node->pattern),
                $node->negated
            ),
            $node instanceof BetweenOperation => new BetweenOperation(
                $this->deepClone($node->expression),
                $this->deepClone($node->low),
                $this->deepClone($node->high),
                $node->negated
            ),
            $node instanceof ExistsOperation => new ExistsOperation(
                new SubqueryNode($this->deepClone($node->subquery->query)),
                $node->negated
            ),
            $node instanceof FunctionCallNode => new FunctionCallNode(
                $node->name,
                array_map(fn($a) => $this->deepClone($a), $node->arguments),
                $node->distinct
            ),
            $node instanceof CaseWhenNode => $this->cloneCase($node),
            $node instanceof WindowFunctionNode => $this->cloneWindow($node),
            $node instanceof NiladicFunctionNode => new NiladicFunctionNode($node->name),
            $node instanceof QuantifiedComparisonNode => new QuantifiedComparisonNode(
                $this->deepClone($node->left),
                $node->operator,
                $node->quantifier,
                new SubqueryNode($this->deepClone($node->subquery->query))
            ),
            default => throw new \RuntimeException('Cannot clone unknown AST node type: ' . get_class($node)),
        };
    }

    private function cloneWith(WithStatement $node): WithStatement
    {
        $ctes = [];
        foreach ($node->ctes as $cte) {
            $ctes[] = [
                'name' => $cte['name'],
                'columns' => $cte['columns'] ?? null,
                'query' => $this->deepClone($cte['query']),
            ];
        }
        return new WithStatement($ctes, $node->recursive, $this->deepClone($node->query));
    }

    private function cloneSelect(SelectStatement $node): SelectStatement
    {
        $new = new SelectStatement();
        $new->distinct = $node->distinct;
        $new->columns = array_map(fn($c) => $this->deepClone($c), $node->columns);

        if ($node->from instanceof SubqueryNode) {
            $new->from = new SubqueryNode($this->deepClone($node->from->query));
        } elseif ($node->from !== null) {
            $new->from = $this->deepClone($node->from);
        }

        $new->fromAlias = $node->fromAlias;
        $new->joins = array_map(fn($j) => $this->deepClone($j), $node->joins);
        $new->where = $node->where !== null ? $this->deepClone($node->where) : null;

        if ($node->groupBy !== null) {
            $new->groupBy = array_map(fn($g) => $this->deepClone($g), $node->groupBy);
        }

        $new->having = $node->having !== null ? $this->deepClone($node->having) : null;

        if ($node->orderBy !== null) {
            $new->orderBy = array_map(fn($o) => [
                'column' => $this->deepClone($o['column']),
                'direction' => $o['direction'] ?? 'ASC',
            ], $node->orderBy);
        }

        $new->limit = $node->limit !== null ? $this->deepClone($node->limit) : null;
        $new->offset = $node->offset !== null ? $this->deepClone($node->offset) : null;

        return $new;
    }

    private function cloneUnion(UnionNode $node): UnionNode
    {
        return new UnionNode(
            $this->deepClone($node->left),
            $this->deepClone($node->right),
            $node->all,
            $node->operator
        );
    }

    private function cloneJoin(JoinNode $node): JoinNode
    {
        $table = $node->table instanceof SubqueryNode
            ? new SubqueryNode($this->deepClone($node->table->query))
            : $this->deepClone($node->table);

        return new JoinNode(
            $node->joinType,
            $table,
            $node->condition !== null ? $this->deepClone($node->condition) : null,
            $node->alias
        );
    }

    private function clonePlaceholder(PlaceholderNode $node): PlaceholderNode
    {
        $new = new PlaceholderNode($node->token);
        if ($node->isBound) {
            $new->bind($node->boundValue);
        }
        return $new;
    }

    private function cloneIn(InOperation $node): InOperation
    {
        if ($node->isSubquery()) {
            return new InOperation(
                $this->deepClone($node->left),
                new SubqueryNode($this->deepClone($node->values->query)),
                $node->negated
            );
        }

        return new InOperation(
            $this->deepClone($node->left),
            array_map(fn($v) => $this->deepClone($v), $node->values),
            $node->negated
        );
    }

    private function cloneCase(CaseWhenNode $node): CaseWhenNode
    {
        $whenClauses = array_map(fn($w) => [
            'when' => $this->deepClone($w['when']),
            'then' => $this->deepClone($w['then']),
        ], $node->whenClauses);

        return new CaseWhenNode(
            $node->operand !== null ? $this->deepClone($node->operand) : null,
            $whenClauses,
            $node->elseResult !== null ? $this->deepClone($node->elseResult) : null
        );
    }

    private function cloneWindow(WindowFunctionNode $node): WindowFunctionNode
    {
        $orderBy = array_map(fn($o) => [
            'expr' => $this->deepClone($o['expr']),
            'direction' => $o['direction'] ?? 'ASC',
        ], $node->orderBy);

        return new WindowFunctionNode(
            $this->deepClone($node->function),
            array_map(fn($p) => $this->deepClone($p), $node->partitionBy),
            $orderBy
        );
    }

    /**
     * Rename an identifier throughout an AST
     *
     * Creates a new AST with all occurrences of $oldName replaced with $newName.
     * Used for CTE renaming when composing queries.
     *
     * Only renames table/CTE references (single-part identifiers that match exactly).
     * Does not rename column qualifiers like `users.id` to `_cte_123.id` since
     * the table alias should be preserved.
     *
     * @param ASTNode $node The AST to transform
     * @param string $oldName The identifier name to find
     * @param string $newName The new name to use
     * @return ASTNode New AST with renamed identifiers
     */
    public function renameIdentifier(ASTNode $node, string $oldName, string $newName): ASTNode
    {
        // For identifiers, check if it matches and rename
        if ($node instanceof IdentifierNode) {
            // Only rename single-part identifiers (table/CTE names)
            // Multi-part identifiers like table.column keep their qualifier
            if (count($node->parts) === 1 && $node->parts[0] === $oldName) {
                return new IdentifierNode([$newName]);
            }
            return new IdentifierNode($node->parts);
        }

        // Recursively transform all other node types
        return match (true) {
            $node instanceof WithStatement => $this->renameInWith($node, $oldName, $newName),
            $node instanceof SelectStatement => $this->renameInSelect($node, $oldName, $newName),
            $node instanceof UnionNode => new UnionNode(
                $this->renameIdentifier($node->left, $oldName, $newName),
                $this->renameIdentifier($node->right, $oldName, $newName),
                $node->all,
                $node->operator
            ),
            $node instanceof SubqueryNode => new SubqueryNode(
                $this->renameIdentifier($node->query, $oldName, $newName)
            ),
            $node instanceof ColumnNode => new ColumnNode(
                $this->renameIdentifier($node->expression, $oldName, $newName),
                $node->alias
            ),
            $node instanceof JoinNode => $this->renameInJoin($node, $oldName, $newName),
            $node instanceof LiteralNode => new LiteralNode($node->value, $node->valueType),
            $node instanceof PlaceholderNode => $this->clonePlaceholder($node),
            $node instanceof BinaryOperation => new BinaryOperation(
                $this->renameIdentifier($node->left, $oldName, $newName),
                $node->operator,
                $this->renameIdentifier($node->right, $oldName, $newName)
            ),
            $node instanceof UnaryOperation => new UnaryOperation(
                $node->operator,
                $this->renameIdentifier($node->expression, $oldName, $newName)
            ),
            $node instanceof InOperation => $this->renameInIn($node, $oldName, $newName),
            $node instanceof IsNullOperation => new IsNullOperation(
                $this->renameIdentifier($node->expression, $oldName, $newName),
                $node->negated
            ),
            $node instanceof LikeOperation => new LikeOperation(
                $this->renameIdentifier($node->left, $oldName, $newName),
                $this->renameIdentifier($node->pattern, $oldName, $newName),
                $node->negated
            ),
            $node instanceof BetweenOperation => new BetweenOperation(
                $this->renameIdentifier($node->expression, $oldName, $newName),
                $this->renameIdentifier($node->low, $oldName, $newName),
                $this->renameIdentifier($node->high, $oldName, $newName),
                $node->negated
            ),
            $node instanceof ExistsOperation => new ExistsOperation(
                new SubqueryNode($this->renameIdentifier($node->subquery->query, $oldName, $newName)),
                $node->negated
            ),
            $node instanceof FunctionCallNode => new FunctionCallNode(
                $node->name,
                array_map(fn($a) => $this->renameIdentifier($a, $oldName, $newName), $node->arguments),
                $node->distinct
            ),
            $node instanceof CaseWhenNode => $this->renameInCase($node, $oldName, $newName),
            $node instanceof WindowFunctionNode => $this->renameInWindow($node, $oldName, $newName),
            $node instanceof NiladicFunctionNode => new NiladicFunctionNode($node->name),
            $node instanceof QuantifiedComparisonNode => new QuantifiedComparisonNode(
                $this->renameIdentifier($node->left, $oldName, $newName),
                $node->operator,
                $node->quantifier,
                new SubqueryNode($this->renameIdentifier($node->subquery->query, $oldName, $newName))
            ),
            default => throw new \RuntimeException('Cannot rename in unknown AST node type: ' . get_class($node)),
        };
    }

    private function renameInWith(WithStatement $node, string $oldName, string $newName): WithStatement
    {
        $ctes = [];
        foreach ($node->ctes as $cte) {
            $ctes[] = [
                'name' => $cte['name'] === $oldName ? $newName : $cte['name'],
                'columns' => $cte['columns'] ?? null,
                'query' => $this->renameIdentifier($cte['query'], $oldName, $newName),
            ];
        }
        return new WithStatement(
            $ctes,
            $node->recursive,
            $this->renameIdentifier($node->query, $oldName, $newName)
        );
    }

    private function renameInSelect(SelectStatement $node, string $oldName, string $newName): SelectStatement
    {
        $new = new SelectStatement();
        $new->distinct = $node->distinct;
        $new->columns = array_map(
            fn($c) => $this->renameIdentifier($c, $oldName, $newName),
            $node->columns
        );

        if ($node->from instanceof SubqueryNode) {
            $new->from = new SubqueryNode($this->renameIdentifier($node->from->query, $oldName, $newName));
        } elseif ($node->from !== null) {
            $new->from = $this->renameIdentifier($node->from, $oldName, $newName);
        }

        $new->fromAlias = $node->fromAlias;
        $new->joins = array_map(
            fn($j) => $this->renameInJoin($j, $oldName, $newName),
            $node->joins
        );
        $new->where = $node->where !== null
            ? $this->renameIdentifier($node->where, $oldName, $newName)
            : null;

        if ($node->groupBy !== null) {
            $new->groupBy = array_map(
                fn($g) => $this->renameIdentifier($g, $oldName, $newName),
                $node->groupBy
            );
        }

        $new->having = $node->having !== null
            ? $this->renameIdentifier($node->having, $oldName, $newName)
            : null;

        if ($node->orderBy !== null) {
            $new->orderBy = array_map(fn($o) => [
                'column' => $this->renameIdentifier($o['column'], $oldName, $newName),
                'direction' => $o['direction'] ?? 'ASC',
            ], $node->orderBy);
        }

        // Limit and offset are expressions, rename in case they reference tables (unlikely but safe)
        $new->limit = $node->limit !== null
            ? $this->renameIdentifier($node->limit, $oldName, $newName)
            : null;
        $new->offset = $node->offset !== null
            ? $this->renameIdentifier($node->offset, $oldName, $newName)
            : null;

        return $new;
    }

    private function renameInJoin(JoinNode $node, string $oldName, string $newName): JoinNode
    {
        $table = $node->table instanceof SubqueryNode
            ? new SubqueryNode($this->renameIdentifier($node->table->query, $oldName, $newName))
            : $this->renameIdentifier($node->table, $oldName, $newName);

        return new JoinNode(
            $node->joinType,
            $table,
            $node->condition !== null
                ? $this->renameIdentifier($node->condition, $oldName, $newName)
                : null,
            $node->alias
        );
    }

    private function renameInIn(InOperation $node, string $oldName, string $newName): InOperation
    {
        if ($node->isSubquery()) {
            return new InOperation(
                $this->renameIdentifier($node->left, $oldName, $newName),
                new SubqueryNode($this->renameIdentifier($node->values->query, $oldName, $newName)),
                $node->negated
            );
        }

        return new InOperation(
            $this->renameIdentifier($node->left, $oldName, $newName),
            array_map(fn($v) => $this->renameIdentifier($v, $oldName, $newName), $node->values),
            $node->negated
        );
    }

    private function renameInCase(CaseWhenNode $node, string $oldName, string $newName): CaseWhenNode
    {
        $whenClauses = array_map(fn($w) => [
            'when' => $this->renameIdentifier($w['when'], $oldName, $newName),
            'then' => $this->renameIdentifier($w['then'], $oldName, $newName),
        ], $node->whenClauses);

        return new CaseWhenNode(
            $node->operand !== null ? $this->renameIdentifier($node->operand, $oldName, $newName) : null,
            $whenClauses,
            $node->elseResult !== null ? $this->renameIdentifier($node->elseResult, $oldName, $newName) : null
        );
    }

    private function renameInWindow(WindowFunctionNode $node, string $oldName, string $newName): WindowFunctionNode
    {
        $orderBy = array_map(fn($o) => [
            'expr' => $this->renameIdentifier($o['expr'], $oldName, $newName),
            'direction' => $o['direction'] ?? 'ASC',
        ], $node->orderBy);

        return new WindowFunctionNode(
            $this->renameIdentifier($node->function, $oldName, $newName),
            array_map(fn($p) => $this->renameIdentifier($p, $oldName, $newName), $node->partitionBy),
            $orderBy
        );
    }
}
