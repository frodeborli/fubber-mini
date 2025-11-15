<?php
// Test wildcard file: matches /users/{anything}
header('Content-Type: application/json');
echo json_encode([
    'handler' => 'users/_.php',
    'user_id' => $_GET[0] ?? null,
]);
// Return null for classical PHP output
return null;
