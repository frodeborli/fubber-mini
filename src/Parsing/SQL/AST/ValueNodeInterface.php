<?php

namespace mini\Parsing\SQL\AST;

/**
 * Marker interface for AST nodes that represent values
 *
 * Value nodes can appear in expression contexts where a value is expected:
 * - Right-hand side of comparisons (=, !=, >, <, etc.)
 * - IN clause value lists
 * - Function arguments
 *
 * Implementors:
 * - LiteralNode - constants: 'hello', 42, NULL
 * - PlaceholderNode - parameters: ?, :name
 * - SubqueryNode - subqueries: (SELECT ...)
 */
interface ValueNodeInterface
{
}
