<?php

declare(strict_types=1);

namespace mini\Html;

abstract class Node implements \Stringable
{
    protected ?Element $parent = null;

    public function parentNode(): ?Node
    {
        return $this->parent;
    }

    public function parentElement(): ?Element
    {
        return $this->parent;
    }

    public function getRootNode(): Node
    {
        $node = $this;
        while ($node->parent !== null) {
            $node = $node->parent;
        }
        return $node;
    }

    public function contains(Node $other): bool
    {
        return $other === $this;
    }

    public function firstChild(): ?Node
    {
        return null;
    }

    public function lastChild(): ?Node
    {
        return null;
    }

    /** @return Node[] */
    public function childNodes(): array
    {
        return [];
    }

    public function nextSibling(): ?Node
    {
        if ($this->parent === null) {
            return null;
        }
        $siblings = $this->parent->childNodes();
        $count = count($siblings);
        for ($i = 0; $i < $count; $i++) {
            if ($siblings[$i] === $this) {
                return $siblings[$i + 1] ?? null;
            }
        }
        return null;
    }

    public function previousSibling(): ?Node
    {
        if ($this->parent === null) {
            return null;
        }
        $siblings = $this->parent->childNodes();
        for ($i = 0, $count = count($siblings); $i < $count; $i++) {
            if ($siblings[$i] === $this) {
                return $i > 0 ? $siblings[$i - 1] : null;
            }
        }
        return null;
    }
}
