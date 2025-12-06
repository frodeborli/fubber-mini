<?php
namespace mini\Parsing;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use LogicException;
use Stringable;
use Traversable;

/**
 * GenericParser
 *
 * A small, general-purpose, lossless structural parser for arbitrary strings.
 *
 * The parser walks an input string and builds a lightweight syntax tree:
 *
 * - Text spans are represented as TextNode instances.
 * - Delimited regions (quotes and bracket pairs) are represented as DelimitedNode instances.
 * - The root of the tree is a NodeList, which:
 *   - implements ArrayAccess, IteratorAggregate and Countable,
 *   - can be cast to string to reconstruct the original input,
 *   - allows `$tree[1]` to access the second root-level node.
 *
 * Configuration is provided to the constructor:
 *
 * - `$quotes`      : list of characters that start/end quoted regions (e.g. ['"', "'", '`']).
 * - `$escapeStyle` : how strings are escaped:
 *      - GenericParser::ESCAPE_NONE           : no escape handling.
 *      - GenericParser::ESCAPE_C              : C-style backslash escapes (\" \\ \n \r \t ...).
 *      - GenericParser::ESCAPE_JSON           : JSON-style backslash escapes (subset of C-style).
 *      - GenericParser::ESCAPE_QUOTE_DOUBLING : quote-doubling ("" or '' inside strings).
 * - `$pairs`       : associative array of opening => closing delimiters
 *                    (e.g. ['(' => ')', '[' => ']', '{' => '}']).
 *
 * Example:
 *
 *  $parser = new GenericParser(
 *      quotes: ['"', "'", '`'],
 *      escapeStyle: GenericParser::ESCAPE_C,
 *      pairs: ['(' => ')', '[' => ']', '{' => '}']
 *  );
 *
 *  $tree = $parser->parse($input);
 *
 *  echo $tree;      // outputs the exact string that was parsed
 *  echo $tree[1];   // second root-level node
 *
 * This parser is intentionally minimal: it only understands quotes and bracket
 * pairs. Everything else is left as raw text. Quoted regions are treated as
 * opaque: their contents are not further structured into DelimitedNode
 * instances; they simply contain a single TextNode child.
 */
final class GenericParser
{
    public const ESCAPE_NONE            = 'none';
    public const ESCAPE_C               = 'c';
    public const ESCAPE_JSON            = 'json';
    public const ESCAPE_QUOTE_DOUBLING  = 'double';

    /** @var array<string,string> Quote open => close (same char for symmetric) */
    private array $quotes;

    /** @var array<string,string> Pair open => close (nested structure) */
    private array $pairs;

    private string $escapeStyle;

    private string $input = '';
    private int $length = 0;
    private int $pos = 0;

    /**
     * @param array<string,string> $quotes Map of opening => closing quote characters.
     *                                     Use same char for symmetric: ['"' => '"', "'" => "'"]
     *                                     Use different for asymmetric: ['[' => ']']
     * @param array<string,string> $pairs Map of opening => closing for nested structures (e.g., ['(' => ')'])
     * @param string               $escapeStyle One of the ESCAPE_* constants.
     */
    public function __construct(
        array $quotes = ['"' => '"', "'" => "'", '`' => '`', '[' => ']', '{' => '}'],
        array $pairs = ['(' => ')'],
        string $escapeStyle = self::ESCAPE_QUOTE_DOUBLING
    ) {
        $this->quotes = $quotes;
        $this->pairs = $pairs;
        $this->escapeStyle = $escapeStyle;
    }

    /**
     * Create a parser configured for SQL
     */
    public static function sql(): self
    {
        return new self(
            quotes: ['"' => '"', "'" => "'", '`' => '`', '[' => ']'],
            pairs: ['(' => ')'],
            escapeStyle: self::ESCAPE_QUOTE_DOUBLING
        );
    }

    /**
     * Parse the given string into a NodeList tree.
     *
     * The returned NodeList:
     * - can be cast to string to reconstruct the original input,
     * - is indexable via ArrayAccess (`$tree[1]`),
     * - is iterable (`foreach ($tree as $node)`).
     *
     * @param  string   $input
     * @return NodeList
     */
    public function parse(string $input): NodeList
    {
        $this->input  = $input;
        $this->length = strlen($input);
        $this->pos    = 0;

        return $this->parseList([]);
    }

    /**
     * Parse until the end of the string or until one of the given delimiters is encountered.
     *
     * @param  string[] $endDelimiters Single-character strings that terminate this list.
     * @return NodeList
     */
    private function parseList(array $endDelimiters): NodeList
    {
        $nodes = [];
        $text  = '';

        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];

