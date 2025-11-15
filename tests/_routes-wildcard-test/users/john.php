<?php
// Test exact match takes precedence: matches /users/john
header('Content-Type: application/json');
echo json_encode([
    'handler' => 'users/john.php',
    'exact_match' => true,
]);
return null;
