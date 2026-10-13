<?php

namespace mini\Template;

/**
 * Compiles a template source file into plain PHP that writes through the
 * active TemplateContext ($this) instead of producing direct output.
 *
 * ## Why
 *
 * The previous renderer wrapped every template in ob_start()/ob_get_clean().
 * Output buffers belong to the whole PHP process, not to one render() call.
 * On a runtime where requests can interleave inside a process (a worker
 * that suspends mid-template on a database call), one request's HTML can
 * land in another request's buffer. Compiling away every direct-output
 * construct removes the output buffer from the picture entirely: all
 * output flows through a RenderSink object that belongs to just one
 * render() call (see RenderSink and TemplateContext).
 *
 * ## What gets translated
 *
 * - Inline HTML (the T_INLINE_HTML token) becomes `$this->echo('...')`,
 *   with the literal text wrapped in a single-quoted string. Its newlines
 *   stay inside the literal - nothing is escaped except `\` and `'`.
 * - `<?= expr ?>` becomes `<?php $this->echo(expr); ?>`.
 * - `echo a, b;` becomes `$this->echo(a, b);` - a straight keyword rename,
 *   since TemplateContext::echo() is variadic and takes the same
 *   comma-separated argument list `echo` does.
 * - `print expr` becomes `$this->print(expr)`, which still evaluates to
 *   int(1), so `print` keeps working as an expression.
 * - `printf(...)` and `vprintf(...)` are renamed to `$this->printf(...)`
 *   and `$this->vprintf(...)` - a pure token rename, their arguments are
 *   untouched. The methods do sprintf()/vsprintf(), write the result, and
 *   return its length, exactly like the real functions.
 * - Everything else (control structures, alternative syntax, heredoc and
 *   nowdoc, assignments, function calls, ...) passes through unchanged.
 *
 * A call written as `->printf(`, `::printf(`, `?->printf(`, or a function
 * or method declaration named `printf`/`vprintf`/`echo`/`print` is left
 * untouched - only a plain, unqualified call to the global function is
 * rewritten.
 *
 * ## What's forbidden
 *
 * Compiling fails, with an exception naming the template file and the
 * offending line, for:
 *
 * - `var_dump()` and `debug_zval_dump()` - always, since they only ever
 *   write directly;
 * - `var_export()` and `print_r()` called *without* their second (return)
 *   argument - with it, they return a string instead of writing, which is
 *   fine;
 * - `include`/`include_once`/`require`/`require_once` - raw inclusion
 *   bypasses compilation entirely (the included file would still produce
 *   direct output) and Mini has its own API for this: use
 *   `mini\render()` for partials and `$this->extend()`/`$this->block()`
 *   for layout inheritance.
 *
 * This is a deliberately small list, not an exhaustive scan for every way
 * PHP can write output. A function or method called from a template that
 * outputs directly (an `echo` inside a helper class, `ob_*`, `flush()`,
 * `readfile()`, ...) is not compiled and is not detected - its output
 * bypasses the sink. See src/Template/README.md for this limitation; such
 * code should return a string (or Stringable) instead of printing it.
 *
 * ## Line numbers
 *
 * Every translation above replaces token text with different text, never
 * with a different number of newlines, and inline HTML's own newlines are
 * carried over verbatim inside its string literal. The compiled file
 * therefore has exactly the same number of lines as the source, with each
 * construct starting on the same line it started on in the source - a
 * thrown exception's line number and any backtrace line point at the
 * right place in both the source and the compiled file.
 */
final class TemplateCompiler
{
    /** Functions that only ever write directly - never compiled, always forbidden. */
    private const ALWAYS_FORBIDDEN = ['var_dump', 'debug_zval_dump'];

    /** Functions that write directly unless called with their return argument. */
    private const FORBIDDEN_WITHOUT_RETURN_ARG = ['var_export', 'print_r'];

    /** Global functions renamed to a TemplateContext method, arguments untouched. */
    private const RENAMED_TO_METHOD = ['printf' => 'printf', 'vprintf' => 'vprintf'];

    /**
     * Compile template source into PHP source that writes through $this
     * (a TemplateContext) instead of producing direct output.
     *
     * @param string $source Raw template file contents.
     * @param string $displayPath Path used in compile-error messages, e.g.
     *   the template's path relative to the project root.
     * @throws TemplateCompileException On a forbidden construct.
     */
    public static function compile(string $source, string $displayPath): string
    {
        $tokens = \PhpToken::tokenize($source);
        $count = count($tokens);

        $out = '';
        $depth = 0;
        /** @var list<array{kind: string, baseDepth: int}> */
        $frames = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Close any pending echo()/print() frames whose argument list
            // ends at this token, before emitting the token itself.
            $out .= self::closeFrames($frames, $token, $depth);

            switch ($token->id) {
                case T_INLINE_HTML:
                    $out .= self::compileInlineHtml($token->text);
                    break;

                case T_OPEN_TAG_WITH_ECHO:
                    $out .= '<?php $this->echo(';
                    $frames[] = ['kind' => 'echo', 'baseDepth' => $depth];
                    break;

                case T_ECHO:
                    $out .= '$this->echo(';
                    $frames[] = ['kind' => 'echo', 'baseDepth' => $depth];
                    break;

                case T_PRINT:
                    $out .= '$this->print(';
                    $frames[] = ['kind' => 'print', 'baseDepth' => $depth];
                    break;

                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    throw new TemplateCompileException(
                        $displayPath,
                        $token->line,
                        "Raw '{$token->text}' is forbidden in templates because the included file would bypass "
                            . "compilation and write directly. Use mini\\render() for partials, or "
                            . "\$this->extend()/\$this->block() for layout inheritance"
                    );

                case T_STRING:
                case T_NAME_FULLY_QUALIFIED:
                    $out .= self::compileIdentifier($tokens, $i, $count, $displayPath);
                    break;

                default:
                    if ($token->text === '(' || $token->text === '[' || $token->text === '{') {
                        $depth++;
                    } elseif ($token->text === ')' || $token->text === ']' || $token->text === '}') {
                        $depth--;
                    }
                    $out .= $token->text;
            }
        }

