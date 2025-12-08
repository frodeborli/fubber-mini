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
    SubqueryNode,
    FunctionCallNode,
    SelectStatement,
    ColumnNode
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

        // SubqueryNode - bind params inside the subquery
        if ($node instanceof SubqueryNode) {
            $new = clone $node;
            $new->query = $this->bindNode($node->query);
            return $new;
        }

        if ($node instanceof FunctionCallNode) {
            $new = clone $node;
            $new->args = array_map(fn($arg) => $this->bindNode($arg), $node->args);
            return $new;
        }

        if ($node instanceof SelectStatement) {
            $new = clone $node;
            if ($node->where) {
                $new->where = $this->bindNode($node->where);
            }
            // Note: We don't bind other parts of SELECT (columns, orderBy, etc)
            // because those typically don't contain placeholders
            return $new;
        }

        if ($node instanceof ColumnNode) {
            $new = clone $node;
            $new->expression = $this->bindNode($node->expression);
            return $new;
        }

        // Return unchanged for other node types
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
            return new LiteralNode('NULL', 'null');
        }

        if (is_int($value) || is_float($value)) {
            return new LiteralNode((string)$value, 'number');
        }

        // String - need to represent as quoted string
        return new LiteralNode($value, 'string');
    }
}
