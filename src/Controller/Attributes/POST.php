<?php

namespace mini\Controller\Attributes;

/**
 * POST route attribute
 *
 * Convenience attribute for POST routes.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class POST extends Route
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'POST');
    }
}
