<?php

namespace mini\Controller\Attributes;

/**
 * DELETE route attribute
 *
 * Convenience attribute for DELETE routes.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class DELETE extends Route
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'DELETE');
    }
}
