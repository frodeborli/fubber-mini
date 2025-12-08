<?php

namespace mini\Parsing\SQL;

/**
 * SQL Lexer - Tokenizes SQL strings
 *
 * Features:
 * - Case-insensitive keywords
 * - String escaping (' ' and \')
 * - Positional (?) and named (:name) placeholders
 * - Comments (-- and # and /* *\/)
 * - Quoted identifiers (backticks)
 * - Dotted identifiers (table.column)
 */
class SqlLexer
{
    private string $sql;
    private int $length;
    private int $cursor = 0;

    // Token types
    public const T_SELECT = 'SELECT';
    public const T_INSERT = 'INSERT';
    public const T_UPDATE = 'UPDATE';
    public const T_DELETE = 'DELETE';
    public const T_FROM   = 'FROM';
    public const T_INTO   = 'INTO';
    public const T_VALUES = 'VALUES';
    public const T_SET    = 'SET';
    public const T_WHERE  = 'WHERE';
    public const T_AND    = 'AND';
    public const T_OR     = 'OR';
    public const T_IN     = 'IN';
    public const T_ORDER  = 'ORDER';
    public const T_BY     = 'BY';
    public const T_LIMIT  = 'LIMIT';
    public const T_AS     = 'AS';
    public const T_ASC    = 'ASC';
    public const T_DESC   = 'DESC';
    public const T_NOT    = 'NOT';
    public const T_IS     = 'IS';
    public const T_NULL   = 'NULL';
    public const T_LIKE   = 'LIKE';
    public const T_IDENTIFIER = 'IDENTIFIER';
    public const T_STRING = 'STRING';
    public const T_NUMBER = 'NUMBER';
    public const T_PLACEHOLDER = 'PLACEHOLDER';
    public const T_COMMA  = 'COMMA';
    public const T_LPAREN = 'LPAREN';
    public const T_RPAREN = 'RPAREN';
    public const T_OP     = 'OPERATOR';
    public const T_EOF    = 'EOF';

    public function __construct(string $sql)
    {
        $this->sql = $sql;
        $this->length = strlen($sql);
    }

