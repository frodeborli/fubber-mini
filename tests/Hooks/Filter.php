<?php
/**
 * Test Filter dispatcher - transform data through pipeline
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Test;
use mini\Hooks\Filter;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - Filter is standalone
    }

    // ========================================
    // Basic filter behavior
    // ========================================

    public function testFilterPassesThroughSingleListener(): void
    {
        $filter = new Filter('test');

        $filter->listen(function($value) {
            return strtoupper($value);
        });

        $result = $filter->filter('hello');

        $this->assertSame('HELLO', $result);
    }

    public function testFilterChainsThroughMultipleListeners(): void
    {
        $filter = new Filter('test');

        $filter->listen(function($value) {
            return $value . '-first';
        });

        $filter->listen(function($value) {
            return $value . '-second';
        });

        $filter->listen(function($value) {
            return $value . '-third';
        });

        $result = $filter->filter('start');

        $this->assertSame('start-first-second-third', $result);
    }

    public function testFilterReceivesExtraArguments(): void
    {
        $filter = new Filter('test');
        $received = null;

        $filter->listen(function($value, $arg1, $arg2) use (&$received) {
            $received = [$arg1, $arg2];
            return $value;
        });

        $filter->filter('value', 'extra1', 'extra2');

        $this->assertSame(['extra1', 'extra2'], $received);
    }

    public function testFilterReturnsOriginalValueWithNoListeners(): void
    {
        $filter = new Filter('test');

        $result = $filter->filter('original');

        $this->assertSame('original', $result);
    }

    // ========================================
    // Pipeline transformation
    // ========================================

    public function testFilterCanTransformTypes(): void
    {
        $filter = new Filter('test');

        // String to array
        $filter->listen(function($value) {
            return str_split($value);
        });

        // Array to count
        $filter->listen(function($value) {
            return count($value);
        });

        $result = $filter->filter('hello');

        $this->assertSame(5, $result);
    }

    public function testFilterOrderMatters(): void
    {
        $filter = new Filter('test');

        // Order: multiply then add
        $filter->listen(fn($v) => $v * 2);
        $filter->listen(fn($v) => $v + 3);

        // (5 * 2) + 3 = 13
        $this->assertSame(13, $filter->filter(5));
    }

    public function testFilterReverseOrderGivesDifferentResult(): void
    {
        $filter = new Filter('test');

        // Order: add then multiply
        $filter->listen(fn($v) => $v + 3);
        $filter->listen(fn($v) => $v * 2);

        // (5 + 3) * 2 = 16
        $this->assertSame(16, $filter->filter(5));
    }

    // ========================================
    // Multiple filter calls
    // ========================================

    public function testCanFilterMultipleTimes(): void
    {
        $filter = new Filter('test');

        $filter->listen(fn($v) => $v . '-filtered');

        $this->assertSame('a-filtered', $filter->filter('a'));
        $this->assertSame('b-filtered', $filter->filter('b'));
        $this->assertSame('c-filtered', $filter->filter('c'));
    }

    public function testFiltersAreStateless(): void
    {
        $filter = new Filter('test');
        $count = 0;

        $filter->listen(function($v) use (&$count) {
            $count++;
            return $v . "-$count";
        });

        // Each call sees a different count, but the filter itself is stateless
        $this->assertSame('a-1', $filter->filter('a'));
        $this->assertSame('b-2', $filter->filter('b'));
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesListener(): void
    {
        $filter = new Filter('test');

        $listener = fn($v) => $v . '-removed';

        $filter->listen($listener);
        $filter->off($listener);

        $result = $filter->filter('test');

        $this->assertSame('test', $result);
    }

    public function testOffWithMultipleListeners(): void
    {
        $filter = new Filter('test');

        $listener1 = fn($v) => $v . '-1';
        $listener2 = fn($v) => $v . '-2';
        $listener3 = fn($v) => $v . '-3';

        $filter->listen($listener1, $listener2, $listener3);
        $filter->off($listener2);

        $result = $filter->filter('start');

        $this->assertSame('start-1-3', $result);
    }

    public function testOffDoesNotAffectOtherListeners(): void
    {
        $filter = new Filter('test');

        $listener1 = fn($v) => $v . '-kept';
        $listener2 = fn($v) => $v . '-removed';

        $filter->listen($listener1);
        $filter->listen($listener2);
        $filter->off($listener2);

        $result = $filter->filter('test');

        $this->assertSame('test-kept', $result);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testFilterCanReturnNull(): void
    {
        $filter = new Filter('test');

        $filter->listen(fn($v) => null);

        $result = $filter->filter('test');

        $this->assertNull($result);
    }

    public function testFilterCanReturnFalse(): void
    {
        $filter = new Filter('test');

        $filter->listen(fn($v) => false);

        $result = $filter->filter(true);

        $this->assertFalse($result);
    }

    public function testFilterCanTransformArrays(): void
    {
        $filter = new Filter('test');

        $filter->listen(fn($arr) => array_map(fn($x) => $x * 2, $arr));
        $filter->listen(fn($arr) => array_filter($arr, fn($x) => $x > 5));
        $filter->listen(fn($arr) => array_values($arr));

        $result = $filter->filter([1, 2, 3, 4, 5]);
        // [2, 4, 6, 8, 10] -> [6, 8, 10] -> [6, 8, 10]

        $this->assertSame([6, 8, 10], $result);
    }

    public function testFilterCanTransformObjects(): void
    {
        $filter = new Filter('test');

        $filter->listen(function($obj) {
            $obj->modified = true;
            return $obj;
        });

        $obj = new \stdClass();
        $obj->original = true;

        $result = $filter->filter($obj);

        $this->assertTrue($result->original);
        $this->assertTrue($result->modified);
    }

    public function testListenAcceptsMultipleClosures(): void
    {
        $filter = new Filter('test');

        $filter->listen(
            fn($v) => $v . '-a',
            fn($v) => $v . '-b'
        );

        $result = $filter->filter('start');

        $this->assertSame('start-a-b', $result);
    }

    public function testFilterWithContextArguments(): void
    {
        $filter = new Filter('test');

        $filter->listen(function($html, $context) {
            if ($context['minify']) {
                return preg_replace('/\s+/', ' ', $html);
            }
            return $html;
        });

        $html = "Hello   World\n\nTest";

        $minified = $filter->filter($html, ['minify' => true]);
        $original = $filter->filter($html, ['minify' => false]);

        $this->assertSame('Hello World Test', $minified);
        $this->assertSame($html, $original);
    }
};

exit($test->run());
