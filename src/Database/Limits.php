<?php

namespace mini\Database;

/**
 * Deliberate boundaries on what VirtualDatabase will attempt
 *
 * The engine is built to run *sensible* SQL over heterogeneous sources from
 * PHP — a CSV file, a JSON document, a remote API and a database table joined
 * in one query. It is not built to be pushed to the edge, and it is not a
 * general-purpose RDBMS. These limits state that scope in code.
 *
 * The point is failure mode. Without them a pathological query does not
 * politely underperform: it consumes the PHP process, and in a Fiber-based
 * runtime it takes every other coroutine in that worker down with it. A query
 * that exceeds a limit fails immediately with an error naming the limit and
 * how to raise it — which is a bug report, not an outage.
 *
 * Every limit can be raised, so nothing here is a ceiling on what you may do
 * with your own data. They are defaults chosen for the workload the engine is
 * for. If you find yourself raising several of them at once, that is a signal
 * the work belongs in a real database that you register as a source.
 *
 * ```php
 * $vdb->setLimits(new Limits(maxJoinedTables: 12));
 * ```
 */
final class Limits
{
    /**
     * @param int $maxJoinedTables Tables permitted in a single query: the FROM
     *        table plus every join, counted before a join strategy is chosen, so
     *        the bound holds for every spelling (INNER, outer, CROSS, NATURAL,
     *        USING and comma-separated FROM) and inside subqueries. Join
     *        planning cost grows sharply with table count, and a query
     *        federating this many distinct sources is usually a sign the work
     *        belongs in a database rather than in the engine.
     * @param int $maxSubqueryDepth How deeply subqueries and CTEs may nest.
     *        Bounds recursion in the planner and evaluator, and stops a
     *        deliberately nested query from exhausting the PHP stack.
     * @param int $maxRecursionIterations Fixpoint iterations for a recursive
     *        CTE before it is declared non-terminating. Exceeding this throws
     *        rather than returning an arbitrary prefix of an unbounded result,
     *        because a silently truncated answer is worse than an error.
     * @param int|null $maxBufferedWrites Rows a single statement may buffer
     *        before applying them. Writes are deferred until a statement
     *        finishes reading (see PendingWrites), so this bounds the memory
     *        that deferral costs. Null disables the cap.
     */
    public function __construct(
        public readonly int $maxJoinedTables = 8,
        public readonly int $maxSubqueryDepth = 8,
        public readonly int $maxRecursionIterations = 10_000,
        public readonly ?int $maxBufferedWrites = 1_000_000,
    ) {
        $this->requirePositive('maxJoinedTables', $maxJoinedTables);
        $this->requirePositive('maxSubqueryDepth', $maxSubqueryDepth);
        $this->requirePositive('maxRecursionIterations', $maxRecursionIterations);

        if ($maxBufferedWrites !== null) {
            $this->requirePositive('maxBufferedWrites', $maxBufferedWrites);
        }
    }

    private function requirePositive(string $name, int $value): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException(
                "Limits::\$$name must be at least 1, got $value"
                . ($name === 'maxBufferedWrites' ? ' (pass null to disable the cap)' : '')
            );
        }
    }
}
