<?php
namespace mini\Hooks;

use Closure;
use LogicException;
use WeakMap;

/**
 * Event dispatcher that triggers once per source (string or object)
 * After triggering for a source, new subscribers are called immediately
 *
 * @package mini\Hooks
 */
class PerItemTriggers extends Dispatcher {

    /**
     * String sources that triggered
     *
     * @var array<string, array>
     */
    protected array $triggeredStrings = [];

    /**
     * Object sources that triggered
     *
     * @var WeakMap<object, array>
     */
    protected readonly WeakMap $triggeredObjects;

    /**
     * Listeners on string sources
     *
     * @var array<string, Closure[]>
     */
    protected array $stringListeners = [];

    /**
     * Listeners on object sources
     *
     * @var WeakMap<object, Closure[]>
     */
    protected readonly WeakMap $objectListeners;

    /**
     * Listeners on all events
     *
     * @var Closure[]
     */
    protected array $listeners = [];

    public function __construct(?string $description = null) {
        parent::__construct($description);
        $this->triggeredObjects = new WeakMap();
        $this->objectListeners = new WeakMap();
    }

    /**
     * Was this event triggered for a specific source?
     *
     * @param string|object $source
     * @return bool
     */
    public function wasTriggeredFor(string|object $source): bool {
        if (\is_string($source)) {
            return \array_key_exists($source, $this->triggeredStrings);
        } else {
            return isset($this->triggeredObjects[$source]);
        }
    }

    /**
     * Trigger event for a specific source
     *
     * @param string|object $source
     * @param mixed ...$data
     * @throws LogicException
     */
    public function triggerFor(string|object $source, mixed ...$data): void {
        if ($this->wasTriggeredFor($source)) {
            throw new LogicException("Event already triggered for this source");
        }

        // Record trigger
        if (\is_string($source)) {
            $this->triggeredStrings[$source] = $data;
        } else {
            $this->triggeredObjects[$source] = $data;
        }

        // Invoke global listeners
        $this->invokeAll($this->listeners, $source, ...$data);

        // Invoke source-specific listeners
        if (\is_string($source)) {
            if (empty($this->stringListeners[$source])) {
                return;
            }
            $listeners = $this->stringListeners[$source];
            unset($this->stringListeners[$source]);
            $this->invokeAll($listeners, $source, ...$data);
        } else {
            if (!isset($this->objectListeners[$source])) {
                return;
            }
            $listeners = $this->objectListeners[$source];
            unset($this->objectListeners[$source]);
            $this->invokeAll($listeners, $source, ...$data);
        }
    }

    /**
     * Subscribe to all events (receives source as first arg)
     *
     * @param Closure ...$listeners
     */
    public function listen(Closure ...$listeners): void {
        foreach ($listeners as $listener) {
            $this->listeners[] = $listener;
        }
    }

    /**
     * Subscribe to a specific source
     * If already triggered, listener is called immediately
     *
     * @param string|object $source
     * @param Closure ...$listeners
     */
    public function listenFor(string|object $source, Closure ...$listeners): void {
        if (\is_object($source)) {
            if (isset($this->triggeredObjects[$source])) {
                // Already triggered - invoke immediately
                $this->invokeAll($listeners, $source, ...$this->triggeredObjects[$source]);
            } else {
                // Add listener
                if (!isset($this->objectListeners[$source])) {
                    $this->objectListeners[$source] = $listeners;
                } else {
                    $this->objectListeners[$source] = [...$this->objectListeners[$source], ...$listeners];
                }
            }
        } else {
            if (\array_key_exists($source, $this->triggeredStrings)) {
                // Already triggered - invoke immediately
                $this->invokeAll($listeners, $source, ...$this->triggeredStrings[$source]);
            } else {
                // Add listener
                $this->stringListeners[$source] = [...($this->stringListeners[$source] ?? []), ...$listeners];
            }
        }
    }

    /**
     * Unsubscribe from event
     *
     * @param Closure ...$listeners
     */
    public function off(Closure ...$listeners): void {
        self::filterArrays($listeners, $this->listeners);
        self::filterArrays($listeners, ...$this->stringListeners);

        foreach ($this->objectListeners as $object => $array) {
            self::filterArrays($listeners, $array);
            if ($array === []) {
                unset($this->objectListeners[$object]);
            } else {
                $this->objectListeners[$object] = $array;
            }
        }
    }
}
