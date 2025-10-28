<?php
namespace mini\Hooks;

use Closure;

/**
 * Event dispatcher for events that can trigger multiple times
 *
 * @package mini\Hooks
 */
class Event extends Dispatcher {

    protected array $listeners = [];
    protected array $onceListeners = [];

    /**
     * Invoke all event listeners with the provided arguments
     *
     * @param mixed ...$args
     * @throws \Throwable
     */
    public function trigger(mixed ...$args): void {
        $this->invokeAll($this->listeners, ...$args);

        $once = $this->onceListeners;
        $this->onceListeners = [];
        $this->invokeAll($once, ...$args);
    }

    /**
     * Subscribe to this event
     *
     * @param Closure ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Subscribe and auto-unsubscribe after first trigger
     *
     * @param Closure ...$listeners
     */
    public function once(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->onceListeners[] = $listener;
        }
    }

    /**
     * Unsubscribe from this event
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays(
            $listeners,
            $this->listeners,
            $this->onceListeners,
        );
    }
}
