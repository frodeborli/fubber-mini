<?php

namespace mini\Contracts;

/**
 * A coroutine context that works on behalf of another request scope
 *
 * Under phasync, the request scope (`Mini::getRequestScope()`) is the current coroutine's
 * context. A runtime that gives some of a request's coroutines contexts of their own, to cancel
 * them apart, implements this on those contexts: they then share the request scope it names,
 * with its Scoped services and its request.
 *
 * ```php
 * final class PartContext implements \phasync\Context\ContextInterface, RequestScopeProviderInterface
 * {
 *     use \phasync\Context\ContextTrait;
 *
 *     public function __construct(private object $scope) {}
 *
 *     public function getRequestScope(): object
 *     {
 *         return $this->scope;
 *     }
 * }
 * ```
 */
interface RequestScopeProviderInterface
{
    /** The request scope this context belongs to */
    public function getRequestScope(): object;
}
