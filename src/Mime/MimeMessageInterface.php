<?php

namespace mini\Mime;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * MIME Message Interface
 *
 * Represents a MIME message that can be composed into a tree structure.
 * Extends both PSR-7 MessageInterface and StreamInterface to enable:
 * - Standard HTTP message handling (headers, body)
 * - Streaming capability for large messages
 * - Recursive composition (body can be another MimeMessageInterface)
 *
 * Based on:
 * - RFC 2045: MIME Part One: Format of Internet Message Bodies (1996)
 * - RFC 2046: MIME Part Two: Media Types (1996)
 * - RFC 2047: MIME Part Three: Message Header Extensions (1996)
 * - RFC 5322: Internet Message Format (2008)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2046
 * @see https://datatracker.ietf.org/doc/html/rfc5322
 */
interface MimeMessageInterface extends MessageInterface, StreamInterface
{
    /**
     * Get the message body
     *
     * For single-part messages, this returns the message itself.
     * For multipart messages, this returns the root multipart container.
     *
     * This override narrows PSR-7's StreamInterface return type to MimeMessageInterface,
     * enabling recursive tree traversal of MIME structures.
     *
     * @return MimeMessageInterface The message body (may be another MIME message)
     */
    public function getBody(): MimeMessageInterface;
}
