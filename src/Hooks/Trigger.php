<?php
namespace mini\Hooks;

use Closure;
use LogicException;

/**
 * Event that can only trigger ONCE
 * Late subscribers are called immediately with the original trigger data
 *
 * @package mini\Hooks
 */
class Trigger extends Dispatcher {

    protected bool $triggered = false;
    protected array $data = [];
    protected array $listeners = [];

    /**
     * Has this trigger already fired?
     *
     * @return bool
     */
    public function wasTriggered(): bool {
        return $this->triggered;
    }

    /**
     * Activate the trigger and run all listeners
     *
     * @param mixed ...$args
     * @throws LogicException
     */
    public function trigger(mixed ...$args): void {
        if ($this->triggered) {
            throw new LogicException("Trigger '{$this->getDescription()}' already fired");
        }
        $this->triggered = true;
        $this->data = $args;
        $listeners = $this->listeners;
        $this->listeners = [];
        $this->invokeAll($listeners, ...$args);
    }

    /**
     * Subscribe to this trigger
     * If already triggered, listener is called immediately
     *
     * @param Closure ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        if ($this->triggered) {
            $this->invokeAll($listeners, ...$this->data);
        } else {
            foreach ($listeners as $listener) {
                $this->listeners[] = $listener;
            }
        }
    }

    /**
     * Unsubscribe from this trigger
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners, $this->listeners);
    }
}
