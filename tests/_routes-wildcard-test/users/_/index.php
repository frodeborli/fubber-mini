<?php
// Test wildcard directory with index: matches /users/{anything}/
header('Content-Type: application/json');
echo json_encode([
    'handler' => 'users/_/index.php',
    'user_id' => $_GET[0] ?? null,
]);
return null;
