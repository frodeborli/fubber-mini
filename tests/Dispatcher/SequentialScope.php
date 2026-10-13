<?php
/**
 * A process that handles requests one at a time and lives on between them (RoadRunner,
 * FrankenPHP worker mode) must not carry Scoped services from one request to the next.
 *
 * The invariant: a Scoped service is the same instance everywhere within one handle() call,
 * and a different instance in the next call. Without a scope of its own to offer, the process
 * answers getRequestScope() with the Mini instance, whose cache lives as long as the process:
 * the second request would get the first one's database connection and session.
 */

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Dispatcher\RequestDispatcher;
use mini\Http\Message\Response;
use mini\Http\Message\ServerRequest;
use mini\Lifetime;
use mini\Mini;
use mini\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$test = new class extends Test {

    /** @var list<object> the scoped service each request saw (twice per request) */
    public array $seen = [];

    protected function setUp(): void
    {
        Mini::$mini->addService('test.sequential', Lifetime::Scoped, fn() => new \stdClass());
    }

    private function dispatcher(): RequestDispatcher
    {
        return new RequestDispatcher(new class($this) implements RequestHandlerInterface {
            public function __construct(private object $test) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->seen[] = Mini::$mini->get('test.sequential');
                $this->test->seen[] = Mini::$mini->get('test.sequential');

                return new Response('ok');
            }
        });
    }

    public function testScopedServicesDoNotSurviveTheRequest(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->handle(new ServerRequest('GET', '/', ''));
        $dispatcher->handle(new ServerRequest('GET', '/', ''));

        $this->assertSame($this->seen[0], $this->seen[1], 'one instance within a request');
        $this->assertSame($this->seen[2], $this->seen[3], 'one instance within a request');
        $this->assertNotSame($this->seen[0], $this->seen[2], 'the next request gets its own');
    }

    public function testNoScopeLingersAfterTheRequest(): void
    {
        $this->dispatcher()->handle(new ServerRequest('GET', '/', ''));
        $this->assertSame(Mini::$mini, Mini::$mini->getRequestScope());
    }
};

exit($test->run());