        return $out;
    }

    /**
     * Pop every pending echo()/print() frame that ends at $token, emitting
     * its closing ")" - the terminating token itself ( `;`, `?>`, a comma
     * after a bare `print`, or a bracket belonging to an outer construct)
     * is left for the main loop to emit normally right after.
     */
    private static function closeFrames(array &$frames, \PhpToken $token, int $depth): string
    {
        $out = '';
        while ($frames) {
            $frame = $frames[array_key_last($frames)];
            if ($frame['baseDepth'] !== $depth) {
                break;
            }
            $isClosingBracket = $token->text === ')' || $token->text === ']' || $token->text === '}';
            $isTerminator = $token->text === ';'
                || $token->id === T_CLOSE_TAG
                || $isClosingBracket
                || ($frame['kind'] === 'print' && $token->text === ',');
            if (!$isTerminator) {
                break;
            }
            array_pop($frames);
            $out .= ')';
        }
        return $out;
    }

    /**
     * Translate a T_INLINE_HTML token into its own `<?php $this->echo('...'); ?>`
     * block, preserving every newline exactly inside the string literal.
     *
     * Inline HTML only ever occurs between two tags (or at the start/end of
     * the file), and the token right after it always starts with `<?`
     * reopening a tag - never with a newline - so this new tag pair can
     * never make PHP swallow a newline that belongs to the rendered
     * output.
     */
    private static function compileInlineHtml(string $html): string
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $html);
        return "<?php \$this->echo('" . $escaped . "');?>";
    }

    /**
     * Handle a T_STRING/T_NAME_FULLY_QUALIFIED token that might name a
     * forbidden or renamed function call. Returns the text to emit; throws
     * on a forbidden call.
     */
    private static function compileIdentifier(array $tokens, int $i, int $count, string $displayPath): string
    {
        $token = $tokens[$i];
        $name = $token->text;
        if ($name !== '' && $name[0] === '\\') {
            $name = substr($name, 1);
        }
        $lower = strtolower($name);

        $prevIndex = self::previousSignificant($tokens, $i);
        if ($prevIndex !== null) {
            $prevId = $tokens[$prevIndex]->id;
            if ($prevId === T_OBJECT_OPERATOR || $prevId === T_DOUBLE_COLON
                || $prevId === T_NULLSAFE_OBJECT_OPERATOR || $prevId === T_FUNCTION) {
                return $token->text;
            }
        }

        $nextIndex = self::nextSignificant($tokens, $i + 1, $count);
        if ($nextIndex === null || $tokens[$nextIndex]->text !== '(') {
            return $token->text;
        }

        if (in_array($lower, self::ALWAYS_FORBIDDEN, true)) {
            throw new TemplateCompileException(
                $displayPath,
                $token->line,
                "{$name}() is forbidden in templates because it always writes directly. "
                    . "Return the value instead, e.g. with a logger, or remove the debugging call"
            );
        }

        if (in_array($lower, self::FORBIDDEN_WITHOUT_RETURN_ARG, true)) {
            $argCount = self::countCallArgs($tokens, $nextIndex, $count);
            if ($argCount < 2) {
                throw new TemplateCompileException(
                    $displayPath,
                    $token->line,
                    "{$name}() is forbidden in templates without its return argument, because it would write "
                        . "directly. Call it as {$name}(\$value, true) to get a string back"
                );
            }
            return $token->text;
        }

        if (isset(self::RENAMED_TO_METHOD[$lower])) {
            return '$this->' . self::RENAMED_TO_METHOD[$lower];
        }

        return $token->text;
    }

    /**
     * Index of the nearest preceding token that isn't whitespace or a
     * comment, or null if there isn't one.
     */
    private static function previousSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $id = $tokens[$j]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Index of the nearest following token that isn't whitespace or a
     * comment, or null if there isn't one.
     */
    private static function nextSignificant(array $tokens, int $i, int $count): ?int
    {
        for ($j = $i; $j < $count; $j++) {
            $id = $tokens[$j]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                return $j;
            }
        }
        return null;
    }

    /**
     * Number of top-level, comma-separated arguments in a call whose `(`
     * token is at $openIndex. Returns 0 for an empty argument list.
     */
    private static function countCallArgs(array $tokens, int $openIndex, int $count): int
    {
        $depth = 1;
        $sawToken = false;
        $commas = 0;
        for ($j = $openIndex + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                $sawToken = true;
                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                $sawToken = true;
                continue;
            }
            if ($depth === 1 && $text === ',') {
                $commas++;
                continue;
            }
            $id = $tokens[$j]->id;
            if ($id !== T_WHITESPACE && $id !== T_COMMENT && $id !== T_DOC_COMMENT) {
                $sawToken = true;
            }
        }
        return $sawToken ? $commas + 1 : 0;
    }
}
