<?php
namespace mini\Hooks;

use Closure;
use LogicException;
use Throwable;

/**
 * Abstract event dispatcher - root class for Mini's hooks system
 *
 * @package mini\Hooks
 */
abstract class Dispatcher {

    private static int $queueFirstIndex = 0;
    private static int $queueLastIndex = 0;
    private static array $queuedListeners = [];
    private static array $queuedArgs = [];
    private static array $queuedDispatchers = [];

    /**
     * Stack trace of where this event dispatcher was constructed
     *
     * @var array
     */
    private array $constructTrace = [];

    /**
     * Closure invoked whenever an event listener throws an exception
     *
     * @var Closure `function(Throwable $exception, Closure $listener, Dispatcher $event): void`
     */
    private static ?Closure $exceptionHandler = null;

    /**
     * Closure which schedules a function to be invoked asynchronously
     *
     * @var null|Closure
     */
    private static ?Closure $deferFunction = null;

    /**
     * Closure which runs all scheduled events
     *
     * @var null|Closure
     */
    private static ?Closure $runEventsFunction = null;

    public function __construct(
        private readonly ?string $description = null,
    ) {
        // Store construction location for debugging
        $this->constructTrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    }

    /**
     * Get a description of the event dispatcher
     *
     * @return null|string
     */
    public final function getDescription(): ?string {
        return $this->description;
    }

    /**
     * Get the filename where this event dispatcher was created
     *
     * @return string
     */
    public final function getFile(): string {
        return $this->constructTrace['file'] ?? 'unknown';
    }

    /**
     * Get the line number where this event dispatcher was created
     *
     * @return int
     */
    public final function getLine(): int {
        return $this->constructTrace['line'] ?? 0;
    }

    /**
     * Configure event loop integration
     *
     * @param Closure $deferFunction
     * @param Closure $runEventsFunction
     * @param Closure $exceptionHandler
     * @throws LogicException
     */
    public final static function configure(Closure $deferFunction, Closure $runEventsFunction, Closure $exceptionHandler): void {
        if (self::$deferFunction !== null) {
            throw new LogicException("Event loop already configured");
        }
        self::$deferFunction = $deferFunction;
        self::$runEventsFunction = $runEventsFunction;
        self::$exceptionHandler = $exceptionHandler;
    }

    /**
     * Invoke all listeners in an array
     *
     * @param Closure[] $listeners Array of closures
     * @param mixed ...$args Arguments to pass to listeners
     */
    protected function invokeAll(array $listeners, mixed ...$args): void {
        foreach ($listeners as $listener) {
            self::defer($this, $listener, $args);
        }
        self::runEvents();
    }

    /**
     * Handle an exception from a listener
     *
     * @param Throwable $exception
     * @param null|Closure $listener
     * @param null|Dispatcher $source
     * @throws Throwable
     */
    protected static function handleException(Throwable $exception, ?Closure $listener, ?Dispatcher $source): void {
        if (self::$exceptionHandler !== null) {
            try {
                (self::$exceptionHandler)(exception: $exception, listener: $listener, event: $source);
            } catch (\Throwable $e) {
                // Fatal: exception handler itself threw
                error_log("Hook exception handler failed: " . $e->getMessage());
                throw $e;
            }
        } else {
            throw $exception;
        }
    }

    /**
     * Filter array to remove specific values
     *
     * @param array $array Source array
     * @param mixed $valueToRemove Value to remove
     * @param null|int $count Number of elements removed
     * @param int|null $limit Limit removals
     * @return array
     */
    protected static function filterArray(array $array, mixed $valueToRemove, ?int &$count = 0, ?int $limit = null): array {
        $result = [];
        foreach ($array as $v) {
            if ($limit !== $count && $v === $valueToRemove) {
                ++$count;
                continue;
            }
            $result[] = $v;
        }
        return $result;
    }

    /**
     * Schedule a function to run when runEvents() is called
     *
     * @param Dispatcher $dispatcher
     * @param Closure $listener
     * @param array $args
     */
    protected static function defer(Dispatcher $dispatcher, Closure $listener, array $args): void {
        if (self::$deferFunction) {
            (self::$deferFunction)(self::invoke(...), $dispatcher, $listener, $args);
        } else {
            self::$queuedListeners[self::$queueLastIndex] = $listener;
            self::$queuedArgs[self::$queueLastIndex] = $args;
            self::$queuedDispatchers[self::$queueLastIndex] = $dispatcher;
            ++self::$queueLastIndex;
        }
    }

    /**
     * Run all scheduled events
     *
     * @throws Throwable
     */
    protected static function runEvents(): void {
        if (self::$runEventsFunction) {
            (self::$runEventsFunction)();
            return;
        }

        // Run only events queued before this call (prevent infinite loops)
        $runUntil = self::$queueLastIndex;
        while (self::$queueFirstIndex < $runUntil) {
            $listener = self::$queuedListeners[self::$queueFirstIndex];
            $args = self::$queuedArgs[self::$queueFirstIndex];
            $source = self::$queuedDispatchers[self::$queueFirstIndex];
            unset(
                self::$queuedListeners[self::$queueFirstIndex],
                self::$queuedArgs[self::$queueFirstIndex],
                self::$queuedDispatchers[self::$queueFirstIndex]
            );
            ++self::$queueFirstIndex;
            try {
                $listener(...$args);
            } catch (Throwable $exception) {
                self::handleException($exception, $listener, $source);
            }
        }
    }

    /**
     * Remove values from multiple arrays by reference
     *
     * @param array $values
     * @param array $arrays
     */
    protected function filterArrays(array $values, array &...$arrays): void {
        foreach ($arrays as &$array) {
            foreach ($values as $value) {
                foreach ($array as $k => $v) {
                    if ($v === $value) {
                        unset($array[$k]);
                    }
                }
            }
        }
    }

    /**
     * Invoke a listener with exception handling
     *
     * @param Dispatcher $dispatcher
     * @param Closure $listener
     * @param array $args
     * @return mixed
     */
    protected static function invoke(Dispatcher $dispatcher, Closure $listener, array $args): mixed {
        try {
            return $listener(...$args);
        } catch (\Throwable $e) {
            self::handleException($e, $listener, $dispatcher);
            return null;
        }
    }
}
