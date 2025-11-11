<?php

namespace mini\Controller\Attributes;

/**
 * GET route attribute
 *
 * Convenience attribute for GET routes.
 *
 * Example:
 * ```php
 * #[GET('/')]
 * public function index(): ResponseInterface
 * {
 *     return $this->respond(['users' => []]);
 * }
 *
 * #[GET('/{id}/')]
 * public function show(int $id): ResponseInterface
 * {
 *     $user = table(User::class)->find($id);
 *     return $this->respond($user);
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class GET extends Route
{
    public function __construct(string $path)
    {
        parent::__construct($path, 'GET');
    }
}
