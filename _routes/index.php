<?php
// Root route handler
return new \mini\Http\Message\Response(
    '<h1>Welcome to Mini Framework</h1>'
    . '<p>This is served from _routes/index.php</p>'
    . '<p><a href="/ping">Test /ping route</a></p>',
    ['Content-Type' => 'text/html; charset=utf-8']
);
