<?php

declare(strict_types=1);

namespace mini\Html;

class Element extends Node
{
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const BOOLEAN_ATTRIBUTES = [
        'allowfullscreen', 'async', 'autofocus', 'autoplay', 'checked',
        'controls', 'default', 'defer', 'disabled', 'formnovalidate',
        'hidden', 'inert', 'ismap', 'loop', 'multiple', 'muted',
        'nomodule', 'novalidate', 'open', 'playsinline', 'readonly',
        'required', 'reversed', 'selected',
    ];

    /** @var array<string, string|bool> */
    private array $attributes;

    /** @var Node[] */
    private array $children = [];

    private bool $isVoid;

    /**
     * @param string $tag HTML tag name
     * @param array<string, string|bool|null> $attributes Element attributes
     * @param string|Node ...$children Child nodes (strings auto-wrap as Text)
     */
    public function __construct(
        private string $tag,
        array $attributes = [],
        string|Node ...$children,
    ) {
        $this->isVoid = in_array(strtolower($tag), self::VOID_ELEMENTS, true);
        $this->attributes = [];

        foreach ($attributes as $name => $value) {
            if ($value !== null && $value !== false) {
                $this->attributes[$name] = $value;
            }
        }

        foreach ($children as $child) {
            $node = is_string($child) ? new Text($child) : $child;
            $node->parent = $this;
            $this->children[] = $node;
        }
    }

