<?php
// Valid: return a PSR-15 RequestHandlerInterface
return new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        return new \mini\Http\Message\Response('handled', ['Content-Type' => 'text/plain']);
    }
};
