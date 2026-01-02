<?php
/**
 * Test Handler dispatcher - first non-null response wins
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Hooks\Handler;

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - Handler is standalone
    }

    // ========================================
    // Basic listen and trigger
    // ========================================

    public function testListenerReceivesArguments(): void
    {
        $handler = new Handler('test');
        $received = null;

        $handler->listen(function($data, $extra) use (&$received) {
            $received = [$data, $extra];
            return 'handled';
        });

        $handler->trigger('value', 'arg');

        $this->assertSame(['value', 'arg'], $received);
    }

    public function testReturnsFirstNonNullResult(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return null;  // Can't handle
        });

        $handler->listen(function($data) {
            return "handled:$data";  // This one wins
        });

        $handler->listen(function($data) {
            return "never-reached";
        });

        $result = $handler->trigger('test');

        $this->assertSame('handled:test', $result);
    }

    public function testReturnsNullWhenNoHandlerMatches(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return null;
        });

        $handler->listen(function($data) {
            return null;
        });

        $result = $handler->trigger('test');

        $this->assertNull($result);
    }

    public function testFirstHandlerCanWin(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return 'first';  // Wins immediately
        });

        $handler->listen(function($data) {
            return 'second';
        });

        $result = $handler->trigger('test');

        $this->assertSame('first', $result);
    }

    // ========================================
    // Short-circuit behavior
    // ========================================

    public function testStopsAfterFirstNonNullResponse(): void
    {
        $handler = new Handler('test');
        $calls = [];

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'first';
            return null;
        });

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'second';
            return 'handled';
        });

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'third';
            return 'also handled';
        });

        $handler->trigger('test');

        // Third listener should never be called
        $this->assertSame(['first', 'second'], $calls);
    }

    public function testAllListenersCalledIfAllReturnNull(): void
    {
        $handler = new Handler('test');
        $calls = [];

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'first';
            return null;
        });

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'second';
            return null;
        });

        $handler->listen(function($data) use (&$calls) {
            $calls[] = 'third';
            return null;
        });

        $handler->trigger('test');

        $this->assertSame(['first', 'second', 'third'], $calls);
    }

    // ========================================
    // Multiple triggers
    // ========================================

    public function testCanTriggerMultipleTimes(): void
    {
        $handler = new Handler('test');
        $count = 0;

        $handler->listen(function($data) use (&$count) {
            $count++;
            return "handled";
        });

        $handler->trigger('first');
        $handler->trigger('second');
        $handler->trigger('third');

        $this->assertSame(3, $count);
    }

    public function testDifferentResultsPerTrigger(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            if ($data === 'a') return 'handled-a';
            if ($data === 'b') return 'handled-b';
            return null;
        });

        $this->assertSame('handled-a', $handler->trigger('a'));
        $this->assertSame('handled-b', $handler->trigger('b'));
        $this->assertNull($handler->trigger('c'));
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesListener(): void
    {
        $handler = new Handler('test');
        $called = false;

        $listener = function($data) use (&$called) {
            $called = true;
            return 'handled';
        };

        $handler->listen($listener);
        $handler->off($listener);
        $handler->trigger('test');

        $this->assertFalse($called);
    }

    public function testOffWithMultipleListeners(): void
    {
        $handler = new Handler('test');
        $calls = [];

        $listener1 = function($data) use (&$calls) {
            $calls[] = 1;
            return null;
        };

        $listener2 = function($data) use (&$calls) {
            $calls[] = 2;
            return null;
        };

        $listener3 = function($data) use (&$calls) {
            $calls[] = 3;
            return null;
        };

        $handler->listen($listener1, $listener2, $listener3);
        $handler->off($listener2);
        $handler->trigger('test');

        $this->assertSame([1, 3], $calls);
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testTriggerWithNoListeners(): void
    {
        $handler = new Handler('test');

        // Should not throw, returns null
        $result = $handler->trigger('some', 'args');

        $this->assertNull($result);
    }

    public function testListenAcceptsMultipleClosures(): void
    {
        $handler = new Handler('test');
        $calls = [];

        $handler->listen(
            function($data) use (&$calls) {
                $calls[] = 1;
                return null;
            },
            function($data) use (&$calls) {
                $calls[] = 2;
                return 'done';
            }
        );

        $handler->trigger('test');

        $this->assertSame([1, 2], $calls);
    }

    public function testHandlerCanReturnFalse(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return false;  // false is non-null, so this wins
        });

        $result = $handler->trigger('test');

        $this->assertSame(false, $result);
    }

    public function testHandlerCanReturnZero(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return 0;  // 0 is non-null, so this wins
        });

        $result = $handler->trigger('test');

        $this->assertSame(0, $result);
    }

    public function testHandlerCanReturnEmptyString(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return '';  // '' is non-null, so this wins
        });

        $result = $handler->trigger('test');

        $this->assertSame('', $result);
    }

    public function testHandlerCanReturnEmptyArray(): void
    {
        $handler = new Handler('test');

        $handler->listen(function($data) {
            return [];  // [] is non-null, so this wins
        });

        $result = $handler->trigger('test');

        $this->assertSame([], $result);
    }
};

exit($test->run());
