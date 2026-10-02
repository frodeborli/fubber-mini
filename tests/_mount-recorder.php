<?php
return new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $uri = $request->getUri();
        return new \mini\Http\Message\Response(json_encode([
            'target' => $request->getRequestTarget(),
            'uriPath' => $uri->getPath(),
            'uriQuery' => $uri->getQuery(),
            'routePrefix' => $request->getAttribute('mini.router.routePrefix'),
        ]), ['Content-Type' => 'application/json']);
    }
};
