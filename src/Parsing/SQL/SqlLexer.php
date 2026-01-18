<?php

namespace mini\Parsing\SQL;

/**
 * SQL Lexer - Tokenizes SQL strings
 *
 * Uses a single combined regex with named groups for fast tokenization.
 * Patterns are ordered long-to-short to ensure correct matching.
 */
class SqlLexer
{
    // Token types
    public const T_SELECT = 'SELECT';
    public const T_INSERT = 'INSERT';
    public const T_REPLACE = 'REPLACE';
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
    public const T_SOME = 'SOME';
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
    public const T_CREATE = 'CREATE';
    public const T_DROP = 'DROP';
    public const T_ALTER = 'ALTER';
    public const T_TABLE = 'TABLE';
    public const T_INDEX = 'INDEX';
    public const T_VIEW = 'VIEW';
    public const T_IF = 'IF';
    public const T_PRIMARY = 'PRIMARY';
    public const T_KEY = 'KEY';
    public const T_UNIQUE = 'UNIQUE';
    public const T_FOREIGN = 'FOREIGN';
    public const T_REFERENCES = 'REFERENCES';
    public const T_CONSTRAINT = 'CONSTRAINT';
    public const T_DEFAULT = 'DEFAULT';
    public const T_AUTOINCREMENT = 'AUTOINCREMENT';
    public const T_CHECK = 'CHECK';
    public const T_CASCADE = 'CASCADE';
    public const T_RESTRICT = 'RESTRICT';
    public const T_ACTION = 'ACTION';
    public const T_NO = 'NO';
    public const T_TEMPORARY = 'TEMPORARY';
    public const T_TEMP = 'TEMP';

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

    /** @var array<string, string> Keyword to token type */
    private const KEYWORDS = [
        'SELECT' => self::T_SELECT, 'INSERT' => self::T_INSERT, 'REPLACE' => self::T_REPLACE,
        'UPDATE' => self::T_UPDATE, 'DELETE' => self::T_DELETE, 'FROM' => self::T_FROM,
        'INTO' => self::T_INTO, 'VALUES' => self::T_VALUES, 'SET' => self::T_SET,
        'WHERE' => self::T_WHERE, 'AND' => self::T_AND, 'OR' => self::T_OR,
        'IN' => self::T_IN, 'ORDER' => self::T_ORDER, 'BY' => self::T_BY,
        'LIMIT' => self::T_LIMIT, 'OFFSET' => self::T_OFFSET, 'AS' => self::T_AS,
        'ASC' => self::T_ASC, 'DESC' => self::T_DESC, 'NOT' => self::T_NOT,
        'IS' => self::T_IS, 'NULL' => self::T_NULL, 'TRUE' => self::T_TRUE,
        'FALSE' => self::T_FALSE, 'LIKE' => self::T_LIKE, 'JOIN' => self::T_JOIN,
        'LEFT' => self::T_LEFT, 'RIGHT' => self::T_RIGHT, 'INNER' => self::T_INNER,
        'OUTER' => self::T_OUTER, 'FULL' => self::T_FULL, 'CROSS' => self::T_CROSS,
        'ON' => self::T_ON, 'DISTINCT' => self::T_DISTINCT, 'GROUP' => self::T_GROUP,
        'HAVING' => self::T_HAVING, 'BETWEEN' => self::T_BETWEEN, 'EXISTS' => self::T_EXISTS,
        'UNION' => self::T_UNION, 'INTERSECT' => self::T_INTERSECT, 'EXCEPT' => self::T_EXCEPT,
        'ALL' => self::T_ALL, 'ANY' => self::T_ANY, 'SOME' => self::T_SOME,
        'CASE' => self::T_CASE, 'WHEN' => self::T_WHEN, 'THEN' => self::T_THEN,
        'ELSE' => self::T_ELSE, 'END' => self::T_END, 'OVER' => self::T_OVER,
        'PARTITION' => self::T_PARTITION, 'WITH' => self::T_WITH, 'RECURSIVE' => self::T_RECURSIVE,
        'CURRENT_DATE' => self::T_CURRENT_DATE, 'CURRENT_TIME' => self::T_CURRENT_TIME,
        'CURRENT_TIMESTAMP' => self::T_CURRENT_TIMESTAMP, 'FETCH' => self::T_FETCH,
        'FIRST' => self::T_FIRST, 'NEXT' => self::T_NEXT, 'ROWS' => self::T_ROWS,
        'ROW' => self::T_ROW, 'ONLY' => self::T_ONLY, 'CREATE' => self::T_CREATE,
        'DROP' => self::T_DROP, 'ALTER' => self::T_ALTER, 'TABLE' => self::T_TABLE,
        'INDEX' => self::T_INDEX, 'VIEW' => self::T_VIEW, 'IF' => self::T_IF,
        'PRIMARY' => self::T_PRIMARY, 'KEY' => self::T_KEY, 'UNIQUE' => self::T_UNIQUE,
        'FOREIGN' => self::T_FOREIGN, 'REFERENCES' => self::T_REFERENCES,
        'CONSTRAINT' => self::T_CONSTRAINT, 'DEFAULT' => self::T_DEFAULT,
        'AUTOINCREMENT' => self::T_AUTOINCREMENT, 'CHECK' => self::T_CHECK,
        'CASCADE' => self::T_CASCADE, 'RESTRICT' => self::T_RESTRICT,
        'ACTION' => self::T_ACTION, 'NO' => self::T_NO,
        'TEMPORARY' => self::T_TEMPORARY, 'TEMP' => self::T_TEMP,
    ];

