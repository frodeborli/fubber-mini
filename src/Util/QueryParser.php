<?php

namespace mini\Util;

/**
 * QueryParser - Parse and match query string criteria
 *
 * Supports clean colon syntax for operators:
 *
 * Operators: eq, gt, gte, lt, lte, like
 *
 * Syntax examples:
 * - key=value (simple equality)
 * - key:eq=value (explicit equality)
 * - key:gt=10 (greater than)
 * - key:gte=18 (greater than or equal)
 * - key:lt=100 (less than)
 * - key:lte=50 (less than or equal)
 * - key:like=*pattern* (contains pattern)
 * - key:like=pattern* (starts with pattern)
 * - key:like=*pattern (ends with pattern)
 * - age:gte=18&age:lte=65 (range query)
 *
 * Usage:
 * $qp = new QueryParser($_GET);
 * $qp = new QueryParser("id=5&age:gte=18&score:gt=80");
 * $qp = new QueryParser($_GET, ["id", "name", "age"]); // with whitelist
 *
 * foreach ($rows as $row) {
 *     if ($qp->matches($row)) {
 *         // row matches criteria
 *     }
 * }
 */
class QueryParser
{
    private array $query = [];
    private array $allowedOperators = ['eq', 'gt', 'gte', 'lt', 'lte', 'like'];
    private array $operatorMap = [
        'eq' => '=',
        'gt' => '>',
        'gte' => '>=',
        'lt' => '<',
        'lte' => '<=',
        'like' => 'LIKE'
    ];

    /**
     * @param string|array $input Query string or parsed array (like $_GET)
     * @param array|null $whitelist Optional list of allowed keys
     */
    public function __construct($input, ?array $whitelist = null)
    {
        if (is_string($input)) {
            $parsed = $this->parseQueryString($input);
        } else {
            $parsed = $input;
        }

        $this->query = $this->parseQuery($parsed, $whitelist);
    }

    /**
     * Check if an object or array matches the query criteria
     */
    public function matches($data): bool
    {
        // Convert object to array for uniform access
        if (is_object($data)) {
            $data = get_object_vars($data);
        }

        foreach ($this->query as $key => $operators) {
            if (!array_key_exists($key, $data)) {
                return false; // Required key not present
            }

            $value = $data[$key];

            // All criteria are now stored as operator arrays
            foreach ($operators as $operator => $expectedValue) {
                if (!$this->compareValues($value, $operator, $expectedValue)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the parsed query array (for debugging)
     * @deprecated Use getQueryStructure() instead
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * Get the normalized query structure
     *
     * Returns queries in consistent format: ["key": {"operator": "value"}]
     * This structure can be easily converted to SQL WHERE clauses.
     *
     * @return array Normalized query structure
     */
    public function getQueryStructure(): array
    {
        return $this->query;
    }

    /**
     * Parse query string using PHP's built-in parse_str with colon syntax support
     */
    protected function parseQueryString(string $queryString): array
    {
        if (empty($queryString)) {
            return [];
        }

        // Use PHP's built-in parser - it handles URL decoding and preserves colons
        parse_str($queryString, $parsed);

        // Post-process to handle colon syntax (key:op=value)
        return $this->processColonSyntax($parsed);
    }

    /**
     * Process colon syntax in parsed query parameters
     *
     * Converts key:op entries to structured format for consistent handling
     */
    private function processColonSyntax(array $parsed): array
    {
        $result = [];

        foreach ($parsed as $key => $value) {
            // Find the last colon in the key
            $colonPos = strrpos($key, ':');

            if ($colonPos !== false) {
                $baseKey = substr($key, 0, $colonPos);
                $operator = substr($key, $colonPos + 1);

                // Only process valid operators
                if (in_array($operator, $this->allowedOperators)) {
                    // Initialize array if needed
                    if (!isset($result[$baseKey])) {
                        $result[$baseKey] = [];
                    }

                    // Store the operator
                    $result[$baseKey][$operator] = $value;
                    continue;
                }
            }

            // Regular key=value (no colon or invalid operator)
            $result[$key] = $value;
        }

        return $result;
    }


    /**
     * Parse the input into a normalized query structure
     *
     * All queries are stored in consistent operator format: ["key": {"op": "value"}]
     * Invalid operators are filtered out during this phase.
     */
    protected function parseQuery(array $input, ?array $whitelist): array
    {
        $query = [];

        foreach ($input as $key => $value) {
            // Apply whitelist filter if provided
            if ($whitelist !== null && !in_array($key, $whitelist, true)) {
                continue;
            }

            // Handle operator syntax: key[op] = value or key.op = value
            if (is_array($value)) {
                $operators = [];
                foreach ($value as $operator => $operatorValue) {
                    // Only store valid operators (invalid ones are silently ignored)
                    if (in_array($operator, $this->allowedOperators, true)) {
                        // Normalize operator (gte -> >=, gt -> >, etc.)
                        $normalizedOperator = $this->operatorMap[$operator];
                        $operators[$normalizedOperator] = $operatorValue;
                    }
                }

                // Only add to query if we have valid operators
                if (!empty($operators)) {
                    $query[$key] = $operators;
                }
            } else {
                // Simple key=value - always store in operator format for consistency
                $query[$key] = ['=' => $value];
            }
        }

        return $query;
    }

    /**
     * Compare two values using SQLite3 semantics
     */
    private function compareValues($actual, string $operator, $expected): bool
    {
        // Handle null values
        if ($actual === null || $expected === null) {
            return $operator === '=' && $actual === $expected;
        }

        // Handle LIKE operator separately (always string-based)
        if ($operator === 'LIKE') {
            return $this->matchesPattern((string)$actual, (string)$expected);
        }

        // Convert to appropriate types for comparison
        // SQLite3 tries numeric comparison if both values look numeric
        if (is_numeric($actual) && is_numeric($expected)) {
            $actual = (float)$actual;
            $expected = (float)$expected;
        } else {
            // String comparison - convert both to strings
            $actual = (string)$actual;
            $expected = (string)$expected;
        }

        switch ($operator) {
            case '=':
                return $actual == $expected;
            case '>':
                return $actual > $expected;
            case '<':
                return $actual < $expected;
            case '>=':
                return $actual >= $expected;
            case '<=':
                return $actual <= $expected;
            default:
                return false;
        }
    }

    /**
     * Check if a string matches a LIKE pattern with * wildcards
     *
     * @param string $value The actual value to test
     * @param string $pattern The pattern with * wildcards
     * @return bool True if value matches pattern
     */
    private function matchesPattern(string $value, string $pattern): bool
    {
        // Convert LIKE pattern with * wildcards to regex
        // Escape special regex characters except *
        $escapedPattern = preg_quote($pattern, '/');

        // Replace escaped \* with .* (any characters)
        $regexPattern = str_replace('\\*', '.*', $escapedPattern);

        // Anchor the pattern to match the entire string
        $regexPattern = '/^' . $regexPattern . '$/i';

        return preg_match($regexPattern, $value) === 1;
    }
}