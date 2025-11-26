<?php

namespace mini\Mime;

use Countable;
use IteratorAggregate;

/**
 * Multipart MIME Message Interface
 *
 * Represents all multipart MIME types (multipart/mixed, multipart/alternative,
 * multipart/related, multipart/digest, multipart/parallel, etc.).
 *
 * Per RFC 2046 Section 5: "All present and future subtypes of the 'multipart'
 * type must use an identical syntax." Therefore, this single interface handles
 * all multipart types - the semantic difference (mixed vs alternative vs related)
 * is encoded in the Content-Type header, not in separate interfaces.
 *
 * Multipart messages contain multiple independent body parts, each with its own
 * content type and headers, separated by a boundary string.
 *
 * Implements convenient access patterns:
 * - getPart($index): Access parts by index
 * - count($message): Get number of parts (Countable)
 * - foreach ($message as $part): Iterate parts (IteratorAggregate)
 *
 * This interface maintains PSR-7 immutability - all mutation methods return
 * new instances. ArrayAccess is intentionally NOT implemented because its
 * offsetSet/offsetUnset methods return void (incompatible with immutability).
 *
 * Based on:
 * - RFC 2046 Section 5: Multipart Media Type (1996)
 * - RFC 2387: The MIME Multipart/Related Content-type (1998)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2046#section-5
 * @see https://datatracker.ietf.org/doc/html/rfc2387
 * @extends IteratorAggregate<int, MimeMessageInterface>
 */
interface MultipartInterface extends MimeMessageInterface, Countable, IteratorAggregate
{
    /**
     * Get all parts of this multipart message
     *
     * @return MimeMessageInterface[] Array of MIME message parts
     */
    public function getParts(): array;

    /**
     * Get a specific part by index
     *
     * @param int $index Zero-based index of the part to retrieve
     * @return MimeMessageInterface|null The part at the specified index, or null if not found
     */
    public function getPart(int $index): ?MimeMessageInterface;

    /**
     * Get the boundary string used to separate parts
     *
     * The boundary is specified in the Content-Type header as:
     * Content-Type: multipart/mixed; boundary="boundary-string"
     *
     * @return string The boundary delimiter
     */
    public function getBoundary(): string;

    /**
     * Get the multipart subtype (e.g., 'mixed', 'alternative', 'related')
     *
     * This is a convenience method that extracts the subtype from the
     * Content-Type header. For example:
     * - "multipart/alternative" returns "alternative"
     * - "multipart/mixed; boundary=xyz" returns "mixed"
     *
     * Internally maps to: parse Content-Type header
     *
     * @return string The multipart subtype
     */
    public function getMultipartType(): string;

    /**
     * Check if this message is of a specific multipart type
     *
     * This is a convenience method for type checking. Internally maps to
     * comparing the subtype from the Content-Type header.
     *
     * @param string $subtype The subtype to check (e.g., 'alternative', 'mixed', 'related')
     * @return bool True if the message is of the specified type
     */
    public function isMultipartType(string $subtype): bool;

    /**
     * Return an instance with the specified part appended
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new part appended.
     *
     * @param MimeMessageInterface $part The part to append
     * @return static A new instance with the appended part
     */
    public function withAddedPart(MimeMessageInterface $part): static;

    /**
     * Return an instance with the specified parts replacing all existing parts
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new parts.
     *
     * @param MimeMessageInterface ...$parts The parts to set
     * @return static A new instance with the specified parts
     */
    public function withParts(MimeMessageInterface ...$parts): static;

    /**
     * Return an instance with the specified boundary
     *
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * new boundary.
     *
     * @param string $boundary The boundary string
     * @return static A new instance with the specified boundary
     */
    public function withBoundary(string $boundary): static;

    /**
     * Return an instance with the specified multipart subtype
     *
     * This is a convenience method that sets the Content-Type header to
     * "multipart/{$subtype}; boundary={boundary}".
     *
     * Internally maps to: $this->withHeader('Content-Type', "multipart/{$subtype}; boundary=...")
     *
     * Common subtypes: 'mixed', 'alternative', 'related', 'digest', 'parallel'
     *
     * @param string $subtype The multipart subtype (e.g., 'alternative', 'mixed')
     * @return static A new instance with the specified multipart type
     * @throws \InvalidArgumentException if subtype is invalid
     */
    public function withMultipartType(string $subtype): static;

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * This method inherits from PSR-7 MessageInterface but adds additional
     * validation for MultipartInterface: the Content-Type header MUST be a
     * valid multipart media type (multipart/*).
     *
     * @param string $name Case-insensitive header field name.
     * @param string|string[] $value Header value(s).
     * @return static
     * @throws \InvalidArgumentException for invalid header names or values.
     * @throws \InvalidArgumentException if Content-Type header is not a valid
     *         multipart media type (must be multipart/mixed, multipart/alternative,
     *         multipart/related, multipart/digest, multipart/parallel, or other
     *         multipart/* subtype per RFC 2046).
     */
    public function withHeader(string $name, $value): static;
}
