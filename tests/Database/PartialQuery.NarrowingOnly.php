<?php
/**
 * Regression tests: a PartialQuery can only be narrowed, never widened
 *
 * PartialQuery is documented as an access-control primitive — "pass a
 * PartialQuery into a template instead of materialising rows, and downstream
 * code cannot widen the result set, it can only narrow" (README, CLAUDE.md).
 * Every method on it must honour that, including with hostile arguments.
 *
 * Two ways a negative argument broke the guarantee, both found by porting the
 * engine to Python and re-auditing the capability surface:
 *
 *   $scoped = $q->limit(2);   // a capability: at most 2 rows
 *   $scoped->limit(-1);       // rendered LIMIT -1, which SQLite reads as
 *                             // "no limit at all" -> all 10 rows
 *   $scoped->offset(-5);      // offset is additive and reduces the limit by
 *                             // the amount offset; a negative one RAISED the
 *                             // limit -> 7 rows
 *
 * A narrowing operation that can widen is not a capability, so both now throw
 * rather than silently producing a wider query.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Database\Query;
use mini\Database\PartialQuery;

$test = new class extends Test {

    private static ?\ReflectionProperty $pqRef = null;

    protected function setUp(): void
    {
        \mini\bootstrap();
        \mini\db()->exec('DROP TABLE IF EXISTS narrowing');
        \mini\db()->exec('CREATE TABLE narrowing (id INTEGER PRIMARY KEY)');
        for ($i = 1; $i <= 10; $i++) {
            \mini\db()->exec("INSERT INTO narrowing VALUES ($i)");
        }
    }

    private function base(): PartialQuery
    {
        if (self::$pqRef === null) {
            self::$pqRef = new \ReflectionProperty(Query::class, 'pq');
        }
        return self::$pqRef->getValue(\mini\db()->query('SELECT * FROM narrowing'));
    }

    public function testNegativeLimitIsRejected(): void
    {
        // LIMIT -1 renders as SQLite's "unlimited" spelling, so accepting it
        // would turn a 2-row capability into an unbounded one.
        $this->assertThrows(
            fn() => $this->base()->limit(2)->limit(-1),
            \InvalidArgumentException::class
        );
    }

    public function testNegativeOffsetIsRejected(): void
    {
        // Offset is additive and shrinks the limit to stay inside the window;
        // a negative offset runs that arithmetic backwards and grows it.
        $this->assertThrows(
            fn() => $this->base()->limit(2)->offset(-5),
            \InvalidArgumentException::class
        );
    }

    public function testNegativeLimitRejectedOnAnUnboundedQueryToo(): void
    {
        // Even with no existing limit, LIMIT -1 must not be rendered
        $this->assertThrows(
            fn() => $this->base()->limit(-1),
            \InvalidArgumentException::class
        );
    }

    public function testScopeSurvivesHostileArguments(): void
    {
        // The guarantee stated positively: whatever a caller does with these
        // methods, the row count never exceeds the capability it was given.
        $scoped = $this->base()->limit(2);
        $this->assertCount(2, iterator_to_array($scoped));

        foreach ([0, 1, 2, 5, 100] as $n) {
            $this->assertLessThanOrEqual(
                2,
                count(iterator_to_array($scoped->limit($n))),
                "limit($n) on a 2-row capability must not widen it"
            );
        }
        foreach ([0, 1, 2, 5, 100] as $n) {
            $this->assertLessThanOrEqual(
                2,
                count(iterator_to_array($scoped->offset($n))),
                "offset($n) on a 2-row capability must not widen it"
            );
        }
    }

    public function testLegitimateNarrowingStillWorks(): void
    {
        $scoped = $this->base()->limit(5);
        $this->assertCount(3, iterator_to_array($scoped->limit(3)));
        $this->assertCount(5, iterator_to_array($scoped->limit(50)), 'limit() can only shrink');
        $this->assertCount(3, iterator_to_array($scoped->offset(2)), 'offset stays within the window');
        $this->assertCount(0, iterator_to_array($scoped->offset(5)), 'offset past the window is empty');
    }

    public function testZeroIsAcceptedAsALegitimateNarrowing(): void
    {
        $this->assertCount(0, iterator_to_array($this->base()->limit(0)));
        $this->assertCount(10, iterator_to_array($this->base()->offset(0)));
    }
};

exit($test->run());
