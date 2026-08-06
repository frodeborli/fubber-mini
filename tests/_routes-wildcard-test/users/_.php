<?php
// Test wildcard file: matches /users/{anything}
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'users/_.php',
    'user_id' => $_GET[0] ?? null,
]), ['Content-Type' => 'application/json']);
