<?php

declare(strict_types=1);

namespace mini\Html;

class Text extends Node
{
    public function __construct(
        private string $content,
    ) {}

    public function __toString(): string
    {
        return htmlspecialchars($this->content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
