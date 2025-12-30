<?php
namespace mini\Http\Message;

/**
 * HTML response with Content-Type: text/html; charset=utf-8
 *
 * Use this for returning rendered HTML content from routes:
 *
 *     return new HtmlResponse(render('template', $data));
 *     return new HtmlResponse($html, ['X-Custom' => 'header']);
 *     return new HtmlResponse($html, [], 404); // Not found page
 */
class HtmlResponse extends Response {

    /**
     * @param string $html          The HTML content
     * @param array $headers        Additional headers (Content-Type is set automatically)
     * @param int $statusCode       HTTP status code (default 200)
     */
    public function __construct(string $html, array $headers = [], int $statusCode = 200) {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        parent::__construct($html, $headers, $statusCode);
    }
}
