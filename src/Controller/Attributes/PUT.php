<?php

namespace mini\Controller\Attributes;

/**
 * PUT route attribute
 *
 * Convenience attribute for PUT routes.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class PUT extends Route
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'PUT');
    }
}
