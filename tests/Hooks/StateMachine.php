<?php
/**
 * Test StateMachine - managed state transitions with hooks
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Test;
use mini\Hooks\StateMachine;

// Test enum for state machine
enum TestPhase {
    case Bootstrap;
    case Ready;
    case Shutdown;
}

$test = new class extends Test {

    protected function setUp(): void
    {
        // No bootstrap needed - StateMachine is standalone
    }

    // ========================================
    // Basic state management
    // ========================================

    public function testInitialStateIsFirstState(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $this->assertSame('idle', $machine->getCurrentState());
    }

    public function testTransitionChangesState(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $machine->trigger('running');

        $this->assertSame('running', $machine->getCurrentState());
    }

    public function testMultipleTransitions(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b', 'c'],
            ['c', 'a'],
        ]);

        $this->assertSame('a', $machine->getCurrentState());
        $machine->trigger('b');
        $this->assertSame('b', $machine->getCurrentState());
        $machine->trigger('c');
        $this->assertSame('c', $machine->getCurrentState());
        $machine->trigger('a');
        $this->assertSame('a', $machine->getCurrentState());
    }

    public function testToStringReturnsCurrentState(): void
    {
        $machine = new StateMachine([
            ['active', 'inactive'],
            ['inactive', 'active'],
        ]);

        $this->assertSame('active', (string)$machine);

        $machine->trigger('inactive');
        $this->assertSame('inactive', (string)$machine);
    }

    // ========================================
    // Enum support
    // ========================================

    public function testEnumStates(): void
    {
        $machine = new StateMachine([
            [TestPhase::Bootstrap, TestPhase::Ready],
            [TestPhase::Ready, TestPhase::Shutdown],
            [TestPhase::Shutdown],
        ]);

        $this->assertSame(TestPhase::Bootstrap, $machine->getCurrentState());

        $machine->trigger(TestPhase::Ready);
        $this->assertSame(TestPhase::Ready, $machine->getCurrentState());
    }

    // ========================================
    // Invalid transitions
    // ========================================

    public function testInvalidTransitionThrows(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b', 'c'],
            ['c'],
        ]);

        // Can't go from a to c directly
        $this->assertThrows(
            fn() => $machine->trigger('c'),
            \LogicException::class
        );
    }

    public function testTerminalStateCannotTransition(): void
    {
        $machine = new StateMachine([
            ['active', 'terminated'],
            ['terminated'],  // Terminal state
        ]);

        $machine->trigger('terminated');

        // Can't transition out of terminal state
        $this->assertThrows(
            fn() => $machine->trigger('active'),
            \LogicException::class
        );
    }

    public function testUnknownStateThrows(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b'],
        ]);

        $this->assertThrows(
            fn() => $machine->trigger('unknown'),
            \LogicException::class
        );
    }

    // ========================================
    // State transition hooks
    // ========================================

    public function testOnEnteringStateCalledBeforeTransition(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $stateWhenCalled = null;

        $machine->onEnteringState('running', function($old, $new) use ($machine, &$stateWhenCalled) {
            $stateWhenCalled = $machine->getCurrentState();
        });

        $machine->trigger('running');

        // State should still be 'idle' when onEnteringState fires
        $this->assertSame('idle', $stateWhenCalled);
    }

    public function testOnEnteredStateCalledAfterTransition(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $stateWhenCalled = null;

        $machine->onEnteredState('running', function($old, $new) use ($machine, &$stateWhenCalled) {
            $stateWhenCalled = $machine->getCurrentState();
        });

        $machine->trigger('running');

        // State should be 'running' when onEnteredState fires
        $this->assertSame('running', $stateWhenCalled);
    }

    public function testOnExitingStateCalledBeforeTransition(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $stateWhenCalled = null;

        $machine->onExitingState('idle', function($old, $new) use ($machine, &$stateWhenCalled) {
            $stateWhenCalled = $machine->getCurrentState();
        });

        $machine->trigger('running');

        // State should be 'idle' when onExitingState fires
        $this->assertSame('idle', $stateWhenCalled);
    }

    public function testOnExitedStateCalledAfterTransition(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $stateWhenCalled = null;

        $machine->onExitedState('idle', function($old, $new) use ($machine, &$stateWhenCalled) {
            $stateWhenCalled = $machine->getCurrentState();
        });

        $machine->trigger('running');

        // State should be 'running' when onExitedState fires
        $this->assertSame('running', $stateWhenCalled);
    }

    public function testHooksReceiveOldAndNewState(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $receivedOld = null;
        $receivedNew = null;

        $machine->onEnteringState('running', function($old, $new) use (&$receivedOld, &$receivedNew) {
            $receivedOld = $old;
            $receivedNew = $new;
        });

        $machine->trigger('running');

        $this->assertSame('idle', $receivedOld);
        $this->assertSame('running', $receivedNew);
    }

    // ========================================
    // Hook order
    // ========================================

    public function testHookExecutionOrder(): void
    {
        $machine = new StateMachine([
            ['idle', 'running'],
            ['running', 'idle'],
        ]);

        $order = [];

        $machine->onExitingState('idle', function() use (&$order) { $order[] = 'exiting-idle'; });
        $machine->listen(function() use (&$order) { $order[] = 'global'; });
        $machine->onEnteringState('running', function() use (&$order) { $order[] = 'entering-running'; });
        $machine->onExitedState('idle', function() use (&$order) { $order[] = 'exited-idle'; });
        $machine->onEnteredState('running', function() use (&$order) { $order[] = 'entered-running'; });

        $machine->trigger('running');

        $this->assertSame([
            'exiting-idle',
            'global',
            'entering-running',
            'exited-idle',
            'entered-running',
        ], $order);
    }

    // ========================================
    // onExitCurrentState - one-time listener
    // ========================================

    public function testOnExitCurrentStateCalledOnce(): void
    {
        $machine = new StateMachine([
            ['a', 'b', 'c'],
            ['b', 'a', 'c'],
            ['c', 'a', 'b'],
        ]);

        $count = 0;

        $machine->onExitCurrentState(function() use (&$count) {
            $count++;
        });

        $machine->trigger('b');  // First transition - listener fires
        $machine->trigger('c');  // Second transition - listener already removed

        $this->assertSame(1, $count);
    }

    public function testOnExitCurrentStateReceivesStates(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b', 'a'],
        ]);

        $received = [];

        $machine->onExitCurrentState(function($old, $new) use (&$received) {
            $received = [$old, $new];
        });

        $machine->trigger('b');

        $this->assertSame(['a', 'b'], $received);
    }

    // ========================================
    // Global listen()
    // ========================================

    public function testListenCalledOnEveryTransition(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b', 'c'],
            ['c', 'a'],
        ]);

        $count = 0;

        $machine->listen(function() use (&$count) {
            $count++;
        });

        $machine->trigger('b');
        $machine->trigger('c');
        $machine->trigger('a');

        $this->assertSame(3, $count);
    }

    // ========================================
    // Array of states for hooks
    // ========================================

    public function testHooksAcceptArrayOfStates(): void
    {
        $machine = new StateMachine([
            ['a', 'b', 'c'],
            ['b', 'c'],
            ['c'],
        ]);

        $calls = [];

        $machine->onEnteredState(['b', 'c'], function($old, $new) use (&$calls) {
            $calls[] = $new;
        });

        $machine->trigger('b');
        $machine->trigger('c');

        $this->assertSame(['b', 'c'], $calls);
    }

    // ========================================
    // off() - unsubscribe
    // ========================================

    public function testOffRemovesGlobalListener(): void
    {
        $machine = new StateMachine([
            ['a', 'b'],
            ['b', 'a'],
        ]);

        $called = false;
        $listener = function() use (&$called) {
            $called = true;
        };

        $machine->listen($listener);
        $machine->off($listener);
        $machine->trigger('b');

        $this->assertFalse($called);
    }

    // ========================================
    // Re-entrant transition prevention
    // ========================================

    public function testCannotTransitionDuringTransition(): void
    {
        $machine = new StateMachine([
            ['a', 'b', 'c'],
            ['b', 'c'],
            ['c'],
        ]);

        $machine->onEnteringState('b', function() use ($machine) {
            // Try to trigger another transition while still transitioning
            $machine->trigger('c');  // This should throw
        });

        $this->assertThrows(
            fn() => $machine->trigger('b'),
            \LogicException::class
        );
    }

    // ========================================
    // Multiple valid target states
    // ========================================

    public function testCanTransitionToAnyValidTarget(): void
    {
        $machine = new StateMachine([
            ['pending', 'approved', 'rejected'],
            ['approved'],
            ['rejected'],
        ]);

        // Start fresh for approved path
        $machine1 = new StateMachine([
            ['pending', 'approved', 'rejected'],
            ['approved'],
            ['rejected'],
        ]);

        $machine1->trigger('approved');
        $this->assertSame('approved', $machine1->getCurrentState());

        // Start fresh for rejected path
        $machine2 = new StateMachine([
            ['pending', 'approved', 'rejected'],
            ['approved'],
            ['rejected'],
        ]);

        $machine2->trigger('rejected');
        $this->assertSame('rejected', $machine2->getCurrentState());
    }

    // ========================================
    // Edge cases
    // ========================================

    public function testIntegerStates(): void
    {
        $machine = new StateMachine([
            [0, 1, 2],
            [1, 2],
            [2, 0],
        ]);

        $this->assertSame(0, $machine->getCurrentState());
        $machine->trigger(1);
        $this->assertSame(1, $machine->getCurrentState());
    }

    public function testHookDoesNotAffectOtherStates(): void
    {
        $machine = new StateMachine([
            ['a', 'b', 'c'],
            ['b', 'c'],
            ['c'],
        ]);

        $called = false;

        $machine->onEnteringState('c', function() use (&$called) {
            $called = true;
        });

        // Transition to b (not c)
        $machine->trigger('b');

        $this->assertFalse($called);
    }
};

exit($test->run());