    public function __get(string $name): string|bool|null
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, string|bool|null $value): void
    {
        if ($value === null || $value === false) {
            unset($this->attributes[$name]);
        } else {
            $this->attributes[$name] = $value;
        }
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

    /**
     * Add children after construction
     */
    public function append(string|Node ...$children): static
    {
        foreach ($children as $child) {
            $node = is_string($child) ? new Text($child) : $child;
            $node->parent = $this;
            $this->children[] = $node;
        }
        return $this;
    }

    public function appendChild(Node $node): Node
    {
        if ($node->parent !== null) {
            $node->parent->removeChild($node);
        }
        $node->parent = $this;
        $this->children[] = $node;
        return $node;
    }

    public function insertBefore(Node $newNode, ?Node $referenceNode): Node
    {
        if ($referenceNode === null) {
            return $this->appendChild($newNode);
        }
        $index = array_search($referenceNode, $this->children, true);
        if ($index === false) {
            throw new \InvalidArgumentException('Reference node is not a child of this element');
        }
        if ($newNode->parent !== null) {
            $newNode->parent->removeChild($newNode);
        }
        $newNode->parent = $this;
        array_splice($this->children, $index, 0, [$newNode]);
        return $newNode;
    }

    public function removeChild(Node $node): Node
    {
        $index = array_search($node, $this->children, true);
        if ($index === false) {
            throw new \InvalidArgumentException('Node is not a child of this element');
        }
        $node->parent = null;
        array_splice($this->children, $index, 1);
        return $node;
    }

    public function contains(Node $other): bool
    {
        if ($other === $this) {
            return true;
        }
        foreach ($this->children as $child) {
            if ($child === $other) {
                return true;
            }
            if ($child instanceof Element && $child->contains($other)) {
                return true;
            }
        }
        return false;
    }

    public function firstChild(): ?Node
    {
        return $this->children[0] ?? null;
    }

    public function lastChild(): ?Node
    {
        return $this->children[count($this->children) - 1] ?? null;
    }

    /** @return Node[] */
    public function childNodes(): array
    {
        return $this->children;
    }

    public function firstElementChild(): ?Element
    {
        foreach ($this->children as $child) {
            if ($child instanceof Element) {
                return $child;
            }
        }
        return null;
    }

    /** @return Element[] */
    public function children(): array
    {
        $elements = [];
        foreach ($this->children as $child) {
            if ($child instanceof Element) {
                $elements[] = $child;
            }
        }
        return $elements;
    }

    /**
     * Render just the opening tag (for begin/end pattern)
     */
    public function renderOpenTag(): string
    {
        return '<' . $this->tag . $this->renderAttributes() . '>';
    }

    public function __toString(): string
    {
        $html = $this->renderOpenTag();

        if ($this->isVoid) {
            return $html;
        }

        foreach ($this->children as $child) {
            $html .= (string) $child;
        }

        return $html . '</' . $this->tag . '>';
    }

    /**
     * @return Element[]
     */
    public function querySelectorAll(string $selector): array
    {
        $parsed = CssSelectorParser::parse($selector);
        $results = [];
        $seen = new \SplObjectStorage();

        foreach ($this->collectDescendants($this) as $descendant) {
            foreach ($parsed as $complex) {
                if ($this->matchesComplexSelector($descendant, $complex)) {
                    if (!$seen->contains($descendant)) {
                        $seen->attach($descendant);
                        $results[] = $descendant;
                    }
                    break;
                }
            }
        }

        return $results;
    }

    public function querySelector(string $selector): ?Element
    {
        $parsed = CssSelectorParser::parse($selector);

        foreach ($this->collectDescendants($this) as $descendant) {
            foreach ($parsed as $complex) {
                if ($this->matchesComplexSelector($descendant, $complex)) {
                    return $descendant;
                }
            }
        }

        return null;
    }

    public function getElementById(string $id): ?Element
    {
        return $this->querySelector('#' . $id);
    }

    /**
     * Test if an element matches a complex selector by walking ancestors right-to-left.
     * Per the DOM spec, the full document hierarchy is considered — ancestors above
     * the base element participate in matching.
     *
     * @param array<int, array{compound: array, combinator: ?string}> $segments
     */
    private function matchesComplexSelector(Element $element, array $segments): bool
    {
        $last = count($segments) - 1;

        if (!$element->matchesCompound($segments[$last]['compound'])) {
            return false;
        }

        $current = $element;
        for ($i = $last - 1; $i >= 0; $i--) {
            $combinator = $segments[$i + 1]['combinator'];

            if ($combinator === '>') {
                $current = $current->parentElement();
                if ($current === null || !$current->matchesCompound($segments[$i]['compound'])) {
                    return false;
                }
            } else {
                // Descendant combinator — walk up until an ancestor matches
                $current = $current->parentElement();
                while ($current !== null) {
                    if ($current->matchesCompound($segments[$i]['compound'])) {
                        break;
                    }
                    $current = $current->parentElement();
                }
                if ($current === null) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Collect all descendant elements in document order (depth-first).
     *
     * @return Element[]
     */
    private function collectDescendants(Element $root): array
    {
        $descendants = [];
        foreach ($root->children as $child) {
            if ($child instanceof Element) {
                $descendants[] = $child;
                array_push($descendants, ...$this->collectDescendants($child));
            }
        }
        return $descendants;
    }

    /**
     * Test if this element matches all simple selectors in a compound.
     *
     * @param array<int, array> $compound
     */
    private function matchesCompound(array $compound): bool
    {
        foreach ($compound as $simple) {
            switch ($simple[0]) {
                case 'universal':
                    break;
                case 'type':
                    if (strtolower($this->tag) !== strtolower($simple[1])) {
                        return false;
                    }
                    break;
                case 'id':
                    if (($this->attributes['id'] ?? null) !== $simple[1]) {
                        return false;
                    }
                    break;
                case 'class':
                    $classes = $this->attributes['class'] ?? '';
                    if (is_bool($classes)) {
                        return false;
                    }
                    if (!preg_match('/(?:^|\s)' . preg_quote($simple[1], '/') . '(?:\s|$)/', $classes)) {
                        return false;
                    }
                    break;
                case 'attr':
                    if (!isset($this->attributes[$simple[1]])) {
                        return false;
                    }
                    if (isset($simple[2])) {
                        $val = $this->attributes[$simple[1]];
                        if (is_bool($val)) {
                            $val = $val ? $simple[1] : '';
                        }
                        if ($val !== $simple[2]) {
                            return false;
                        }
                    }
                    break;
            }
        }
        return true;
    }

    private function renderAttributes(): string
    {
        $html = '';

        foreach ($this->attributes as $name => $value) {
            if ($value === true) {
                if (in_array(strtolower($name), self::BOOLEAN_ATTRIBUTES, true)) {
                    $html .= ' ' . $name;
                } else {
                    $html .= ' ' . $name . '="' . $name . '"';
                }
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }

        return $html;
    }
}
