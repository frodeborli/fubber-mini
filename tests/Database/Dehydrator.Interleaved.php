<?php
/**
 * Dehydrator::hydrate() while another coroutine hydrates the same class
 *
 * The invariant: hydrating an entity is not observable by a hydration running at the same time.
 * A Hydratable whose fromSqlRow() waits (a query, a lazy load) is hydrated through fromSqlRow()
 * for every row, whichever coroutines are in the middle of it.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Database\Dehydrator;
use mini\Database\Hydratable;
use mini\Test;

final class InterleavedEntity implements Hydratable
{
    public string $name = '';
    public bool $custom = false;

    public static function fromSqlRow(object $row): static
    {
        \Fiber::suspend(); // waits, as a fromSqlRow() that runs a query would
        $entity = Dehydrator::hydrateReflection($row, static::class, false);
        $entity->custom = true;

        return $entity;
    }
}

$test = new class extends Test {

    public function testConcurrentHydrationOfOneClassUsesFromSqlRowForEach(): void
    {
        $results = [];
        $fibers  = [];
        foreach (['a', 'b'] as $name) {
            $fibers[$name] = new \Fiber(function () use ($name, &$results): void {
                $results[$name] = Dehydrator::hydrate((object) ['name' => $name], InterleavedEntity::class);
            });
        }
        // Both are inside fromSqlRow() before either finishes
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        foreach ($fibers as $fiber) {
            $fiber->resume();
        }

        $this->assertSame('a', $results['a']->name);
        $this->assertSame('b', $results['b']->name);
        $this->assertTrue($results['a']->custom, 'first coroutine went through fromSqlRow()');
        $this->assertTrue($results['b']->custom, 'second coroutine went through fromSqlRow() too');
    }
};

exit($test->run());
