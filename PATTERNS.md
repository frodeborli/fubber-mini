# Mini Framework - Common Patterns

This document contains common patterns for extending and customizing Mini framework behavior.

## Table of Contents

- [Overriding Framework Services](#overriding-framework-services)
- [Request Processing (PSR-15 Middleware)](#request-processing-psr-15-middleware)
- [Request Lifecycle Hooks](#request-lifecycle-hooks)
- [Exception Handling](#exception-handling)

---

## Overriding Framework Services

Mini allows applications to override framework default services using config files.

### How It Works

The framework registers services using `Mini::$mini->loadServiceConfig()`, which:
1. First checks for application config at `_config/[namespace]/[ClassName].php`
2. Falls back to framework default at `vendor/fubber/mini/config/[namespace]/[ClassName].php`

This means **you override services by creating config files**, not by registering them before the framework loads.

### Pattern: Override Services via Config Files

**Example: Custom Logger (Monolog)**

```php
<?php
// _config/Psr/Log/LoggerInterface.php

return new \Monolog\Logger('app', [
    new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Logger::DEBUG),
    new \Monolog\Handler\SentryHandler(/* ... */),
]);
```

**Example: Custom Cache (Redis)**

```php
<?php
// _config/Psr/SimpleCache/CacheInterface.php

return new \Symfony\Component\Cache\Psr16Cache(
    new \Symfony\Component\Cache\Adapter\RedisAdapter(
        \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection('redis://localhost')
    )
);
```

**Example: Custom Database (PostgreSQL)**

```php
<?php
// _config/PDO.php

return new PDO(
    'pgsql:host=db.example.com;dbname=myapp',
    'user',
    'pass',
    [
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_PERSISTENT => true,
    ]
);
```

**Example: Custom UUID Factory**

```php
<?php
// _config/mini/UUID/FactoryInterface.php

return new \mini\UUID\UUID4Factory();  // Use v4 instead of v7
```

### Config File Lookup

Framework services use this pattern:
```php
Mini::$mini->addService(
    LoggerInterface::class,
    Lifetime::Singleton,
    fn() => Mini::$mini->loadServiceConfig(LoggerInterface::class)
);
```

When you call `log()`, Mini looks for config in this order:
1. `_config/Psr/Log/LoggerInterface.php` (your override)
2. `vendor/fubber/mini/config/Psr/Log/LoggerInterface.php` (framework default)

**Note:** You cannot override framework services by registering them in `bootstrap.php` before the framework loads. The framework unconditionally registers its services, and `loadServiceConfig()` handles the override logic via config files.

---

## Request Processing (PSR-15 Middleware)

`HttpDispatcher` supports standard PSR-15 middleware. Middleware wraps the router,
receives the PSR-7 request, and returns a PSR-7 response — there is no `header()`,
`echo`, or `exit` involved, so the same code works under classic SAPIs and
Fiber-based coroutine runtimes.

```php
<?php
// bootstrap.php

use mini\Mini;
use mini\Dispatcher\HttpDispatcher;
use mini\Http\Message\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

$dispatcher = Mini::$mini->get(HttpDispatcher::class);

// Authentication middleware
$dispatcher->addMiddleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/admin') && !\mini\auth()->isAuthenticated()) {
            return new JsonResponse(['error' => 'Authentication required'], statusCode: 401);
        }
        return $handler->handle($request);
    }
});

// Response header middleware
$dispatcher->addMiddleware(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');
    }
});
```

Middleware runs in FIFO order (first added runs first). Because middleware operates
on immutable PSR-7 messages, "response processing" is simply transforming the
response object on the way out — no output buffering tricks required.

**Short-circuiting:** return a response without calling `$handler->handle($request)`.
**Modifying the request:** pass a new request instance to `$handler->handle()` —
`mini\request()` and the `$_GET`/`$_POST` proxies always reflect the latest request.

---

## Request Lifecycle Hooks

For cross-cutting concerns that don't need to touch the response, `HttpDispatcher`
exposes typed events (see `src/Hooks/`):

```php
<?php
// bootstrap.php

use mini\Mini;
use mini\Dispatcher\HttpDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$dispatcher = Mini::$mini->get(HttpDispatcher::class);

// Before the router runs: request-scoped initialization
$dispatcher->onBeforeRequest->listen(function (ServerRequestInterface $request) {
    \mini\log()->info('{method} {path}', [
        'method' => $request->getMethod(),
        'path' => (string)$request->getUri(),
    ]);
});

// Always fires (finally block) - cleanup, metrics, audit logging
$dispatcher->onAfterRequest->listen(
    function (ServerRequestInterface $request, ?ResponseInterface $response, ?\Throwable $e) {
        if ($e !== null) {
            \mini\log()->error('Request failed: {message}', ['message' => $e->getMessage()]);
        }
    }
);
```

`Phase` transition hooks (`Mini::$mini->phase->onEnteringState(...)` /
`onEnteredState(...)`) remain available for application *lifecycle* concerns —
service wiring during Bootstrap, checks when entering Ready. Per-request logic
belongs in middleware or the dispatcher events above, not in phase hooks: in a
long-lived coroutine runtime the Ready phase is entered once while many requests
are handled.

---

## Exception Handling

Route handlers signal errors by throwing; the dispatcher converts exceptions to
responses via the exception converter registry:

```php
<?php
// bootstrap.php

use mini\Mini;
use mini\Dispatcher\HttpDispatcher;
use mini\Http\Message\JsonResponse;
use Psr\Http\Message\ResponseInterface;

$dispatcher = Mini::$mini->get(HttpDispatcher::class);

$dispatcher->registerExceptionConverter(
    fn(\App\DomainException $e): ResponseInterface =>
        new JsonResponse(['error' => $e->getMessage()], statusCode: 422)
);
```

Built-in HTTP exceptions (`mini\Exceptions\NotFoundException`, `AccessDeniedException`,
`BadRequestException`, ...) already convert to their corresponding status codes.

---

## Best Practices

### Service Overrides
- Create config files in `_config/[namespace]/[ClassName].php`
- Return properly configured service instances from config files
- Implement required PSR interfaces when replacing framework services (PSR-3, PSR-16, etc.)
- Don't try to override by registering services in `bootstrap.php` (won't work)

### Request/Response Processing
- Use PSR-15 middleware for anything that inspects or transforms requests/responses
- Use `onBeforeRequest`/`onAfterRequest` for logging, metrics, and cleanup
- Never use `header()`, `echo`, or `exit` for request handling — the strict route
  contract forbids direct output, and it cannot survive coroutine runtimes
- Throw exceptions for error responses; register converters for domain exceptions

---

## See Also

- **README.md** - Getting started guide
- **REFERENCE.md** - API reference
- **src/Dispatcher/README.md** - Full request lifecycle documentation
- **src/Hooks/README.md** - Typed events, triggers, filters, state machines
