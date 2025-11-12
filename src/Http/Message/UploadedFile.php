<?php
namespace mini\Http\Message;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Value object representing a file uploaded through an HTTP request.
 *
 * Instances of this interface are considered immutable; all methods that
 * might change state MUST be implemented such that they retain the internal
 * state of the current instance and return an instance that contains the
 * changed state.
 */
class UploadedFile implements UploadedFileInterface {
    use UploadedFileTrait;

    /**
     * @param resource|string $source   A stream resource or the path to the actual file in the file system
     * @param string $filename          The filename as provided by the uploader
     * @param string $mediaType         The mime type as provided by the uploader
     * @param int $filesize             The filesize of the uploaded file, if known
     * @param int $error                The error code as provided by the uploader {$see https://www.php.net/manual/en/features.file-upload.errors.php}
     * @param bool $isUploadedFile      Override the {@see is_uploaded_file()} function by setting this to true
     */
    public function __construct(mixed $source, string $filename=null, string $mediaType=null, int $filesize=null, int $error=null, bool $isUploadedFile=false) {
        $this->UploadedFileTrait($source, $filename, $mediaType, $filesize, $error, $isUploadedFile);
    }
}
