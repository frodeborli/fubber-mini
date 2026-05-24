<?php

namespace mini\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Marks a class as something that can produce a PSR-7 response without
 * implementing the full `ResponseInterface` contract.
 *
 * Mirrors PHP's `IteratorAggregate` pattern: rather than implementing every
 * method of `Iterator`, a class implements `getIterator()` and the runtime
 * uses the returned iterator. Likewise, a `ResponseAggregate` implements one
 * method that returns the actual response. The Router resolves the aggregate
 * to its `ResponseInterface` before continuing the dispatch chain — so any
 * place that handles a `ResponseInterface` also handles a `ResponseAggregate`.
 *
 * Typical use case: a class that conceptually represents a response but
 * doesn't want to be locked into eager construction or `Response` inheritance.
 *
 * ```php
 * class MarkdownPage implements ResponseAggregate
 * {
 *     public function __construct(private readonly string $filePath) {}
 *
 *     public function getResponse(): ResponseInterface
 *     {
 *         $html = $this->renderMarkdown($this->filePath);
 *         return new \mini\Http\Message\HtmlResponse($html);
 *     }
 * }
 *
 * // _routes/terms.php
 * return new MarkdownPage('docs/terms-and-conditions.md');
 * ```
 *
 * `getResponse()` is called only when the dispatcher needs the response —
 * construction stays cheap.
 *
 * Implementations should return a concrete `ResponseInterface`, not another
 * `ResponseAggregate`. The dispatch chain is single-level by design.
 */
interface ResponseAggregate
{
    public function getResponse(): ResponseInterface;
}
