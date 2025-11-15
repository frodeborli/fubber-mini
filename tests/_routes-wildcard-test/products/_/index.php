<?php
// Test wildcard directory ONLY - no _.php file exists
// This should only match paths WITH trailing slash
header('Content-Type: application/json');
echo json_encode([
    'handler' => 'products/_/index.php',
    'product_id' => $_GET[0] ?? null,
]);
return null;
