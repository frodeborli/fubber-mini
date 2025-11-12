<?php

// Set $_GET BEFORE autoload, since HttpDispatcher is registered during autoload
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/ping?say=World';
$_SERVER['QUERY_STRING'] = 'say=World';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = ['say' => 'World'];  // fromGlobals() reads from $_GET

require 'vendor/autoload.php';

\mini\dispatch();
