<?php

namespace mini\Table;

/**
 * Order specification for a single column
 */
readonly class OrderDef
{
    public function __construct(
        public string $column,
        public bool $asc = true,
    ) {}

    /**
     * Parse an order spec string into OrderDef array
     *
     * @param string $spec e.g., "name ASC, created_at DESC, id"
     * @return OrderDef[]
     */
    public static function parse(string $spec): array
    {
        $result = [];
        foreach (preg_split('/\s*,\s*/', trim($spec)) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^(\w+)(?:\s+(ASC|DESC))?$/i', $part, $m)) {
                $column = $m[1];
                $asc = !isset($m[2]) || strtoupper($m[2]) === 'ASC';
                $result[] = new self($column, $asc);
            }
        }
        return $result;
    }

    /**
     * Get column names from OrderDef array
     *
     * @param OrderDef[] $orders
     * @return string[]
     */
    public static function columns(array $orders): array
    {
        return array_map(fn(self $o) => $o->column, $orders);
    }

    /**
     * Convert OrderDef array back to spec string
     *
     * @param OrderDef[] $orders
     */
    public static function toSpec(array $orders): string
    {
        return implode(', ', array_map(
            fn(self $o) => $o->column . ($o->asc ? ' ASC' : ' DESC'),
            $orders
        ));
    }
}
