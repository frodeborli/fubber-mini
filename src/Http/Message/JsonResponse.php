<?php
namespace mini\Http\Message;

/**
 * JSON response with Content-Type: application/json
 *
 * Use this for returning JSON data from routes:
 *
 *     return new JsonResponse(['users' => $users]);
 *     return new JsonResponse($data, [], 201); // Created
 *     return new JsonResponse(['error' => 'Not found'], [], 404);
 */
class JsonResponse extends Response {

    /**
     * @param mixed $data           Data to JSON encode
     * @param array $headers        Additional headers (Content-Type is set automatically)
     * @param int $statusCode       HTTP status code (default 200)
     * @throws \JsonException       If JSON encoding fails
     */
    public function __construct(mixed $data, array $headers = [], int $statusCode = 200) {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $headers['Content-Type'] = 'application/json';
        parent::__construct($json, $headers, $statusCode);
    }
}
