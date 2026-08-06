# Async — Event Loop Seam

The integration point between Mini and a Fiber-based runtime. Mini ships the **interface only**: `AsyncInterface` plus the `async()` accessor. There is no bundled event loop, and there never will be — a forkable core defines the seam, the runtime lives outside it.

This module is small, but it is load-bearing for Mini's positioning. Mini assumes the async future of PHP is **Fibers** (phasync-style), not a C extension with its own process model. Nothing in Mini holds shared mutable state across requests except immutable configuration, and nothing is bound to the SAPI. `AsyncInterface` is where a concrete loop plugs into that.

## Philosophy

- **Seam, not implementation.** Mini has zero dependencies; an event loop is a dependency. So Mini declares the contract and lets phasync, Swoole, ReactPHP, Amp, or your own loop satisfy it.
- **Fibers, not callbacks.** Every method is written in direct style — `await()` returns a value, `sleep()` blocks the calling coroutine. No promise types leak into the contract, so application code reads the same whether or not a loop is installed.
- **Always works — for the waiting methods.** `run()`, `await()`, `sleep()` and `awaitStream()` bootstrap a loop if none is running: `sleep()` with no loop is `usleep()`; `awaitStream()` with no loop is `stream_select()`. Callers never have to ask "am I inside a loop?" to *wait*. Spawning is different — some runtimes (phasync among them) can only spawn from inside a running context, so reach for `run()` as the entry point and `go()` inside it.
- **Fail fast when unconfigured.** `async()` resolves through the service container, which throws if no config file provides an implementation. There is no silent synchronous fallback that quietly serialises your concurrency.

## Configuration

`AsyncInterface` is registered as a singleton whose factory calls `Mini::$mini->loadServiceConfig(AsyncInterface::class)`. That resolves to:

```
_config/mini/Async/AsyncInterface.php
```

The file returns an instance:

```php
<?php
// _config/mini/Async/AsyncInterface.php
return new Vendor\Phasync\MiniAsyncAdapter();
```

Without that file, `async()` throws — the config is not optional, because there is no default loop to fall back to.

## Usage

```php
use function mini\async;

// Spawn and wait in one call
$rows = async()->run(fn() => fetchEverything());

// Spawn several coroutines, then collect — inside run(), so a loop is running
[$users, $orders] = async()->run(function () {
    $a = async()->go(fn() => fetchUsers());
    $b = async()->go(fn() => fetchOrders());

    return [async()->await($a), async()->await($b)];
});
```

`go()` returns a `Fiber` handle immediately — deliberately the native PHP type, not a framework promise class. When the body starts is **runtime-specific**: it may not run until something drives the loop (`await()`, `sleep()`, `awaitStream()`), but phasync starts the coroutine body immediately and returns at its first suspension point. Write code that is correct either way — do not rely on statements after `go()` executing before the coroutine's first line, and do not rely on the coroutine having already made progress.

`go()` also does not necessarily bootstrap: phasync can only spawn inside a context that is already running, so `go()` outside `async()->run()` is an error there. Only the waiting methods — `run()`, `await()`, `sleep()`, `awaitStream()` — are contracted to work from ordinary synchronous code.

### Waiting on I/O

```php
use function mini\async;
use const mini\READABLE;

$socket = stream_socket_client('tcp://example.com:80');
fwrite($socket, "GET / HTTP/1.0\r\n\r\n");

async()->awaitStream($socket, READABLE);
$response = stream_get_contents($socket);
```

The wait modes are plain integer bitmask constants in the `mini` namespace:

| Constant          | Value | Meaning                          |
| ----------------- | ----- | -------------------------------- |
| `mini\READABLE`   | `1`   | Wait until the stream is readable |
| `mini\WRITABLE`   | `2`   | Wait until the stream is writable |
| `mini\EXCEPTION`  | `4`   | Wait for out-of-band/exception data |

They combine: `READABLE | WRITABLE`.

### Yielding and deferring

```php
async()->sleep();        // 0 seconds — yield to other coroutines
async()->sleep(0.25);    // suspend this coroutine for 250ms

async()->defer(function () use ($handle) {
    fclose($handle);     // runs after the current execution completes
});
```

## The interface

`mini\Async\AsyncInterface`:

| Method | Purpose |
| ------ | ------- |
| `run(Closure $fn, array $args = [], ?object $context = null): mixed` | Spawn and await in one step. The entry point: works outside a loop, and bootstraps one. |
| `go(Closure $coroutine, array $args = [], ?object $context = null): Fiber` | Spawn a coroutine; returns immediately with the `Fiber` handle. |
| `await(Fiber $fiber): mixed` | Wait for a fiber and return its value. Starts a loop if none is running. |
| `sleep(float $seconds = 0): void` | Suspend for a duration; `0` yields. |
| `awaitStream($resource, int $mode): mixed` | Suspend until a stream is ready; returns the same resource for chaining. |
| `defer(Closure $callback): void` | Run a callback after the current execution completes. |
| `handleException(\Throwable $e, ?Closure $source = null): void` | Route an exception raised inside async code to the runtime's handler. |

The optional `$context` on `run()` and `go()` is an object identifying the scope a spawned fiber belongs to. Runtimes that support scoped services use it to keep request-scoped state (request, session, DB transaction) attached to the coroutine tree rather than to a global.

## Implementing a runtime

