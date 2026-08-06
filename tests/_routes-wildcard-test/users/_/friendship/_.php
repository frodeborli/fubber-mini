<?php
// Test double wildcard: matches /users/{user_id}/friendship/{friend_id}
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'users/_/friendship/_.php',
    'user_id' => $_GET[0] ?? null,
    'friend_id' => $_GET[1] ?? null,
]), ['Content-Type' => 'application/json']);
