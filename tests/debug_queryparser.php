<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use mini\Util\QueryParser;

// Test what QueryParser actually returns
$queryParser = new QueryParser('age:gte=21');
$result = $queryParser->getQuery();

echo "QueryParser result for 'age:gte=21':\n";
var_export($result);
echo "\n\n";

// Test with getQueryStructure instead
$structure = $queryParser->getQueryStructure();
echo "QueryParser structure:\n";
var_export($structure);
echo "\n\n";

// Test simple query
$simpleParser = new QueryParser('name=Alice');
echo "Simple query 'name=Alice':\n";
var_export($simpleParser->getQuery());
echo "\n\n";

// Test array input
$arrayParser = new QueryParser(['age:gte' => '21']);
echo "Array input ['age:gte' => '21']:\n";
var_export($arrayParser->getQuery());
echo "\n";