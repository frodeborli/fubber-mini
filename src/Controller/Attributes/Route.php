<?php

namespace mini\Controller\Attributes;

/**
 * Route attribute for controller methods
 *
 * Maps a controller method to an HTTP route pattern.
 * This is syntactic sugar over manual $this->router->get() calls.
 *
 * Example:
 * ```php
 * #[Route('/', method: 'GET')]
 * public function index(): ResponseInterface
 * {
 *     return $this->respond(['users' => []]);
 * }
 *
 * #[Route('/{id}/', method: 'GET')]
 * public function show(int $id): ResponseInterface
 * {
 *     $user = table(User::class)->find($id);
 *     if (!$user) throw new \mini\Exceptions\NotFoundException();
 *     return $this->respond($user);
 * }
 * ```
 *
 * @package mini\Controller\Attributes
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Route
{
    public function __construct(
        public string $path,
        public ?string $method = null,
    ) {}
}
