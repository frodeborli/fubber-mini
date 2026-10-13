# Writing PHP that lives in a shared global namespace

Mini is being made to serve many requests at once in one PHP process (Fibers), without being
tied to one runtime: PHP-FPM, phasync/Swerve, RoadRunner, FrankenPHP, Swoole, Amphp. This is the
running log of what that turned out to need, written down as it is found, because most of it is
true of any PHP library that wants to live under concurrency. Newest first. Each entry says
what was wrong, what we changed, and how it is tested.

Target release: **0.19.0** (`0.19.0-alpha1` ... `rc1`). Minor versions bump only for breaking
changes; several breaking changes share one minor version.

## Runtime shapes Mini has to fit

| Runtime | Requests per process | State between requests | Context-switch hook |
|---|---|---|---|
| PHP-FPM / CGI | one, then the process ends | gone | not needed |
| RoadRunner, FrankenPHP worker | one at a time, process lives on | **persists** | not needed (serialized) |
| phasync / Swerve | many at once (Fibers) | persists | yes (`SwitchAwareInterface`) |
| Swoole, Amphp | many at once | persists | no known hook: state must be read through an accessor, not swapped |

Consequence: Mini's own code must read per-request state through Mini (an accessor keyed on the
request scope), never from a process global. Swapping process globals (`$_GET`, the locale,
output buffers) on a context switch is an optional layer for third-party code, available only
where the runtime offers the hook.

## Findings

### Template output mixes between requests (fixed, 0.19.0)

PHP's output buffers belong to the process, not to the request. Templates, `block()` and layout
inheritance captured their output with `ob_start()`/`ob_get_clean()`. A request that was
suspended while rendering (a lazy query inside the template, or preemption at a loop back-edge)
left its buffer on top of the stack, so the next request that echoed wrote into it: request A
returned part of request B's page, and B lost it.

Fix: templates are compiled to PHP that never produces output. Mini tokenizes each template
(`PhpToken::tokenize()`) and turns inline HTML, `<?= ?>`, `echo`, `print`, `printf` and `vprintf`
into calls on `$this` that write to a `RenderSink` owned by the render. `block()`/`end()` push
and pop the sink instead of output buffers. Compiled files keep every source line on the same
line and mirror the template's path under the cache directory, so backtraces stay readable, and
opcache caches them like any PHP file. The `var_dump` family and raw `include`/`require` are
compile errors. Code a template calls that echoes directly still bypasses the sink: it should
return a string or `Stringable`.

Test: `tests/Template/Interleaving.php` suspends two renders mid-template with plain Fibers and
checks each gets exactly its own output. Lesson: process-wide machinery can't be made
per-request by using it carefully; the work has to stop going through it.

### Scoped services outlive the request in a sequential worker (fixed, 0.19.0)

`Mini::getRequestScope()` answered with the Mini instance itself when no runtime supplied a
scope. Under FPM that is harmless. In a worker that handles requests one at a time and lives on
(RoadRunner, FrankenPHP) the Scoped-service cache lives as long as the process: request 2 got
request 1's `PDO` and `Session`. A cross-user leak.

Fix: the scope is now a configurable `mini\Contracts\RequestScopeInterface` with `current()`
and `run($fn)`. `RequestDispatcher::handle()` runs every request through `run()`. A concurrent
runtime returns its own per-request object (phasync context, coroutine context) and `run()`
passes through; a sequential runtime makes a fresh object per `run()`. Override in
`_config/mini/Contracts/RequestScopeInterface.php`.

Test: `tests/Dispatcher/SequentialScope.php`. Lesson: "one object per request" must be
*produced by something at the start of the request*, not assumed.

### A static guard wrapped around user code corrupts other requests (fixed, 0.19.0)

`Dehydrator::hydrate()` set a static `$hydrating[$class]` flag around `fromSqlRow()` so that the
user's `fromSqlRow()` could call `hydrate()` again and get the reflection mapping. While one
coroutine waited inside `fromSqlRow()`, any other hydrating the same class saw the flag and
silently skipped `fromSqlRow()`: a wrong entity, no error.

Fix: no flag. `hydrateReflection()` is public and is what a `fromSqlRow()` calls for the default
mapping. Test: `tests/Database/Dehydrator.Interleaved.php` (two Fibers inside `fromSqlRow()` at
once). Lesson: a re-entrancy guard is shared state; make re-entry impossible by API shape
instead (give the callee a separate entry point), not by a flag around code you do not own.

### Open: found by audit, not yet fixed

- **The Router's output guard uses a process-global buffer.** `Router::runControllerFile()`
  wraps `require` of a route file in `ob_start()` to reject route files that echo. If the route
  file suspends, another request's output lands in that buffer: a false "produced output
  directly" error for this request, and lost output for the other. (Templates are fixed, above.)
- **Locale and timezone** (`Locale::setDefault()`, `date_default_timezone_set()`) are
  process-global, and `Fmt`, `Translator` and the datetime conversion read them. They need
  per-request storage that Mini's own code reads directly.
- **`$_GET`/`$_POST`/`$_COOKIE`** are ArrayAccess proxies, which break libraries that expect
  arrays. Preferred: real arrays, assigned on switch where a hook exists.
- **`bootstrap()` is SAPI-bound** (error handler, `ob_start`, `header()`, `exit`); it must not
  run under a request dispatcher, and the error handler is a classic-SAPI choice.
- **`CSRF`** reads `$_SERVER` and binds tokens to IP and User-Agent; to become a service.
- **`Hooks\Dispatcher`** default queue is one static queue; one request's listeners can be run
  by another request's `runEvents()`.
- **`Router::$mountPath`** is dead (route files run in a `static` closure); to be removed.
- **`BTreeLeafPage` pool** is a static object pool; to be removed or moved out of Mini.

### Preemption (phasync-ext) narrows what is a hazard

A coroutine is preempted only at loop back-edges in PHP-called-from-PHP code, never in
straight-line code, magic methods, C-function callbacks or destructors
(`#[\phasync\Uninterruptible]` opts out). A static is at risk only if a loop or I/O sits between
its read and its write. Hazards that Node.js or C# programs share (an event dispatcher's
listeners, a shared cache) are acceptable.
