<?php
namespace mini\Hooks;

use Closure;

/**
 * Event where first non-null response wins
 * Remaining listeners are not called
 *
 * @package mini\Hooks
 */
class Handler extends Dispatcher {

    protected array $listeners = [];

    /**
     * Unsubscribe handler
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners, $this->listeners);
    }

    /**
     * Register a handler function
     *
     * @param Closure ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Try each listener in order
     * First non-null response is returned
     *
     * @param mixed $data
     * @param mixed ...$args
     * @return mixed Returns null if no handler handled the data
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
