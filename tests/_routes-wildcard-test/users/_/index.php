<?php
// Test wildcard directory with index: matches /users/{anything}/
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'users/_/index.php',
    'user_id' => $_GET[0] ?? null,
]), ['Content-Type' => 'application/json']);
