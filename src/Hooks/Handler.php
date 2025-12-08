<?php
namespace mini\Hooks;

use Closure;

/**
 * Event where first non-null response wins
 * Remaining listeners are not called
 *
 * @template TInput The input type to be handled
 * @template TOutput The output type returned by handlers
 * @package mini\Hooks
 */
class Handler extends Dispatcher {

    /** @var list<callable(TInput, mixed...): (TOutput|null)> */
    protected array $listeners = [];

    /**
     * Unsubscribe handler
     *
     * @param callable(TInput, mixed...): (TOutput|null) ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners, $this->listeners);
    }

    /**
     * Register a handler function
     *
     * @param callable(TInput, mixed...): (TOutput|null) ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Try each handler in order until one returns non-null
     *
     * @param TInput $data The data to handle
     * @param mixed ...$args Additional context arguments
     * @return TOutput|null First non-null response, or null if no handler matched
     * @throws \Throwable
     */
    public function trigger(mixed $data, mixed ...$args): mixed {
        foreach ($this->listeners as $listener) {
            $result = self::invoke($this, $listener, [$data, ...$args]);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }
}
