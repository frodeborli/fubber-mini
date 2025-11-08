<?php

namespace mini\Hooks;

use Closure;
use LogicException;
use UnitEnum;

/**
 * A state machine which validates that states only transition to legal target states.
 *
 * Provides hooks for entering, exiting, entered, and exited states, allowing fine-grained
 * control over state transitions. Prevents invalid transitions and re-entrant state changes.
 *
 * ## Example Usage
 *
 * ```php
 * $machine = new StateMachine([
 *     [Phase::Bootstrap, Phase::Request],  // Bootstrap can only go to Request
 *     [Phase::Request],                    // Request is terminal
 * ]);
 *
 * $machine->onEnteringState(Phase::Request, function($old, $new) {
 *     echo "Entering request phase";
 * });
 *
 * $machine->trigger(Phase::Request);  // Transitions and fires hooks
 * ```
 *
 * @package mini\Hooks
 */
class StateMachine extends Dispatcher {

    /**
     * Valid state transitions for the state machine.
     *
     * @var array<string|int, array<int, string|int|UnitEnum>>
     */
    protected readonly array $transitions;

    /**
     * The current state
     *
     * @var string|int|UnitEnum
     */
    protected string|int|UnitEnum $state;

    /**
     * State being transitioned to (null if not transitioning)
     *
     * Used to prevent re-entrant transitions.
     *
     * @var string|int|UnitEnum|null
     */
    protected null|string|int|UnitEnum $transitioningTo = null;

    /**
     * Subscribers to all state changes
     *
     * @var Closure[]
     */
    protected array $listeners = [];

    /**
     * Subscribers for entering a particular state
     *
     * @var array<string|int, Closure[]>
     */
    protected array $enteringListeners = [];

    /**
     * Subscribers for exiting a particular state
     *
     * @var array<string|int, Closure[]>
     */
    protected array $exitingListeners = [];

    /**
     * Subscribers for completed entering a particular state
     *
     * @var array<string|int, Closure[]>
     */
    protected array $enteredListeners = [];

    /**
     * Subscribers for completed exiting a particular state
     *
     * @var array<string|int, Closure[]>
     */
    protected array $exitedListeners = [];

    /**
     * Subscribers to get notified ONCE when the current state exits
     *
     * @var Closure[]
     */
    protected array $exitCurrentListeners = [];

    /**
     * Configure the states and valid transitions
     *
     * Transitions are defined as an array of arrays, where each sub-array represents
     * a state and its valid target states. The first element is the source state,
     * subsequent elements are states it can transition to. If there are no subsequent
     * elements, the state is terminal (no valid transitions).
     *
     * Examples:
     * ```php
     * // Simple two-state lifecycle
     * $machine = new StateMachine([
     *     [Phase::Bootstrap, Phase::Request],  // Bootstrap → Request
     *     [Phase::Request],                    // Request is terminal
     * ]);
     *
     * // More complex state machine
     * $machine = new StateMachine([
     *     ['idle', 'running', 'stopped'],    // idle → running OR stopped
     *     ['running', 'idle', 'stopped'],    // running → idle OR stopped
     *     ['stopped', 'idle'],               // stopped → idle (can restart)
     * ]);
     * ```
     *
     * Note: Enums cannot be used as array keys in PHP, hence the nested array format.
     *
     * @param array<int, array<int, string|int|UnitEnum>> $transitions State definitions with valid targets
     * @param string|null $description Optional description for debugging
     */
    public function __construct(array $transitions, ?string $description = null) {
        parent::__construct($description);

        $mappedTransitions = [];
        foreach ($transitions as $transition) {
            $mappedTransitions[self::scalarState($transition[0])] = $transition;
        }
        $this->transitions = $mappedTransitions;

        // Set initial state to first state in first transition
        foreach ($transitions as $trans) {
            $this->state = $trans[0];
            break;
        }
    }

    /**
     * Return the current state
     *
     * @return string|int|UnitEnum
     */
    public function getCurrentState(): string|int|UnitEnum {
        return $this->state;
    }

    /**
     * String representation of current state
     *
     * @return string
     */
    public function __toString(): string {
        return (string)self::scalarState($this->state);
    }

    /**
     * Transition to a new state
     *
     * Validates the transition is legal, then fires hooks in this order:
     * 1. exitCurrent listeners
     * 2. exiting listeners for old state
     * 3. global listeners
     * 4. entering listeners for new state
     * 5. Changes state
     * 6. exited listeners for old state
     * 7. entered listeners for new state
     *
     * @param string|int|UnitEnum $targetState The state to transition to
     * @throws LogicException If transition is invalid or already transitioning
     */
    public function trigger(string|int|UnitEnum $targetState): void {
        try {
            $previousState = $this->state;

            $this->assertStateExists($targetState);
            $this->assertNotInTransition();
            $this->assertValidTargetState($targetState);

            $this->transitioningTo = $targetState;

            // Fire exitCurrent listeners (one-time listeners)
            $exitCurrentListeners = $this->exitCurrentListeners;
            $this->exitCurrentListeners = [];
            $this->invokeAll($exitCurrentListeners, $previousState, $targetState);

            // Fire state transition hooks
            $this->invokeAll($this->exitingListeners[self::scalarState($this->state)] ?? [], $previousState, $targetState);
            $this->invokeAll($this->listeners ?? [], $previousState, $targetState);
            $this->invokeAll($this->enteringListeners[self::scalarState($this->transitioningTo)] ?? [], $previousState, $targetState);

            // Perform the actual state change
            $this->state = $this->transitioningTo;

            // Fire completion hooks
            $this->invokeAll($this->exitedListeners[self::scalarState($previousState)] ?? [], $previousState, $this->state);
            $this->invokeAll($this->enteredListeners[self::scalarState($this->state)] ?? [], $previousState, $this->state);
        } finally {
            $this->transitioningTo = null;
        }
    }