            // Stop if we hit any of the configured end delimiters
            if (in_array($ch, $endDelimiters, true)) {
                break;
            }

            // Opening of a delimited region: quote or pair
            if (array_key_exists($ch, $this->quotes) || array_key_exists($ch, $this->pairs)) {
                // Flush any accumulated text first
                if ($text !== '') {
                    $nodes[] = new TextNode($text);
                    $text = '';
                }

                $nodes[] = $this->parseDelimited($ch);
                continue;
            }

            // Plain text
            $text .= $ch;
            $this->pos++;
        }

        if ($text !== '') {
            $nodes[] = new TextNode($text);
        }

        return new NodeList($nodes);
    }

    /**
     * Parse a delimited region starting at the current position.
     *
     * This handles:
     * - quotes (opaque, no nested structure), and
     * - pairs (with nested structure).
     *
     * @param  string       $startChar
     * @return DelimitedNode
     */
    private function parseDelimited(string $startChar): DelimitedNode
    {
        // Quotes: opaque content
        if (array_key_exists($startChar, $this->quotes)) {
            return $this->parseQuoted($startChar, $this->quotes[$startChar]);
        }

        // Pairs: nested structure
        $open  = $startChar;
        $close = $this->pairs[$open];

        // Consume the opening delimiter
        $this->pos++;

        $children = $this->parseList([$close]);

        $closed = false;
        if ($this->pos < $this->length && $this->input[$this->pos] === $close) {
            $this->pos++; // consume closing delimiter
            $closed = true;
        }

        return new DelimitedNode($open, $close, $children, $closed);
    }

    /**
     * Parse a quoted region starting at the current position.
     *
     * Quoted regions are treated as opaque: their content is represented as a
     * single TextNode child of the DelimitedNode.
     *
     * Escape behavior inside the quote depends on $escapeStyle:
     * - ESCAPE_NONE           : no escapes, quote closes on the next matching char.
     * - ESCAPE_C / ESCAPE_JSON: backslash escapes; backslash plus next char are
     *                           treated as literal content.
     * - ESCAPE_QUOTE_DOUBLING : doubled close char inside the quoted region
     *                           is treated as an escaped quote.
     *
     * @param  string $open  Opening quote character
     * @param  string $close Closing quote character (same as open for symmetric quotes)
     * @return DelimitedNode
     */
    private function parseQuoted(string $open, string $close): DelimitedNode
    {
        // Consume the opening quote
        $this->pos++;

        $buf    = '';
        $closed = false;

        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];

            // Quote-doubling: ]] or '' inside the string
            if ($this->escapeStyle === self::ESCAPE_QUOTE_DOUBLING) {
                if ($ch === $close) {
                    // If doubled, treat as escaped quote
                    if (
                        $this->pos + 1 < $this->length
                        && $this->input[$this->pos + 1] === $close
                    ) {
                        $buf .= $close . $close;
                        $this->pos += 2;
                        continue;
                    }

                    // Single close char terminates the string
                    $this->pos++;
                    $closed = true;
                    break;
                }

                $buf .= $ch;
                $this->pos++;
                continue;
            }

            // Backslash-escape mode (C / JSON)
            if ($this->escapeStyle === self::ESCAPE_C || $this->escapeStyle === self::ESCAPE_JSON) {
                if ($ch === '\\') {
                    // Include the backslash and the char following it as literal text,
                    // and do not treat the following char as a terminator.
                    if ($this->pos + 1 < $this->length) {
                        $buf .= '\\' . $this->input[$this->pos + 1];
                        $this->pos += 2;
                        continue;
                    }

                    // Trailing backslash at end-of-string, treat as literal
                    $buf .= '\\';
                    $this->pos++;
                    continue;
                }

                if ($ch === $close) {
                    $this->pos++;
                    $closed = true;
                    break;
                }

                $buf .= $ch;
                $this->pos++;
                continue;
            }

            // ESCAPE_NONE: no special escape handling, quote closes on next matching char
            if ($ch === $close) {
                $this->pos++;
                $closed = true;
                break;
            }

            $buf .= $ch;
            $this->pos++;
        }

        $children = new NodeList([
            new TextNode($buf),
        ]);

        return new DelimitedNode($open, $close, $children, $closed);
    }
}

/**
 * Node
 *
 * Base interface for all nodes in the syntax tree.
 *
 * Nodes are:
 * - TextNode        : raw text spans.
 * - DelimitedNode   : regions enclosed in delimiters such as quotes or brackets.
 */