An adapter is a thin translation layer — usually under a hundred lines. The following targets [phasync](https://github.com/phasync/phasync); every `phasync::` symbol below was checked against that library's source, but treat it as a reference sketch rather than a drop-in package — pin it to the phasync version you actually install.

```php
namespace Vendor\Phasync;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use Throwable;
use mini\Async\AsyncInterface;
use phasync\Context\ContextInterface;

use function mini\log;

final class MiniAsyncAdapter implements AsyncInterface
{
    public function run(Closure $fn, array $args = [], ?object $context = null): mixed
    {
        // NOT await(go(...)): phasync::go() requires a context that is already
        // running, so the literal composition throws when run() is called from
        // ordinary synchronous code. phasync::run() bootstraps the context.
        return \phasync::run($fn, $args, $this->context($context));
    }

    public function go(Closure $coroutine, array $args = [], ?object $context = null): Fiber
    {
        // phasync::go() is go(Closure, array, int $concurrent, ?ContextInterface, bool):
        // $context must be passed by name or it lands in $concurrent.
        //
        // phasync cannot spawn outside a running context. Fail with a message
        // that names the fix instead of leaking the runtime's own LogicException.
        if (!\phasync::isRunning()) {
            throw new LogicException(
                'phasync cannot spawn a coroutine outside a context — wrap the '
                . 'call site in async()->run(), or use async()->run() directly.'
            );
        }

        return \phasync::go($coroutine, $args, context: $this->context($context));
    }

    public function await(Fiber $fiber): mixed
    {
        return \phasync::await($fiber);
    }

    public function sleep(float $seconds = 0): void
    {
        \phasync::sleep($seconds);
    }

    public function awaitStream($resource, int $mode): mixed
    {
        // phasync::stream() returns an int bitmap of the events that fired;
        // AsyncInterface contracts the resource back, for chaining.
        \phasync::stream($resource, $mode);

        return $resource;
    }

    public function defer(Closure $callback): void
    {
        \phasync::defer($callback);
    }

    public function handleException(Throwable $exception, ?Closure $source = null): void
    {
        log()->error('Unhandled exception in coroutine: {message}', [
            'message'   => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }

    /** Mini's seam types $context as ?object; phasync demands ?ContextInterface. */
    private function context(?object $context): ?ContextInterface
    {
        if (null !== $context && !$context instanceof ContextInterface) {
            throw new InvalidArgumentException(
                'phasync contexts must implement ' . ContextInterface::class
            );
        }

        return $context;
    }
}
```

Five things the signatures alone don't tell you, and which the sketch above shows:

1. **The context type is wider than the runtime's.** `AsyncInterface` types `$context` as `?object` so the seam does not name a third-party interface. Most runtimes are narrower — phasync requires `phasync\Context\ContextInterface`. Check and reject, or wrap; do not pass it through untyped.
2. **`awaitStream()` returns the resource, not a readiness bitmap.** Runtimes commonly return the events that fired. The return type on the interface is `mixed`, so a mismatch will not raise a `TypeError` — it will just break every caller that chains. Discard the runtime's value and return `$resource`.
3. **Bootstrap on demand — and know which methods can.** `run()`, `await()`, `sleep()` and `awaitStream()` must work when called from ordinary synchronous code, outside any loop. Do not implement `run()` as the literal `await(go(...))` its docblock describes: many runtimes refuse to spawn outside a context, so that composition fatals at exactly the moment bootstrapping was needed. Map `run()` onto the runtime's own entry point (`phasync::run()`) instead. If the runtime cannot spawn outside a context at all, have `go()` throw a message naming the fix rather than surfacing the runtime's internals.
4. **Coroutines may start eagerly.** `AsyncInterface` documents `go()` as lazy, but that is one runtime's behaviour, not a contract you can provide on top of a runtime that disagrees — phasync's `go()` "creates a normal coroutine and starts running it", so the body has already reached its first suspension point by the time `go()` returns. Do not add machinery to fake laziness; leave the runtime's scheduling alone and let the docs (above) tell callers not to depend on either order.
5. **You own exception reporting.** Route `handleException()` to your application logger or the runtime's own supported reporting hook. Don't reach for a runtime's internal logging helper — phasync's `logUnhandledException()`, for instance, is marked `@internal` and `@deprecated`. Once `handleException()` swallows an exception, Mini will not see it again.

The wait-mode constants happen to line up here — `mini\READABLE`/`WRITABLE`/`EXCEPTION` are `1`/`2`/`4`, matching `phasync::READABLE`/`WRITABLE`/`EXCEPT` — so the bitmask passes through unchanged. That is a coincidence of two implementations, not a guarantee: an adapter for a runtime with different values must translate the mask.

## Writing async-safe code in Mini

Mini's own code is written so that installing a loop does not change behaviour:

- Request-scoped state (`$_GET`, `$_POST`, `$_SESSION`, `request()`, `session()`) is resolved per fiber, not per process.
- Services registered with `Lifetime::Scoped` are instantiated once per request scope, not once per process.
- Nothing in the framework depends on `header()`, `echo`, or other SAPI-bound output — route files must return a response object, which is exactly what makes Mini portable to a coroutine runtime.

Follow the same rules in application code and it will run unchanged under a loop.

## See also

- **[src/Session/README.md](../Session/README.md)** — how fiber-safe request-scoped state is implemented
- **[src/Dispatcher/README.md](../Dispatcher/README.md)** — the non-SAPI-bound request/response path
- **[src/Mini/README.md](../Mini/README.md)** — service lifetimes and `loadServiceConfig()`
