<?php
// Test nested wildcard: matches /users/{user_id}/posts
header('Content-Type: application/json');
echo json_encode([
    'handler' => 'users/_/posts.php',
    'user_id' => $_GET[0] ?? null,
]);
return null;
