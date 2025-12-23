<?php

namespace mini\Parsing\SQL;

use mini\Parsing\SQL\AST\{
    ASTNode,
    PlaceholderNode,
    LiteralNode,
    BinaryOperation,
    UnaryOperation,
    InOperation,
    IsNullOperation,
    LikeOperation,
    BetweenOperation,
    ExistsOperation,
    SubqueryNode,
    FunctionCallNode,
    SelectStatement,
    InsertStatement,
    UpdateStatement,
    DeleteStatement,
    ColumnNode,
    JoinNode
};

/**
 * Binds parameters to AST by replacing placeholders with literal values
 *
 * This creates a new AST with all PlaceholderNode instances replaced
 * by LiteralNode instances containing the actual parameter values.
 *
 * Usage:
 * ```php
 * $binder = new AstParameterBinder(['Alice', 30]);
 * $boundAst = $binder->bind($ast);
 * // Now PlaceholderNode('?') are replaced with LiteralNode values
 * ```
 */
class AstParameterBinder
{
    private array $params;
    private int $positionalIndex = 0;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * Bind parameters to AST
     *
     * @param ASTNode $node Root AST node
     * @return ASTNode New AST with placeholders replaced
     */
    public function bind(ASTNode $node): ASTNode
    {
        $this->positionalIndex = 0;
        return $this->bindNode($node);
    }

    private function bindNode(ASTNode $node): ASTNode
    {
        // Replace PlaceholderNode with LiteralNode
        if ($node instanceof PlaceholderNode) {
            return $this->bindPlaceholder($node);
        }

        // Recursively bind child nodes
        if ($node instanceof BinaryOperation) {
            $new = clone $node;
            $new->left = $this->bindNode($node->left);
            $new->right = $this->bindNode($node->right);
            return $new;
        }

        if ($node instanceof UnaryOperation) {
            $new = clone $node;
            $new->expression = $this->bindNode($node->expression);
            return $new;
        }

        if ($node instanceof InOperation) {
            $new = clone $node;
            $new->left = $this->bindNode($node->left);
            if ($node->isSubquery()) {
                // Bind params inside subquery (params flow through)
                $new->values = $this->bindNode($node->values);
            } else {
                $new->values = array_map(fn($v) => $this->bindNode($v), $node->values);
            }
            return $new;
        }

        if ($node instanceof ExistsOperation) {
            $new = clone $node;
            $new->subquery = $this->bindNode($node->subquery);
            return $new;
        }

        if ($node instanceof IsNullOperation) {
            $new = clone $node;
            $new->expression = $this->bindNode($node->expression);
            return $new;
        }

        if ($node instanceof LikeOperation) {
            $new = clone $node;
            $new->left = $this->bindNode($node->left);
            $new->pattern = $this->bindNode($node->pattern);
            return $new;
        }

        if ($node instanceof BetweenOperation) {
            $new = clone $node;
            $new->expression = $this->bindNode($node->expression);
            $new->low = $this->bindNode($node->low);
            $new->high = $this->bindNode($node->high);
            return $new;
        }

        // SubqueryNode - bind params inside the subquery
        if ($node instanceof SubqueryNode) {
            $new = clone $node;
            $new->query = $this->bindNode($node->query);
            return $new;
        }

        if ($node instanceof FunctionCallNode) {
            $new = clone $node;
            $new->arguments = array_map(fn($arg) => $this->bindNode($arg), $node->arguments);
            return $new;
        }

        if ($node instanceof SelectStatement) {
            $new = clone $node;

            // Bind FROM (may be a subquery/derived table)
            if ($node->from instanceof ASTNode) {
                $new->from = $this->bindNode($node->from);
            }

            // Bind columns (may contain placeholders in expressions)
            $new->columns = array_map(fn($col) => $this->bindNode($col), $node->columns);

            // Bind JOINs
            $new->joins = array_map(fn($join) => $this->bindNode($join), $node->joins);

            if ($node->where) {
                $new->where = $this->bindNode($node->where);
            }

            // Bind GROUP BY expressions
            if ($node->groupBy) {
                $new->groupBy = array_map(fn($expr) => $this->bindNode($expr), $node->groupBy);
            }

            // Bind HAVING
            if ($node->having) {
                $new->having = $this->bindNode($node->having);
            }

            // Bind ORDER BY expressions
            if ($node->orderBy) {
                $new->orderBy = array_map(function ($item) {
                    return [
                        'column' => $this->bindNode($item['column']),
                        'direction' => $item['direction']
                    ];
                }, $node->orderBy);
            }

            // Bind LIMIT (may be placeholder)
            if ($node->limit) {
                $new->limit = $this->bindNode($node->limit);
            }

            // Bind OFFSET (may be placeholder)
            if ($node->offset) {
                $new->offset = $this->bindNode($node->offset);
            }

            return $new;
        }

        if ($node instanceof JoinNode) {
            $new = clone $node;
            if ($node->condition) {
                $new->condition = $this->bindNode($node->condition);
            }
            return $new;
        }

        if ($node instanceof ColumnNode) {
            $new = clone $node;
            $new->expression = $this->bindNode($node->expression);
            return $new;
        }

        if ($node instanceof InsertStatement) {
            $new = clone $node;
            // Bind each row of values
            $new->values = array_map(function ($row) {
                return array_map(fn($v) => $this->bindNode($v), $row);
            }, $node->values);
            return $new;
        }

        if ($node instanceof UpdateStatement) {
            $new = clone $node;
            // Bind SET values
            $new->updates = array_map(function ($update) {
                return [
                    'column' => $update['column'],
                    'value' => $this->bindNode($update['value'])
                ];
            }, $node->updates);
            // Bind WHERE
            if ($node->where) {
                $new->where = $this->bindNode($node->where);
            }
            return $new;
        }

        if ($node instanceof DeleteStatement) {
            $new = clone $node;
            if ($node->where) {
                $new->where = $this->bindNode($node->where);
            }
            return $new;
        }

        // Return unchanged for other node types (identifiers, literals)
        return $node;
    }

    private function bindPlaceholder(PlaceholderNode $node): LiteralNode
    {
        $value = null;

        if ($node->token === '?') {
            // Positional placeholder
            $value = $this->params[$this->positionalIndex++] ?? null;
        } else {
            // Named placeholder (:name)
            $name = ltrim($node->token, ':');
            $value = $this->params[$name] ?? null;
        }

        // Create LiteralNode with appropriate type
        if ($value === null) {
            return new LiteralNode(null, 'null');
        }

        if (is_int($value) || is_float($value)) {
            return new LiteralNode((string)$value, 'number');
        }

        // String - need to represent as quoted string
        return new LiteralNode($value, 'string');
    }
}
