<?php
// Test wildcard directory ONLY - no _.php file exists
// This should only match paths WITH trailing slash
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'products/_/index.php',
    'product_id' => $_GET[0] ?? null,
]), ['Content-Type' => 'application/json']);
