<?php

namespace mini\Controller\Attributes;

/**
 * PATCH route attribute
 *
 * Convenience attribute for PATCH routes.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class PATCH extends Route
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'PATCH');
    }
}
