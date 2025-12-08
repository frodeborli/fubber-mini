<?php

namespace mini\Mail;

/**
 * Multipart MIME types
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2046#section-5
 */
enum MultipartType: string
{
    /**
     * Independent parts, processed in order (e.g., email with attachments)
     */
    case Mixed = 'mixed';

    /**
     * Same content in different formats - client chooses best (e.g., text + HTML)
     */
    case Alternative = 'alternative';

    /**
     * Parts that reference each other (e.g., HTML with inline images via Content-ID)
     */
    case Related = 'related';

    /**
     * Collection of messages (e.g., email digest)
     */
    case Digest = 'digest';

    /**
     * Parts to be displayed simultaneously
     */
    case Parallel = 'parallel';

    /**
     * Form data submissions
     */
    case FormData = 'form-data';

    /**
     * Byte ranges of a resource
     */
    case Byteranges = 'byteranges';
}