interface Node extends Stringable
{
    /**
     * @return Node[] Direct child nodes (empty for TextNode).
     */
    public function children(): array;
}

/**
 * TextNode
 *
 * Represents a contiguous span of plain text.
 */
final class TextNode implements Node
{
    /**
     * @param string $text The exact text content of this node.
     */
    public function __construct(
        public readonly string $text
    ) {
    }

    /**
     * Return the raw text content.
     */
    public function __toString(): string
    {
        return $this->text;
    }

    /**
     * Text nodes have no children.
     *
     * @return Node[]
     */
    public function children(): array
    {
        return [];
    }
}

/**
 * DelimitedNode
 *
 * Represents a region enclosed by a pair of delimiters, such as:
 * - quotes     : " ... ", ' ... ', ` ... `
 * - brackets   : ( ... ), [ ... ], { ... }
 *
 * The node retains the exact opening and closing delimiters and a NodeList of
 * its inner content. If the closing delimiter was not found (unbalanced input),
 * $closed will be false, and __toString() will not append the closing delimiter,
 * preserving the original input faithfully.
 */
final class DelimitedNode implements Node
{
    /**
     * @param string   $open    Opening delimiter character.
     * @param string   $close   Closing delimiter character.
     * @param NodeList $children List of nodes inside this delimited region.
     * @param bool     $closed  True if a closing delimiter was found in the input.
     */
    public function __construct(
        public readonly string $open,
        public readonly string $close,
        public readonly NodeList $children,
        public readonly bool $closed = true
    ) {
    }

    /**
     * Reconstruct the original delimited region as a string.
     *
     * If the node is marked as not closed, the closing delimiter is omitted,
     * matching the original (unbalanced) input.
     */
    public function __toString(): string
    {
        return $this->open
             . $this->children
             . ($this->closed ? $this->close : '');
    }

    /**
     * @return Node[] Direct children of this delimited region.
     */
    public function children(): array
    {
        return $this->children->all();
    }
}

/**
 * NodeList
 *
 * A list of Node instances that:
 * - can be echoed to reconstruct the exact concatenated text of all nodes,
 * - supports array access (`$list[0]`),
 * - is iterable, and
 * - implements Countable.
 *
 * The root of a parsed tree is a NodeList, but NodeList is also used inside
 * DelimitedNode instances for nested content.
 */
final class NodeList implements ArrayAccess, IteratorAggregate, Countable, Stringable
{
    /** @var Node[] */
    private array $nodes;

    /**
     * @param Node[] $nodes
     */
    public function __construct(array $nodes)
    {
        $this->nodes = array_values($nodes);
    }

    /**
     * Reconstruct the original text represented by this list of nodes.
     */
    public function __toString(): string
    {
        return implode('', array_map('strval', $this->nodes));
    }

    /**
     * @inheritDoc
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->nodes[$offset]);
    }

    /**
     * @inheritDoc
     *
     * @return Node|null
     */
    public function offsetGet(mixed $offset): ?Node
    {
        return $this->nodes[$offset] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->nodes[] = $value;
        } else {
            $this->nodes[$offset] = $value;
        }
    }

    /**
     * @inheritDoc
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->nodes[$offset]);
        $this->nodes = array_values($this->nodes);
    }

    /**
     * Iterate over all nodes.
     *
     * @return Traversable<Node>
     */
    public function getIterator(): Traversable
    {
        yield from $this->nodes;
    }

    /**
     * Number of nodes in this list.
     */
    public function count(): int
    {
        return count($this->nodes);
    }

    /**
     * Return the underlying node array.
     *
     * @return Node[]
     */
    public function all(): array
    {
        return $this->nodes;
    }

    /**
     * Walk all nodes depth-first, applying a callback to each
     *
     * The callback receives each node and can return:
     * - A Node to replace it
     * - A string to replace it with a TextNode
     * - null to keep the node unchanged
     *
     * For DelimitedNode, children are walked first (depth-first), then
     * the callback is applied to the DelimitedNode itself.
     *
     * @param \Closure(Node): (Node|string|null) $fn
     */
    public function walk(\Closure $fn): void
    {
        foreach ($this->nodes as $i => $node) {
            // Recurse into children first
            if ($node instanceof DelimitedNode) {
                $node->children->walk($fn);
            }

            // Apply callback
            $result = $fn($node);
            if ($result === null) {
                continue;
            }

            if (is_string($result)) {
                $this->nodes[$i] = new TextNode($result);
            } else {
                $this->nodes[$i] = $result;
            }
        }
    }
}
