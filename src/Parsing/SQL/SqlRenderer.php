<?php

namespace mini\Parsing\SQL;

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
 * ```
 */
class SqlRenderer
{
    /**
     * Render an AST node to SQL string
     *
     * @param ASTNode $node The AST node to render
     * @return string The SQL string
     */
    public function render(ASTNode $node): string
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
            $node instanceof PlaceholderNode => $node->token,
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

        // LIMIT
        if ($node->limit !== null) {
            $sql .= ' LIMIT ' . $this->render($node->limit);
        }

        // OFFSET
        if ($node->offset !== null) {
            $sql .= ' OFFSET ' . $this->render($node->offset);
        }

        return $sql;
    }

    private function renderUnion(UnionNode $node): string
    {
        $left = $this->render($node->left);
        $right = $this->render($node->right);

        $op = $node->operator;
        if ($node->all) {
            $op .= ' ALL';
        }

        return "($left) $op ($right)";
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
        // For now, render as simple name without quoting
        // PartialQuery can handle quoting at a higher level if needed
        return $node->getFullName();
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
            $node instanceof PlaceholderNode => new PlaceholderNode($node->token),
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
}
