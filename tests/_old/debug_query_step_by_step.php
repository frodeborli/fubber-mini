<?php

require __DIR__ . '/../../ensure-autoloader.php';

use mini\Tables\DatabaseRepository;
use mini\Util\QueryParser;
use mini\DB;

class TestUser {
    public ?int $id = null;
    public ?string $name = null;
    public ?\DateTimeImmutable $created_at = null;
}

$db = new DB(':memory:');
$repo = new DatabaseRepository($db, 'test_dates', TestUser::class, 'id', [
    'created_at' => 'datetime'
]);

echo "Step-by-step debugging of query() method:\n";

echo "\n1. Field names from repository:\n";
$fieldNames = $repo->getFieldNames();
echo var_export($fieldNames, true) . "\n";

echo "\n2. Testing QueryParser with field whitelist:\n";
$params = 'created_at:gte=2024-01-01';
parse_str($params, $parsed);
echo "Parsed params: " . var_export($parsed, true) . "\n";

$queryParser = new QueryParser($parsed, $fieldNames);
$parsedQuery = $queryParser->getQuery();
echo "QueryParser result: " . var_export($parsedQuery, true) . "\n";

echo "\n3. Testing QueryParser without whitelist:\n";
$queryParserNoWhitelist = new QueryParser($parsed);
$parsedQueryNoWhitelist = $queryParserNoWhitelist->getQuery();
echo "QueryParser result (no whitelist): " . var_export($parsedQueryNoWhitelist, true) . "\n";