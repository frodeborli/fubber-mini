<?php
/**
 * Test PerItemTriggers - one-time per source (string or object)
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Hooks\PerItemTriggers;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - PerItemTriggers is standalone
    }

    // ========================================
    // String sources - basic behavior
    // ========================================

    public function testTriggerForStringSources(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = [];

        $triggers->listen(function($source, $data) use (&$received) {
            $received[] = [$source, $data];
        });

        $triggers->triggerFor('user:1', 'data1');
        $triggers->triggerFor('user:2', 'data2');

        $this->assertSame([
            ['user:1', 'data1'],
            ['user:2', 'data2'],
        ], $received);
    }

    public function testWasTriggeredForString(): void
    {
        $triggers = new PerItemTriggers('test');

        $this->assertFalse($triggers->wasTriggeredFor('source'));

        $triggers->triggerFor('source', 'data');

        $this->assertTrue($triggers->wasTriggeredFor('source'));
    }

    public function testSecondTriggerForSameStringThrows(): void
    {
        $triggers = new PerItemTriggers('test');
        $triggers->triggerFor('source', 'data');

        $this->assertThrows(
            fn() => $triggers->triggerFor('source', 'other'),
            \LogicException::class
        );
    }

    // ========================================
    // Object sources - basic behavior
    // ========================================

    public function testTriggerForObjectSources(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = [];

        $obj1 = new \stdClass();
        $obj2 = new \stdClass();

        $triggers->listen(function($source, $data) use (&$received) {
            $received[] = [$source, $data];
        });

        $triggers->triggerFor($obj1, 'data1');
        $triggers->triggerFor($obj2, 'data2');

        $this->assertSame($obj1, $received[0][0]);
        $this->assertSame('data1', $received[0][1]);
        $this->assertSame($obj2, $received[1][0]);
        $this->assertSame('data2', $received[1][1]);
    }

    public function testWasTriggeredForObject(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj = new \stdClass();

        $this->assertFalse($triggers->wasTriggeredFor($obj));

        $triggers->triggerFor($obj, 'data');

        $this->assertTrue($triggers->wasTriggeredFor($obj));
    }

    public function testSecondTriggerForSameObjectThrows(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj = new \stdClass();

        $triggers->triggerFor($obj, 'data');

        $this->assertThrows(
            fn() => $triggers->triggerFor($obj, 'other'),
            \LogicException::class
        );
    }

    // ========================================
    // listenFor - source-specific listeners
    // ========================================

    public function testListenForStringBeforeTrigger(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = null;

        $triggers->listenFor('source', function($src, $data) use (&$received) {
            $received = $data;
        });

        // Not called yet
        $this->assertNull($received);

        $triggers->triggerFor('source', 'value');

        // Now called
        $this->assertSame('value', $received);
    }

    public function testListenForStringAfterTrigger(): void
    {
        $triggers = new PerItemTriggers('test');
        $triggers->triggerFor('source', 'stored-value');

        $received = null;

        // Late subscriber - called immediately
        $triggers->listenFor('source', function($src, $data) use (&$received) {
            $received = $data;
        });

        $this->assertSame('stored-value', $received);
    }

    public function testListenForObjectBeforeTrigger(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj = new \stdClass();
        $received = null;

        $triggers->listenFor($obj, function($src, $data) use (&$received) {
            $received = $data;
        });

        // Not called yet
        $this->assertNull($received);

        $triggers->triggerFor($obj, 'value');

        // Now called
        $this->assertSame('value', $received);
    }

    public function testListenForObjectAfterTrigger(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj = new \stdClass();

        $triggers->triggerFor($obj, 'stored-value');

        $received = null;

        // Late subscriber - called immediately
        $triggers->listenFor($obj, function($src, $data) use (&$received) {
            $received = $data;
        });

        $this->assertSame('stored-value', $received);
    }

    // ========================================
    // Global vs source-specific listeners
    // ========================================

    public function testGlobalListenerReceivesAllSources(): void
    {
        $triggers = new PerItemTriggers('test');
        $calls = [];

        $triggers->listen(function($source) use (&$calls) {
            $calls[] = $source;
        });

        $triggers->triggerFor('a');
        $triggers->triggerFor('b');
        $triggers->triggerFor('c');

        $this->assertSame(['a', 'b', 'c'], $calls);
    }

    public function testSourceSpecificListenerOnlyReceivesMatchingSource(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = null;

        $triggers->listenFor('target', function($src, $data) use (&$received) {
            $received = $data;
        });

        $triggers->triggerFor('other', 'wrong');  // Doesn't match
        $this->assertNull($received);

        $triggers->triggerFor('target', 'correct');
        $this->assertSame('correct', $received);
    }

    public function testBothGlobalAndSourceSpecificCalled(): void
    {
        $triggers = new PerItemTriggers('test');
        $calls = [];

        $triggers->listen(function($source) use (&$calls) {
            $calls[] = "global:$source";
        });

        $triggers->listenFor('x', function($source) use (&$calls) {
            $calls[] = "specific:$source";
        });

        $triggers->triggerFor('x');

        $this->assertSame(['global:x', 'specific:x'], $calls);
    }

    // ========================================
    // Multiple arguments
    // ========================================

    public function testMultipleArguments(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = null;

        $triggers->listen(function($source, $a, $b, $c) use (&$received) {
            $received = [$a, $b, $c];
        });

        $triggers->triggerFor('source', 1, 2, 3);

        $this->assertSame([1, 2, 3], $received);
    }

    public function testLateSubscriberReceivesOriginalArguments(): void
    {
        $triggers = new PerItemTriggers('test');
        $triggers->triggerFor('source', 'arg1', 'arg2');

        $received = null;

        $triggers->listenFor('source', function($src, $a, $b) use (&$received) {
            $received = [$a, $b];
        });

        $this->assertSame(['arg1', 'arg2'], $received);
    }

    // ========================================
    // Multiple listeners per source
    // ========================================

    public function testMultipleListenersForSameSource(): void
    {
        $triggers = new PerItemTriggers('test');
        $calls = [];

        $triggers->listenFor('source', function() use (&$calls) {
            $calls[] = 'first';
        });

        $triggers->listenFor('source', function() use (&$calls) {
            $calls[] = 'second';
        });

        $triggers->triggerFor('source');

        $this->assertSame(['first', 'second'], $calls);
    }

    public function testMultipleLateSubscribersAllCalled(): void
    {
        $triggers = new PerItemTriggers('test');
        $triggers->triggerFor('source', 'data');

        $calls = [];

        $triggers->listenFor('source', function() use (&$calls) {
            $calls[] = 'late1';
        });

        $triggers->listenFor('source', function() use (&$calls) {
            $calls[] = 'late2';
        });

        $this->assertSame(['late1', 'late2'], $calls);
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesGlobalListener(): void
    {
        $triggers = new PerItemTriggers('test');
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $triggers->listen($listener);
        $triggers->off($listener);
        $triggers->triggerFor('source');

        $this->assertFalse($called);
    }

    public function testOffRemovesSourceSpecificStringListener(): void
    {
        $triggers = new PerItemTriggers('test');
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $triggers->listenFor('source', $listener);
        $triggers->off($listener);
        $triggers->triggerFor('source');

        $this->assertFalse($called);
    }

    public function testOffRemovesSourceSpecificObjectListener(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj = new \stdClass();
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $triggers->listenFor($obj, $listener);
        $triggers->off($listener);
        $triggers->triggerFor($obj);

        $this->assertFalse($called);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testTriggerWithNoListeners(): void
    {
        $triggers = new PerItemTriggers('test');

        // Should not throw
        $triggers->triggerFor('source', 'data');

        $this->assertTrue($triggers->wasTriggeredFor('source'));
    }

    public function testTriggerWithNoData(): void
    {
        $triggers = new PerItemTriggers('test');
        $received = 'not-called';

        $triggers->listen(function($source) use (&$received) {
            $received = $source;
        });

        $triggers->triggerFor('source');

        $this->assertSame('source', $received);
    }

    public function testDifferentStringsAreDifferentSources(): void
    {
        $triggers = new PerItemTriggers('test');

        $triggers->triggerFor('a');
        $triggers->triggerFor('b');

        $this->assertTrue($triggers->wasTriggeredFor('a'));
        $this->assertTrue($triggers->wasTriggeredFor('b'));
        $this->assertFalse($triggers->wasTriggeredFor('c'));
    }

    public function testDifferentObjectsAreDifferentSources(): void
    {
        $triggers = new PerItemTriggers('test');
        $obj1 = new \stdClass();
        $obj2 = new \stdClass();
        $obj3 = new \stdClass();

        $triggers->triggerFor($obj1);
        $triggers->triggerFor($obj2);

        $this->assertTrue($triggers->wasTriggeredFor($obj1));
        $this->assertTrue($triggers->wasTriggeredFor($obj2));
        $this->assertFalse($triggers->wasTriggeredFor($obj3));
    }

    public function testListenAcceptsMultipleClosures(): void
    {
        $triggers = new PerItemTriggers('test');
        $calls = [];

        $triggers->listen(
            function($src) use (&$calls) { $calls[] = 1; },
            function($src) use (&$calls) { $calls[] = 2; }
        );

        $triggers->triggerFor('source');

        $this->assertSame([1, 2], $calls);
    }

    public function testListenForAcceptsMultipleClosures(): void
    {
        $triggers = new PerItemTriggers('test');
        $calls = [];

        $triggers->listenFor(
            'source',
            function($src) use (&$calls) { $calls[] = 1; },
            function($src) use (&$calls) { $calls[] = 2; }
        );

        $triggers->triggerFor('source');

        $this->assertSame([1, 2], $calls);
    }
};

exit($test->run());
