<?php

declare(strict_types=1);

namespace mini\Html;

/**
 * CSS Selector Parser — lexer + recursive descent parser.
 *
 * Supports: tag, #id, .class, [attr], [attr="value"], *, descendant (space),
 * child (>), and selector lists (comma). Throws on unsupported syntax.
 *
 * Grammar:
 *   selectorList    = complexSelector (',' complexSelector)*
 *   complexSelector = compoundSelector (combinator compoundSelector)*
 *   combinator      = '>' | WS
 *   compoundSelector = simpleSelector+
 *   simpleSelector  = '*' | IDENT | HASH | '.' IDENT | '[' IDENT ('=' (STRING | IDENT))? ']'
 */
class CssSelectorParser
{
    private const T_IDENT    = 'IDENT';
    private const T_HASH     = 'HASH';
    private const T_DOT      = 'DOT';
    private const T_LBRACKET = 'LBRACKET';
    private const T_RBRACKET = 'RBRACKET';
    private const T_EQUALS   = 'EQUALS';
    private const T_STRING   = 'STRING';
    private const T_COMMA    = 'COMMA';
    private const T_GT       = 'GT';
    private const T_STAR     = 'STAR';
    private const T_WS       = 'WS';
    private const T_EOF      = 'EOF';

    private static string $pattern = '~
        (?<HASH>\#[\w-]+)|
        (?<STR>"[^"]*"|\'[^\']*\')|
        (?<ID>[\w-]+)|
        (?<DOT>\.)|
        (?<LB>\[)|
        (?<RB>\])|
        (?<EQ>=)|
        (?<COMMA>,)|
        (?<GT>>)|
        (?<STAR>\*)|
        (?<WS>\s+)|
        (?<ERR>[\s\S])
    ~x';

    private string $selector;
    /** @var array<int, array{type: string, value: string, pos: int}> */
    private array $tokens;
    private int $cursor;

    private function __construct(string $selector)
    {
        $this->selector = $selector;
    }

    /**
     * Parse a CSS selector string.
     *
     * @return array<int, array<int, array{compound: array, combinator: ?string}>>
     */
    public static function parse(string $selector): array
    {
        $parser = new self($selector);
        $parser->tokenize();
        return $parser->selectorList();
    }

    private function tokenize(): void
    {
        $count = preg_match_all(self::$pattern, $this->selector, $m, PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL);

        $tokens = [];
        $pos = 0;

        for ($i = 0; $i < $count; $i++) {
            $raw = $m[0][$i];
            $len = strlen($raw);

            if ($m['ERR'][$i] !== null) {
                throw new \InvalidArgumentException(
                    "CSS selector syntax error: unexpected '{$m['ERR'][$i]}' at position {$pos} in \"{$this->selector}\""
                );
            }

            if ($m['HASH'][$i] !== null) {
                $tokens[] = ['type' => self::T_HASH, 'value' => substr($m['HASH'][$i], 1), 'pos' => $pos];
            } elseif ($m['STR'][$i] !== null) {
                $tokens[] = ['type' => self::T_STRING, 'value' => substr($m['STR'][$i], 1, -1), 'pos' => $pos];
            } elseif ($m['ID'][$i] !== null) {
                $tokens[] = ['type' => self::T_IDENT, 'value' => $m['ID'][$i], 'pos' => $pos];
            } elseif ($m['DOT'][$i] !== null) {
                $tokens[] = ['type' => self::T_DOT, 'value' => '.', 'pos' => $pos];
            } elseif ($m['LB'][$i] !== null) {
                $tokens[] = ['type' => self::T_LBRACKET, 'value' => '[', 'pos' => $pos];
            } elseif ($m['RB'][$i] !== null) {
                $tokens[] = ['type' => self::T_RBRACKET, 'value' => ']', 'pos' => $pos];
            } elseif ($m['EQ'][$i] !== null) {
                $tokens[] = ['type' => self::T_EQUALS, 'value' => '=', 'pos' => $pos];
            } elseif ($m['COMMA'][$i] !== null) {
                $tokens[] = ['type' => self::T_COMMA, 'value' => ',', 'pos' => $pos];
            } elseif ($m['GT'][$i] !== null) {
                $tokens[] = ['type' => self::T_GT, 'value' => '>', 'pos' => $pos];
            } elseif ($m['STAR'][$i] !== null) {
                $tokens[] = ['type' => self::T_STAR, 'value' => '*', 'pos' => $pos];
            } elseif ($m['WS'][$i] !== null) {
                $tokens[] = ['type' => self::T_WS, 'value' => $m['WS'][$i], 'pos' => $pos];
            }

            $pos += $len;
        }

        $tokens[] = ['type' => self::T_EOF, 'value' => '', 'pos' => $pos];
        $this->tokens = $tokens;
        $this->cursor = 0;
    }

    private function current(): array
    {
        return $this->tokens[$this->cursor];
    }

    private function advance(): array
    {
        return $this->tokens[$this->cursor++];
    }

    private function skipWs(): void
    {
        while ($this->current()['type'] === self::T_WS) {
            $this->cursor++;
        }
    }

    private function expect(string $type): array
    {
        $tok = $this->current();
        if ($tok['type'] !== $type) {
            throw new \InvalidArgumentException(
                "CSS selector syntax error: expected {$type}, got '{$tok['value']}' at position {$tok['pos']} in \"{$this->selector}\""
            );
        }
        $this->cursor++;
        return $tok;
    }

    /**
     * @return array<int, array<int, array{compound: array, combinator: ?string}>>
     */
    private function selectorList(): array
    {
        $list = [];
        $this->skipWs();
        $list[] = $this->complexSelector();

        while ($this->current()['type'] === self::T_COMMA) {
            $this->cursor++; // consume ','
            $this->skipWs();
            $list[] = $this->complexSelector();
        }

        if ($this->current()['type'] !== self::T_EOF) {
            $tok = $this->current();
            throw new \InvalidArgumentException(
                "CSS selector syntax error: unexpected '{$tok['value']}' at position {$tok['pos']} in \"{$this->selector}\""
            );
        }

        return $list;
    }

    /**
     * @return array<int, array{compound: array, combinator: ?string}>
     */
    private function complexSelector(): array
    {
        $segments = [];
        $segments[] = ['compound' => $this->compoundSelector(), 'combinator' => null];

        while (true) {
            $type = $this->current()['type'];

            // Child combinator: optional WS > optional WS
            if ($type === self::T_GT) {
                $this->cursor++;
                $this->skipWs();
                $segments[] = ['compound' => $this->compoundSelector(), 'combinator' => '>'];
                continue;
            }

            // Descendant combinator: WS followed by a compound selector start
            if ($type === self::T_WS) {
                // Peek past whitespace to see what follows
                $saved = $this->cursor;
                $this->skipWs();
                $next = $this->current()['type'];

                // If followed by '>' it's WS around child combinator, not descendant
                if ($next === self::T_GT) {
                    $this->cursor++;
                    $this->skipWs();
                    $segments[] = ['compound' => $this->compoundSelector(), 'combinator' => '>'];
                    continue;
                }

                // If followed by comma, EOF, or nothing parsable — end of this complex selector
                if ($next === self::T_COMMA || $next === self::T_EOF) {
                    break;
                }

                // Otherwise it's a descendant combinator
                $segments[] = ['compound' => $this->compoundSelector(), 'combinator' => ' '];
                continue;
            }

            break;
        }

        return $segments;
    }

    /** @return array<int, array> */
    private function compoundSelector(): array
    {
        $parts = [];

        while (true) {
            $type = $this->current()['type'];

            if ($type === self::T_STAR) {
                $this->cursor++;
                $parts[] = ['universal'];
            } elseif ($type === self::T_IDENT) {
                $tok = $this->advance();
                $parts[] = ['type', $tok['value']];
            } elseif ($type === self::T_HASH) {
                $tok = $this->advance();
                $parts[] = ['id', $tok['value']];
            } elseif ($type === self::T_DOT) {
                $this->cursor++;
                $ident = $this->expect(self::T_IDENT);
                $parts[] = ['class', $ident['value']];
            } elseif ($type === self::T_LBRACKET) {
                $parts[] = $this->attributeSelector();
            } else {
                break;
            }
        }

        if ($parts === []) {
            $tok = $this->current();
            throw new \InvalidArgumentException(
                "CSS selector syntax error: expected selector, got '{$tok['value']}' at position {$tok['pos']} in \"{$this->selector}\""
            );
        }

        // Check for unsupported pseudo-classes/pseudo-elements
        if ($this->current()['type'] === self::T_IDENT) {
            $val = $this->current()['value'];
            if ($val[0] === ':') {
                throw new \InvalidArgumentException(
                    "CSS selector syntax error: pseudo-classes/pseudo-elements are not supported in \"{$this->selector}\""
                );
            }
        }

        return $parts;
    }

    /** @return array */
    private function attributeSelector(): array
    {
        $this->expect(self::T_LBRACKET);
        $attr = $this->expect(self::T_IDENT);

        if ($this->current()['type'] === self::T_RBRACKET) {
            $this->cursor++;
            return ['attr', $attr['value']];
        }

        $this->expect(self::T_EQUALS);

        $valTok = $this->current();
        if ($valTok['type'] === self::T_STRING) {
            $this->cursor++;
            $value = $valTok['value'];
        } elseif ($valTok['type'] === self::T_IDENT) {
            $this->cursor++;
            $value = $valTok['value'];
        } else {
            throw new \InvalidArgumentException(
                "CSS selector syntax error: expected attribute value, got '{$valTok['value']}' at position {$valTok['pos']} in \"{$this->selector}\""
            );
        }

        $this->expect(self::T_RBRACKET);
        return ['attr', $attr['value'], $value];
    }
}
