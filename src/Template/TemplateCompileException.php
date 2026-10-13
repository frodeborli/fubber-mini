<?php

namespace mini\Template;

/**
 * Thrown by TemplateCompiler when a template source file uses a construct
 * that is forbidden in compiled templates (see TemplateCompiler's docblock
 * for the full list and the reasoning).
 *
 * Always carries the offending file path and line number in its message,
 * so the error points straight at the template source - not at the
 * compiled file or the renderer internals.
 */
class TemplateCompileException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly int $templateLine,
        string $reason,
    ) {
        parent::__construct("$reason in $path on line $templateLine");
    }
}
