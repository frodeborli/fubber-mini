<?php
// Test nested wildcard: matches /users/{user_id}/posts
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'users/_/posts.php',
    'user_id' => $_GET[0] ?? null,
]), ['Content-Type' => 'application/json']);