    /**
     * Register a listener that fires once when the current state exits
     *
     * @param Closure $listener function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function onExitCurrentState(Closure $listener): void {
        $this->exitCurrentListeners[] = $listener;
    }

    /**
     * Subscribe to when a particular state is about to be entered
     *
     * @param string|int|UnitEnum|array<string|int|UnitEnum> $targetStates
     * @param Closure $listener function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function onEnteringState(string|int|UnitEnum|array $targetStates, Closure $listener): void {
        foreach ($this->filterStatesArgument($targetStates) as $state) {
            $this->enteringListeners[self::scalarState($state)][] = $listener;
        }
    }

    /**
     * Subscribe to when a particular state has been entered
     *
     * @param string|int|UnitEnum|array<string|int|UnitEnum> $targetStates
     * @param Closure $listener function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function onEnteredState(string|int|UnitEnum|array $targetStates, Closure $listener): void {
        foreach ($this->filterStatesArgument($targetStates) as $state) {
            $this->enteredListeners[self::scalarState($state)][] = $listener;
        }
    }

    /**
     * Subscribe to when a particular state is about to be exited
     *
     * @param string|int|UnitEnum|array<string|int|UnitEnum> $targetStates
     * @param Closure $listener function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function onExitingState(string|int|UnitEnum|array $targetStates, Closure $listener): void {
        foreach ($this->filterStatesArgument($targetStates) as $state) {
            $this->exitingListeners[self::scalarState($state)][] = $listener;
        }
    }

    /**
     * Subscribe to when a particular state has been exited
     *
     * @param string|int|UnitEnum|array<string|int|UnitEnum> $targetStates
     * @param Closure $listener function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function onExitedState(string|int|UnitEnum|array $targetStates, Closure $listener): void {
        foreach ($this->filterStatesArgument($targetStates) as $state) {
            $this->exitedListeners[self::scalarState($state)][] = $listener;
        }
    }

    /**
     * Subscribe to get notified about all state transitions
     *
     * @param Closure ...$listeners function(string|int|UnitEnum $oldState, string|int|UnitEnum $newState): void
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Unsubscribe from all state transition events
     *
     * Removes ALL subscriptions for the provided closures.
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners,
            $this->listeners,
            $this->enteringListeners,
            $this->exitingListeners,
            $this->enteredListeners,
            $this->exitedListeners,
            $this->exitCurrentListeners,
        );
    }

    /**
     * Assert we're not currently transitioning
     *
     * @throws LogicException If already transitioning
     */
    protected function assertNotInTransition(): void {
        if ($this->transitioningTo !== null) {
            throw new LogicException(
                "Already transitioning to state `" . self::scalarState($this->transitioningTo) .
                "`, can't begin another transition yet."
            );
        }
    }

    /**
     * Assert that the provided states exist in the state machine
     *
     * @param string|int|UnitEnum ...$states
     * @throws LogicException If any state is unknown
     */
    protected function assertStateExists(string|int|UnitEnum ...$states): void {
        foreach ($states as $state) {
            if (!isset($this->transitions[self::scalarState($state)])) {
                throw new LogicException("Unknown state `" . self::scalarState($state) . "`");
            }
        }
    }

    /**
     * Assert that the provided state is a valid target from current state
     *
     * @param string|int|UnitEnum $state
     * @throws LogicException If transition is invalid
     */
    protected function assertValidTargetState(string|int|UnitEnum $state): void {
        $this->assertStateExists($state);
        $targets = $this->transitions[self::scalarState($this->state)];

        // Check if target state is in the list (skip first element which is the source state)
        if (\in_array($state, \array_slice($targets, 1), true)) {
            return;
        }

        // Build error message
        if (count($targets) === 1) {
            $tail = 'there are no valid future states (terminal state)';
        } else {
            $validStates = array_map(
                fn($s) => '`' . self::scalarState($s) . '`',
                \array_slice($targets, 1)
            );
            $tail = 'valid target states are ' . implode(', ', $validStates);
        }

        throw new LogicException(
            "Illegal transition from `" . self::scalarState($this->state) .
            "` to `" . self::scalarState($state) . "`: $tail"
        );
    }

    /**
     * Normalize state arguments (handles arrays, recursion)
     *
     * @param string|int|UnitEnum|array<string|int|UnitEnum> ...$states
     * @return array<int, string|int|UnitEnum>
     */
    protected function filterStatesArgument(string|int|UnitEnum|array ...$states): array {
        $result = [];
        foreach ($states as $state) {
            if (\is_array($state)) {
                foreach (self::filterStatesArgument(...$state) as $s) {
                    $result[self::scalarState($s)] = $s;
                }
            } else {
                $result[self::scalarState($state)] = $state;
            }
        }
        $this->assertStateExists(...$result);
        return \array_values($result);
    }

    /**
     * Convert state to scalar value for array keys
     *
     * UnitEnums use their name, others pass through.
     *
     * @param string|int|UnitEnum $state
     * @return string|int
     */
    private static function scalarState(string|int|UnitEnum $state): string|int {
        if ($state instanceof UnitEnum) {
            return $state->name;
        }
        return $state;
    }
}
