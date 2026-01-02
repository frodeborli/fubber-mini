<?php
/**
 * Test Trigger dispatcher - one-time event with memory
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Hooks\Trigger;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - Trigger is standalone
    }

    // ========================================
    // Basic trigger behavior
    // ========================================

    public function testListenerReceivesArguments(): void
    {
        $trigger = new Trigger('test');
        $received = null;

        $trigger->listen(function($a, $b) use (&$received) {
            $received = [$a, $b];
        });

        $trigger->trigger('hello', 42);

        $this->assertSame(['hello', 42], $received);
    }

    public function testMultipleListenersAllCalled(): void
    {
        $trigger = new Trigger('test');
        $calls = [];

        $trigger->listen(function() use (&$calls) {
            $calls[] = 'first';
        });

        $trigger->listen(function() use (&$calls) {
            $calls[] = 'second';
        });

        $trigger->trigger();

        $this->assertSame(['first', 'second'], $calls);
    }

    // ========================================
    // One-time behavior
    // ========================================

    public function testSecondTriggerThrows(): void
    {
        $trigger = new Trigger('test');
        $trigger->trigger();

        $this->assertThrows(
            fn() => $trigger->trigger(),
            \LogicException::class
        );
    }

    public function testWasTriggeredReturnsFalseInitially(): void
    {
        $trigger = new Trigger('test');

        $this->assertFalse($trigger->wasTriggered());
    }

    public function testWasTriggeredReturnsTrueAfterTrigger(): void
    {
        $trigger = new Trigger('test');
        $trigger->trigger();

        $this->assertTrue($trigger->wasTriggered());
    }

    // ========================================
    // Late subscriber behavior
    // ========================================

    public function testLateSubscriberCalledImmediately(): void
    {
        $trigger = new Trigger('test');
        $trigger->trigger('data', 123);

        $received = null;
        $trigger->listen(function($a, $b) use (&$received) {
            $received = [$a, $b];
        });

        // Should have been called immediately
        $this->assertSame(['data', 123], $received);
    }

    public function testMultipleLateSubscribers(): void
    {
        $trigger = new Trigger('test');
        $trigger->trigger('value');

        $calls = [];

        $trigger->listen(function($v) use (&$calls) {
            $calls[] = "first:$v";
        });

        $trigger->listen(function($v) use (&$calls) {
            $calls[] = "second:$v";
        });

        $this->assertSame(['first:value', 'second:value'], $calls);
    }

    public function testLateSubscriberReceivesOriginalData(): void
    {
        $trigger = new Trigger('test');
        $originalData = ['key' => 'value', 'nested' => [1, 2, 3]];
        $trigger->trigger($originalData);

        $received = null;
        $trigger->listen(function($data) use (&$received) {
            $received = $data;
        });

        $this->assertSame($originalData, $received);
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesListenerBeforeTrigger(): void
    {
        $trigger = new Trigger('test');
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $trigger->listen($listener);
        $trigger->off($listener);
        $trigger->trigger();

        $this->assertFalse($called);
    }

    public function testOffHasNoEffectAfterTrigger(): void
    {
        $trigger = new Trigger('test');
        $called = false;

        $listener = function() use (&$called) {
            $called = true;
        };

        $trigger->listen($listener);
        $trigger->trigger();

        // Listener was already called, off() just cleans up
        $trigger->off($listener);

        $this->assertTrue($called);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testTriggerWithNoListeners(): void
    {
        $trigger = new Trigger('test');

        // Should not throw
        $trigger->trigger('some', 'args');

        $this->assertTrue($trigger->wasTriggered());
    }

    public function testTriggerWithNoArguments(): void
    {
        $trigger = new Trigger('test');
        $called = false;

        $trigger->listen(function() use (&$called) {
            $called = true;
        });

        $trigger->trigger();

        $this->assertTrue($called);
    }

    public function testListenersCleanedAfterTrigger(): void
    {
        $trigger = new Trigger('test');
        $count = 0;

        $trigger->listen(function() use (&$count) {
            $count++;
        });

        $trigger->trigger();

        // Late subscriber
        $trigger->listen(function() use (&$count) {
            $count++;
        });

        // Both should have been called exactly once
        $this->assertSame(2, $count);
    }
};

exit($test->run());