    /**
     * Tokenize SQL string into array of tokens
     *
     * @return array Array of tokens with type, value, and position
     * @throws SqlSyntaxException
     */
    public function tokenize(): array
    {
        $tokens = [];

        while ($this->cursor < $this->length) {
            $start = $this->cursor;
            $char = $this->sql[$this->cursor];
            $next = $this->cursor + 1 < $this->length ? $this->sql[$this->cursor + 1] : '';

            // Whitespace
            if (ctype_space($char)) {
                $this->cursor++;
                continue;
            }

            // Comments
            if ($char === '#' || ($char === '-' && $next === '-')) {
                while ($this->cursor < $this->length && $this->sql[$this->cursor] !== "\n") {
                    $this->cursor++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $this->cursor += 2;
                while ($this->cursor < $this->length) {
                    if ($this->sql[$this->cursor] === '*' &&
                        ($this->cursor + 1 < $this->length && $this->sql[$this->cursor + 1] === '/')) {
                        $this->cursor += 2;
                        break;
                    }
                    $this->cursor++;
                }
                continue;
            }

            // Numbers
            if (is_numeric($char)) {
                $num = '';
                while ($this->cursor < $this->length &&
                       (is_numeric($this->sql[$this->cursor]) || $this->sql[$this->cursor] === '.')) {
                    $num .= $this->sql[$this->cursor++];
                }
                $tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'pos' => $start];
                continue;
            }

            // Strings (Single quotes) with Escaping
            if ($char === "'") {
                $this->cursor++;
                $str = '';
                while ($this->cursor < $this->length) {
                    $c = $this->sql[$this->cursor];

                    if ($c === "'") {
                        // Standard SQL escape: ''
                        if ($this->cursor + 1 < $this->length && $this->sql[$this->cursor + 1] === "'") {
                            $str .= "'";
                            $this->cursor += 2;
                            continue;
                        }
                        // End of string
                        $this->cursor++;
                        break;
                    }

                    if ($c === '\\') {
                        // Backslash escape: \'
                        if ($this->cursor + 1 < $this->length && $this->sql[$this->cursor + 1] === "'") {
                            $str .= "'";
                            $this->cursor += 2;
                            continue;
                        }
                    }

                    $str .= $c;
                    $this->cursor++;
                }
                $tokens[] = ['type' => self::T_STRING, 'value' => $str, 'pos' => $start];
                continue;
            }

            // Positional Placeholder (?)
            if ($char === '?') {
                $tokens[] = ['type' => self::T_PLACEHOLDER, 'value' => '?', 'pos' => $start];
                $this->cursor++;
                continue;
            }

            // Named Placeholder (:name)
            if ($char === ':') {
                $this->cursor++;

                if ($this->cursor < $this->length) {
                    $peek = $this->sql[$this->cursor];
                    if (ctype_alpha($peek) || $peek === '_') {
                        $pStart = $start;
                        while ($this->cursor < $this->length) {
                            $c = $this->sql[$this->cursor];
                            if (!ctype_alnum($c) && $c !== '_') {
                                break;
                            }
                            $this->cursor++;
                        }
                        $value = substr($this->sql, $pStart, $this->cursor - $pStart);
                        $tokens[] = ['type' => self::T_PLACEHOLDER, 'value' => $value, 'pos' => $start];
                        continue;
                    }
                }
                throw new SqlSyntaxException("Invalid placeholder syntax", $this->sql, $start);
            }

            // Operators / Punctuation
            if (in_array($char, ['(', ')', ',', '*', '=', '>', '<', '!'])) {
                $type = match($char) {
                    '(' => self::T_LPAREN,
                    ')' => self::T_RPAREN,
                    ',' => self::T_COMMA,
                    default => self::T_OP
                };

                if (($char === '!' || $char === '>' || $char === '<') && $next === '=') {
                    $tokens[] = ['type' => self::T_OP, 'value' => $char . '=', 'pos' => $start];
                    $this->cursor += 2;
                    continue;
                }

                $tokens[] = ['type' => $type, 'value' => $char, 'pos' => $start];
                $this->cursor++;
                continue;
            }

            // Identifiers and Keywords
            if (ctype_alpha($char) || $char === '_' || $char === '`') {
                $isQuoted = ($char === '`');
                if ($isQuoted) {
                    $this->cursor++;
                }

                $word = '';
                while ($this->cursor < $this->length) {
                    $c = $this->sql[$this->cursor];
                    if ($isQuoted) {
                        if ($c === '`') {
                            $this->cursor++;
                            break;
                        }
                    } else {
                        // Allow dots in identifiers (e.g. table.column)
                        if (!ctype_alnum($c) && $c !== '_' && $c !== '.') {
                            break;
                        }
                    }
                    $word .= $c;
                    $this->cursor++;
                }

                $upper = strtoupper($word);
                $type = self::T_IDENTIFIER;

                if (!$isQuoted) {
                    $type = match($upper) {
                        'SELECT' => self::T_SELECT,
                        'INSERT' => self::T_INSERT,
                        'UPDATE' => self::T_UPDATE,
                        'DELETE' => self::T_DELETE,
                        'FROM' => self::T_FROM,
                        'INTO' => self::T_INTO,
                        'VALUES' => self::T_VALUES,
                        'SET' => self::T_SET,
                        'WHERE' => self::T_WHERE,
                        'AND' => self::T_AND,
                        'OR' => self::T_OR,
                        'IN' => self::T_IN,
                        'ORDER' => self::T_ORDER,
                        'BY' => self::T_BY,
                        'LIMIT' => self::T_LIMIT,
                        'AS' => self::T_AS,
                        'ASC' => self::T_ASC,
                        'DESC' => self::T_DESC,
                        'NOT' => self::T_NOT,
                        'IS' => self::T_IS,
                        'NULL' => self::T_NULL,
                        'LIKE' => self::T_LIKE,
                        default => self::T_IDENTIFIER
                    };
                }

                $tokens[] = ['type' => $type, 'value' => $word, 'pos' => $start];
                continue;
            }

            // Unary Minus / Plus
            if ($char === '-' || $char === '+') {
                $tokens[] = ['type' => self::T_OP, 'value' => $char, 'pos' => $start];
                $this->cursor++;
                continue;
            }

            throw new SqlSyntaxException("Unexpected character '$char'", $this->sql, $this->cursor);
        }

        $tokens[] = ['type' => self::T_EOF, 'value' => null, 'pos' => $this->cursor];
        return $tokens;
    }
}
