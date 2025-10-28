<?php
namespace mini\Hooks;

use Closure;
use Throwable;

/**
 * Chain of listeners that transform a value
 * Each listener receives the value and returns a (potentially) modified version
 *
 * @package mini\Hooks
 */
class Filter extends Dispatcher {

    protected array $listeners = [];

    /**
     * Filter a value through all registered listeners
     *
     * @param mixed $value The value to filter
     * @param mixed ...$args Extra arguments passed to listeners
     * @return mixed Filtered value
     */
    public function filter(mixed $value, mixed ...$args): mixed {
        try {
            foreach ($this->listeners as $listener) {
                try {
                    $value = $listener($value, ...$args);
                } catch (Throwable $e) {
                    self::handleException($e, $listener, $this);
                }
            }
            return $value;
        } finally {
            self::runEvents();
        }
    }

    /**
     * Register a filter function
     * Function MUST return the value (modified or not)
     *
     * @param Closure ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Unsubscribe filter function
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners, $this->listeners);
    }
}
