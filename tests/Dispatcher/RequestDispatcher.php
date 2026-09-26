<?php
/**
 * RequestDispatcher: the request pipeline as a PSR-15 request handler
 *
 * The invariants: handle() runs a request through the middleware and the final handler and
 * returns the response; request() and the $_GET proxy see the request of the scope they are
 * called from, also while another scope handles another request; nothing of a request is
 * visible once handle() returned; exceptions are converted, or thrown to the caller.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Dispatcher\HttpDispatcher;
use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\Response;
use mini\Http\Message\ServerRequest;
use mini\Mini;
use mini\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

$test = new class extends Test {

    /** @var list<string> what the handler saw, per call */
    public array $seen = [];

    private RequestDispatcher $dispatcher;

    protected function setUp(): void
    {
        // The final handler reports what request() and $_GET say; ?suspend=1 suspends its fiber
        // halfway, so that another request is handled meanwhile
        $this->dispatcher = new RequestDispatcher(new class($this) implements RequestHandlerInterface {
            public function __construct(private object $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $before = \mini\request()->getQueryParams()['id'] ?? '-';
                if (isset($request->getQueryParams()['suspend'])) {
                    \Fiber::suspend();
                }
                if (isset($request->getQueryParams()['throw'])) {
                    throw new \DomainException($request->getQueryParams()['throw']);
                }
                $after = (\mini\request()->getQueryParams()['id'] ?? '-') . '/' . ($_GET['id'] ?? '-');

                return new Response("$before $after");
            }
        });
        // Registered through the HttpDispatcher: the RequestDispatcher it delegates to gets it
        Mini::$mini->get(HttpDispatcher::class)->addMiddleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Middleware', 'yes');
            }
        });
        $this->dispatcher->addMiddleware(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Own-Middleware', 'yes');
            }
        });
        $this->dispatcher->registerExceptionConverter(function (\DomainException $e): ResponseInterface {
            return new Response('converted: ' . $e->getMessage(), [], 409);
        });
        \mini\bootstrap();
    }

    private function handle(string $target): ResponseInterface
    {
        return $this->dispatcher->handle(new ServerRequest('GET', $target, ''));
    }

    public function testHandleRunsTheMiddlewareAndTheHandler(): void
    {
        $response = $this->handle('/x?id=1');
        $this->assertSame('1 1/1', (string) $response->getBody());
        $this->assertSame('yes', $response->getHeaderLine('X-Own-Middleware'));
    }

    public function testMiddlewareRegisteredThroughHttpDispatcherReachesTheSharedPipeline(): void
    {
        $response = Mini::$mini->get(RequestDispatcher::class)->handle(new ServerRequest('GET', '/ping', ''));
        $this->assertSame('yes', $response->getHeaderLine('X-Middleware'));
    }

    public function testConcurrentRequestsInDifferentScopesSeeTheirOwnRequest(): void
    {
        // Two requests in two fibers, each suspended halfway while the other runs
        $a = new \Fiber(fn () => $this->handle('/x?id=a&suspend=1'));
        $b = new \Fiber(fn () => $this->handle('/x?id=b&suspend=1'));
        $a->start();
        $b->start();
        $a->resume();
        $b->resume();
        $this->assertSame('a a/a', (string) $a->getReturn()->getBody());
        $this->assertSame('b b/b', (string) $b->getReturn()->getBody());
    }

    public function testNothingOfARequestIsVisibleAfterHandleReturned(): void
    {
        $this->handle('/x?id=gone');
        $this->assertThrows(fn () => \mini\request(), \RuntimeException::class);
    }

    public function testWithinMakesARequestCurrentAgainAfterHandleReturned(): void
    {
        $request = new ServerRequest('GET', '/live?id=kept', '');
        $this->handle('/x?id=other');
        $this->assertSame('kept/kept', RequestDispatcher::within($request, fn () => \mini\request()->getQueryParams()['id'] . '/' . $_GET['id']));
        $this->assertThrows(fn () => \mini\request(), \RuntimeException::class);
    }

    public function testWithinIsRefusedWhileTheScopeHandlesARequest(): void
    {
        $dispatcher = new RequestDispatcher(new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return RequestDispatcher::within($request, fn () => new Response('too early'));
            }
        });
        $this->assertThrows(fn () => $dispatcher->handle(new ServerRequest('GET', '/x', '')), \LogicException::class);
    }

    public function testAConvertedExceptionBecomesItsResponse(): void
    {
        $response = $this->handle('/x?throw=nope');
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('converted: nope', (string) $response->getBody());
    }

    public function testAnUnconvertedExceptionIsThrownToTheCaller(): void
    {
        $dispatcher = new RequestDispatcher(new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('unhandled');
            }
        });
        $this->assertThrows(fn () => $dispatcher->handle(new ServerRequest('GET', '/', '')), \LogicException::class);
    }
};

exit($test->run());
