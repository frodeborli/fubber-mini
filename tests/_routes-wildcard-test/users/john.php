<?php
// Test exact match takes precedence: matches /users/john
return new \mini\Http\Message\Response(json_encode([
    'handler' => 'users/john.php',
    'exact_match' => true,
]), ['Content-Type' => 'application/json']);
