<?php

namespace mini\Parsing\SQL;

/**
 * SQL Lexer - Tokenizes SQL strings
 *
 * Features:
 * - Case-insensitive keywords
 * - String escaping ('' and \')
 * - Positional (?) and named (:name) placeholders
 * - Comments (-- and # and /* *\/)
 * - Quoted identifiers: `backticks` (MySQL) and "double quotes" (standard SQL)
 * - Dot tokens for qualified names (table.column parsed in parser)
 * - Numbers with at most one decimal point
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
    public const T_OFFSET = 'OFFSET';
    public const T_AS     = 'AS';
    public const T_ASC    = 'ASC';
    public const T_DESC   = 'DESC';
    public const T_NOT    = 'NOT';
    public const T_IS     = 'IS';
    public const T_NULL   = 'NULL';
    public const T_TRUE   = 'TRUE';
    public const T_FALSE  = 'FALSE';
    public const T_LIKE   = 'LIKE';
    public const T_JOIN   = 'JOIN';
    public const T_LEFT   = 'LEFT';
    public const T_RIGHT  = 'RIGHT';
    public const T_INNER  = 'INNER';
    public const T_OUTER  = 'OUTER';
    public const T_FULL   = 'FULL';
    public const T_CROSS  = 'CROSS';
    public const T_ON     = 'ON';
    public const T_DISTINCT = 'DISTINCT';
    public const T_GROUP  = 'GROUP';
    public const T_HAVING = 'HAVING';
    public const T_BETWEEN = 'BETWEEN';
    public const T_EXISTS = 'EXISTS';
    public const T_UNION = 'UNION';
    public const T_INTERSECT = 'INTERSECT';
    public const T_EXCEPT = 'EXCEPT';
    public const T_ALL = 'ALL';
    public const T_ANY = 'ANY';
    public const T_SOME = 'SOME';  // SOME is synonym for ANY in SQL
    public const T_CASE = 'CASE';
    public const T_WHEN = 'WHEN';
    public const T_THEN = 'THEN';
    public const T_ELSE = 'ELSE';
    public const T_END = 'END';
    public const T_OVER = 'OVER';
    public const T_PARTITION = 'PARTITION';
    public const T_WITH = 'WITH';
    public const T_RECURSIVE = 'RECURSIVE';
    public const T_CURRENT_DATE = 'CURRENT_DATE';
    public const T_CURRENT_TIME = 'CURRENT_TIME';
    public const T_CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';
    public const T_FETCH = 'FETCH';
    public const T_FIRST = 'FIRST';
    public const T_NEXT  = 'NEXT';
    public const T_ROWS  = 'ROWS';
    public const T_ROW   = 'ROW';
    public const T_ONLY  = 'ONLY';
    public const T_IDENTIFIER = 'IDENTIFIER';
    public const T_STRING = 'STRING';
    public const T_NUMBER = 'NUMBER';
    public const T_PLACEHOLDER = 'PLACEHOLDER';
    public const T_COMMA  = 'COMMA';
    public const T_DOT    = 'DOT';
    public const T_STAR   = 'STAR';
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

            // Numbers (allow at most one decimal point)
            if (is_numeric($char)) {
                $num = '';
                $hasDot = false;
                while ($this->cursor < $this->length) {
                    $c = $this->sql[$this->cursor];
                    if (is_numeric($c)) {
                        $num .= $c;
                        $this->cursor++;
                    } elseif ($c === '.' && !$hasDot) {
                        $num .= $c;
                        $hasDot = true;
                        $this->cursor++;
                    } else {
                        break;
                    }
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
            if (in_array($char, ['(', ')', ',', '.', '*', '/', '=', '>', '<', '!', '%', '|'])) {
                $type = match($char) {
                    '(' => self::T_LPAREN,
                    ')' => self::T_RPAREN,
                    ',' => self::T_COMMA,
                    '.' => self::T_DOT,
                    '*' => self::T_STAR,
                    default => self::T_OP
                };

                if (($char === '!' || $char === '>' || $char === '<') && $next === '=') {
                    $tokens[] = ['type' => self::T_OP, 'value' => $char . '=', 'pos' => $start];
                    $this->cursor += 2;
                    continue;
                }

                // <> is alias for !=
                if ($char === '<' && $next === '>') {
                    $tokens[] = ['type' => self::T_OP, 'value' => '<>', 'pos' => $start];
                    $this->cursor += 2;
                    continue;
                }

                // || string concatenation operator
                if ($char === '|' && $next === '|') {
                    $tokens[] = ['type' => self::T_OP, 'value' => '||', 'pos' => $start];
                    $this->cursor += 2;
                    continue;
                }

                $tokens[] = ['type' => $type, 'value' => $char, 'pos' => $start];
                $this->cursor++;
                continue;
            }

            // Identifiers and Keywords
            // Supports: bare identifiers, `backtick` (MySQL), "double quote" (standard SQL)
            if (ctype_alpha($char) || $char === '_' || $char === '`' || $char === '"') {
                $quoteChar = null;
                if ($char === '`' || $char === '"') {
                    $quoteChar = $char;
                    $this->cursor++;
                }

                $word = '';
                while ($this->cursor < $this->length) {
                    $c = $this->sql[$this->cursor];
                    if ($quoteChar !== null) {
                        if ($c === $quoteChar) {
                            // Handle escaped quotes (doubled quote chars)
                            if ($this->cursor + 1 < $this->length && $this->sql[$this->cursor + 1] === $quoteChar) {
                                $word .= $quoteChar;
                                $this->cursor += 2;
                                continue;
                            }
                            $this->cursor++;
                            break;
                        }
                    } else {
                        // Simple identifiers - alphanumeric and underscores only
                        // Dots are handled as separate tokens for table.column syntax
                        if (!ctype_alnum($c) && $c !== '_') {
                            break;
                        }
                    }
                    $word .= $c;
                    $this->cursor++;
                }

                $upper = strtoupper($word);
                $type = self::T_IDENTIFIER;

                if ($quoteChar === null) {
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
                        'OFFSET' => self::T_OFFSET,
                        'AS' => self::T_AS,
                        'ASC' => self::T_ASC,
                        'DESC' => self::T_DESC,
                        'NOT' => self::T_NOT,
                        'IS' => self::T_IS,
                        'NULL' => self::T_NULL,
                        'TRUE' => self::T_TRUE,
                        'FALSE' => self::T_FALSE,
                        'LIKE' => self::T_LIKE,
                        'JOIN' => self::T_JOIN,
                        'LEFT' => self::T_LEFT,
                        'RIGHT' => self::T_RIGHT,
                        'INNER' => self::T_INNER,
                        'OUTER' => self::T_OUTER,
                        'FULL' => self::T_FULL,
                        'CROSS' => self::T_CROSS,
                        'ON' => self::T_ON,
                        'DISTINCT' => self::T_DISTINCT,
                        'GROUP' => self::T_GROUP,
                        'HAVING' => self::T_HAVING,
                        'BETWEEN' => self::T_BETWEEN,
                        'EXISTS' => self::T_EXISTS,
                        'UNION' => self::T_UNION,
                        'INTERSECT' => self::T_INTERSECT,
                        'EXCEPT' => self::T_EXCEPT,
                        'ALL' => self::T_ALL,
                        'ANY' => self::T_ANY,
                        'SOME' => self::T_SOME,
                        'CASE' => self::T_CASE,
                        'WHEN' => self::T_WHEN,
                        'THEN' => self::T_THEN,
                        'ELSE' => self::T_ELSE,
                        'END' => self::T_END,
                        'OVER' => self::T_OVER,
                        'PARTITION' => self::T_PARTITION,
                        'WITH' => self::T_WITH,
                        'RECURSIVE' => self::T_RECURSIVE,
                        'CURRENT_DATE' => self::T_CURRENT_DATE,
                        'CURRENT_TIME' => self::T_CURRENT_TIME,
                        'CURRENT_TIMESTAMP' => self::T_CURRENT_TIMESTAMP,
                        'FETCH' => self::T_FETCH,
                        'FIRST' => self::T_FIRST,
                        'NEXT' => self::T_NEXT,
                        'ROWS' => self::T_ROWS,
                        'ROW' => self::T_ROW,
                        'ONLY' => self::T_ONLY,
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
