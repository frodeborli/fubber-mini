<?php
// Valid: return a PSR-7 ResponseInterface directly
return new \mini\Http\Message\Response('pong', ['Content-Type' => 'text/plain']);