    /**
     * Combined regex pattern built once at class load time.
     * Named groups: WS (whitespace), CMT (comment), NUM, STR, HEX, POS, NAM, ID, BT, DQ, OP
     */
    private static string $pattern;

    private string $sql;

    public static function init(): void
    {
        // Operators sorted long-to-short for correct matching
        $ops = ['<>', '>=', '<=', '!=', '||', '>', '<', '=', '!', '+', '-', '*', '/', '%', '|', '(', ')', ',', '.'];
        $opPattern = implode('|', array_map(fn($op) => preg_quote($op, '~'), $ops));

        // Build combined pattern with named capture groups
        // Uses preg_match_all for batch processing - much faster than iterative preg_match
        self::$pattern = '~' .
            '(?<WS>\s+)|' .                                       // whitespace (skipped)
            '(?<CMT>--[^\n]*|\#[^\n]*|/\*[\s\S]*?\*/)|' .         // comments (skipped)
            '(?<NUM>\d+(?:\.\d*)?)|' .                            // numbers
            "'(?<STR>(?:[^'\\\\]|''|\\\\.)*)'|" .                 // strings
            "[xX]'(?<HEX>[0-9a-fA-F]*)'|" .                       // hex blobs
            '(?<POS>\?)|' .                                       // positional placeholder
            '(?<NAM>:[a-zA-Z_]\w*)|' .                            // named placeholder
            '(?<ID>[a-zA-Z_]\w*)|' .                              // identifiers
            '`(?<BT>[^`]*)`|' .                                   // backtick quoted
            '"(?<DQ>[^"]*)"' .                                    // double quoted
            '|(?<OP>' . $opPattern . ')|' .                       // operators (sorted)
            '(?<ERR>[\s\S])' .                                    // catch-all for errors
        '~';
    }

    public function __construct(string $sql)
    {
        $this->sql = $sql;
    }

    /**
     * Tokenize SQL string into array of tokens
     *
     * @return array Array of tokens with type, value, and position
     * @throws SqlSyntaxException
     */
    public function tokenize(): array
    {
        $sql = $this->sql;
        $count = preg_match_all(self::$pattern, $sql, $m, PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL);

        // Local refs for hot path
        $keywords = self::KEYWORDS;
        $raw = $m[0];
        $mWS = $m['WS'];
        $mCMT = $m['CMT'];
        $mNUM = $m['NUM'];
        $mSTR = $m['STR'];
        $mHEX = $m['HEX'];
        $mPOS = $m['POS'];
        $mNAM = $m['NAM'];
        $mID = $m['ID'];
        $mBT = $m['BT'];
        $mDQ = $m['DQ'];
        $mOP = $m['OP'];
        $mERR = $m['ERR'];

        // Single pass: calculate offset and build tokens together
        $tokens = [];
        $pos = 0;

        for ($i = 0; $i < $count; $i++) {
            $len = strlen($raw[$i]);

            // Error check
            if ($mERR[$i] !== null) {
                throw new SqlSyntaxException("Unexpected character '{$mERR[$i]}'", $sql, $pos);
            }

            // Skip whitespace and comments
            if ($mWS[$i] !== null || $mCMT[$i] !== null) {
                $pos += $len;
                continue;
            }

            // Numbers
            if ($mNUM[$i] !== null) {
                $tokens[] = ['type' => self::T_NUMBER, 'value' => $mNUM[$i], 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Strings
            if ($mSTR[$i] !== null) {
                $str = $mSTR[$i];
                $str = str_replace("''", "'", $str);
                $str = str_replace("\\'", "'", $str);
                $tokens[] = ['type' => self::T_STRING, 'value' => $str, 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Hex blobs
            if ($mHEX[$i] !== null) {
                $hex = $mHEX[$i];
                $binary = $hex === '' ? '' : hex2bin($hex);
                if ($binary === false) {
                    throw new SqlSyntaxException("Invalid hex literal: x'$hex'", $sql, $pos);
                }
                $tokens[] = ['type' => self::T_STRING, 'value' => $binary, 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Positional placeholder
            if ($mPOS[$i] !== null) {
                $tokens[] = ['type' => self::T_PLACEHOLDER, 'value' => '?', 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Named placeholder
            if ($mNAM[$i] !== null) {
                $tokens[] = ['type' => self::T_PLACEHOLDER, 'value' => $mNAM[$i], 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Identifiers/keywords
            if ($mID[$i] !== null) {
                $word = $mID[$i];
                $type = $keywords[strtoupper($word)] ?? self::T_IDENTIFIER;
                $tokens[] = ['type' => $type, 'value' => $word, 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Backtick quoted
            if ($mBT[$i] !== null) {
                $tokens[] = ['type' => self::T_IDENTIFIER, 'value' => $mBT[$i], 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Double quoted
            if ($mDQ[$i] !== null) {
                $tokens[] = ['type' => self::T_IDENTIFIER, 'value' => $mDQ[$i], 'pos' => $pos];
                $pos += $len;
                continue;
            }

            // Operators
            if ($mOP[$i] !== null) {
                $op = $mOP[$i];
                $type = match ($op) {
                    '(' => self::T_LPAREN,
                    ')' => self::T_RPAREN,
                    ',' => self::T_COMMA,
                    '.' => self::T_DOT,
                    '*' => self::T_STAR,
                    default => self::T_OP,
                };
                $tokens[] = ['type' => $type, 'value' => $op, 'pos' => $pos];
            }
            $pos += $len;
        }

        $tokens[] = ['type' => self::T_EOF, 'value' => null, 'pos' => $pos];
        return $tokens;
    }
}

// Initialize static pattern
SqlLexer::init();
