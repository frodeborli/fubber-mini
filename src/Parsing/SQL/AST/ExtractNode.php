<?php

namespace mini\Parsing\SQL\AST;

/**
 * EXTRACT(field FROM source) - SQL:2003 F052
 *
 * The field is a keyword, not an expression, so EXTRACT cannot be written as
 * an ordinary function call. Like {@see CastNode} it extends FunctionCallNode
 * anyway: the source *is* an ordinary argument, so every generic AST walk
 * (aggregate detection, column collection, parameter binding, deep cloning)
 * keeps working without learning a new node type. Only the renderer and the
 * evaluator care about the difference.
 */
class ExtractNode extends FunctionCallNode
{
    public string $type = 'EXTRACT';

    /**
     * The datetime fields this engine extracts.
     *
     * The standard also defines TIMEZONE_HOUR/TIMEZONE_MINUTE; Mini stores
     * datetimes as naive text, so there is no zone to report and offering the
     * fields would be a lie.
     */
    public const FIELDS = ['YEAR', 'MONTH', 'DAY', 'HOUR', 'MINUTE', 'SECOND'];

    /** One of self::FIELDS, upper-cased */
    public string $field;

    public function __construct(string $field, ASTNode $source)
    {
        parent::__construct('EXTRACT', [$source]);
        $this->field = $field;
    }
}
