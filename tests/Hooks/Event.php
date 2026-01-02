<?php
/**
 * Test Event dispatcher - multi-fire, multiple listeners
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Hooks\Event;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - Event is standalone
    }

    // ========================================
    // Basic listen and trigger
    // ========================================

    public function testListenerReceivesArguments(): void
    {
        $event = new Event('test');
        $received = null;

        $event->listen(function($a, $b) use (&$received) {
            $received = [$a, $b];
        });

        $event->trigger('hello', 42);

        $this->assertSame(['hello', 42], $received);
    }

    public function testMultipleListenersAllCalled(): void
    {
        $event = new Event('test');
        $calls = [];

        $event->listen(function() use (&$calls) {
            $calls[] = 'first';
        });

        $event->listen(function() use (&$calls) {
            $calls[] = 'second';
        });

        $event->listen(function() use (&$calls) {
            $calls[] = 'third';
        });

        $event->trigger();

        $this->assertSame(['first', 'second', 'third'], $calls);
    }

    public function testCanTriggerMultipleTimes(): void
    {
        $event = new Event('test');
        $count = 0;

        $event->listen(function() use (&$count) {
            $count++;
        });

        $event->trigger();
        $event->trigger();
        $event->trigger();

        $this->assertSame(3, $count);
    }

    // ========================================
    // once() - auto-unsubscribe
    // ========================================

    public function testOnceListenerCalledOnlyOnce(): void
    {
        $event = new Event('test');
        $count = 0;

        $event->once(function() use (&$count) {
            $count++;
        });

        $event->trigger();
        $event->trigger();
        $event->trigger();

        $this->assertSame(1, $count);
    }

    public function testOnceAndRegularListenersTogether(): void
    {
        $event = new Event('test');
        $onceCalls = 0;
        $regularCalls = 0;

        $event->once(function() use (&$onceCalls) {
            $onceCalls++;
        });

        $event->listen(function() use (&$regularCalls) {
            $regularCalls++;
        });

        $event->trigger();
        $event->trigger();

        $this->assertSame(1, $onceCalls);
        $this->assertSame(2, $regularCalls);
    }

    public function testMultipleOnceListeners(): void
    {
        $event = new Event('test');
        $calls = [];

        $event->once(function() use (&$calls) {
            $calls[] = 'once1';
        });

        $event->once(function() use (&$calls) {
            $calls[] = 'once2';
        });

        $event->trigger();
        $event->trigger();

        $this->assertSame(['once1', 'once2'], $calls);
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesListener(): void
    {
        $event = new Event('test');
        $count = 0;

        $listener = function() use (&$count) {
            $count++;
        };

        $event->listen($listener);
        $event->trigger();  // count = 1

        $event->off($listener);
        $event->trigger();  // count still 1

        $this->assertSame(1, $count);
    }

    public function testOffRemovesOnceListener(): void
    {
        $event = new Event('test');
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $event->once($listener);
        $event->off($listener);
        $event->trigger();

        $this->assertFalse($called);
    }

    public function testOffWithMultipleListeners(): void
    {
        $event = new Event('test');
        $calls = [];

        $listener1 = function() use (&$calls) { $calls[] = 1; };
        $listener2 = function() use (&$calls) { $calls[] = 2; };
        $listener3 = function() use (&$calls) { $calls[] = 3; };

        $event->listen($listener1, $listener2, $listener3);
        $event->off($listener2);
        $event->trigger();

        $this->assertSame([1, 3], $calls);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testTriggerWithNoListeners(): void
    {
        $event = new Event('test');

        // Should not throw
        $event->trigger('some', 'args');

        $this->assertTrue(true);  // Reached here without exception
    }

    public function testListenAcceptsMultipleClosures(): void
    {
        $event = new Event('test');
        $calls = [];

        $event->listen(
            function() use (&$calls) { $calls[] = 1; },
            function() use (&$calls) { $calls[] = 2; }
        );

        $event->trigger();

        $this->assertSame([1, 2], $calls);
    }

    public function testOnceAcceptsMultipleClosures(): void
    {
        $event = new Event('test');
        $calls = [];

        $event->once(
            function() use (&$calls) { $calls[] = 1; },
            function() use (&$calls) { $calls[] = 2; }
        );

        $event->trigger();
        $event->trigger();

        $this->assertSame([1, 2], $calls);  // Only called once each
    }
};

exit($test->run());
