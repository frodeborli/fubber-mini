<?php

namespace mini\Parsing\SQL\AST;

/**
 * CAST(expr AS type)
 *
 * A cast is a function call whose second "argument" is a type name rather than
 * an expression, so it cannot be written as an ordinary FunctionCallNode - but
 * it *is* one structurally. Extending FunctionCallNode keeps every generic AST
 * walk (aggregate detection, column collection, parameter binding) working on
 * the casted expression without each of them having to learn a new node type;
 * only the renderer and the evaluator care about the difference.
 */
class CastNode extends FunctionCallNode
{
    public string $type = 'CAST';

    /** The type name as written, e.g. 'INTEGER' or 'VARCHAR(255)' */
    public string $castType;

    public function __construct(ASTNode $expression, string $castType)
    {
        parent::__construct('CAST', [$expression]);
        $this->castType = $castType;
    }

    /**
     * The SQLite type affinity of the declared type: INTEGER, REAL, TEXT,
     * NUMERIC or BLOB.
     *
     * Determined by SQLite's rules, which is why any length or precision is
     * simply ignored: VARCHAR(255) is TEXT, DECIMAL(10,2) is NUMERIC.
     */
    public function affinity(): string
    {
        $name = strtoupper($this->castType);

        if (str_contains($name, 'INT')) {
            return 'INTEGER';
        }
        if (str_contains($name, 'CHAR') || str_contains($name, 'CLOB') || str_contains($name, 'TEXT')) {
            return 'TEXT';
        }
        if (str_contains($name, 'BLOB')) {
            return 'BLOB';
        }
        if (str_contains($name, 'REAL') || str_contains($name, 'FLOA') || str_contains($name, 'DOUB')) {
            return 'REAL';
        }
        return 'NUMERIC';
    }
}
