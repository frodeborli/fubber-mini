<?php
namespace mini\Http\Message;

use Psr\Http\Message\StreamInterface;

/**
 * Value object representing a file uploaded through an HTTP request.
 *
 * Instances of this interface are considered immutable; all methods that
 * might change state MUST be implemented such that they retain the internal
 * state of the current instance and return an instance that contains the
 * changed state.
 */
trait UploadedFileTrait {

    protected mixed $stream = null;
    protected ?string $path = null;
    protected ?string $filename = null;
    protected ?string $mediaType = null;
    protected ?string $error = null;
    protected ?int $filesize = null;

    /**
     * @param resource|string $source   A stream resource or the path to the actual file in the file system
     * @param string $filename          The filename as provided by the uploader
     * @param string $mediaType         The mime type as provided by the uploader
     * @param int $filesize             The filesize of the uploaded file, if known
     * @param int $error                The error code as provided by the uploader {$see https://www.php.net/manual/en/features.file-upload.errors.php}
     * @param bool $isUploadedFile      Declare that the file is in fact an uploaded file, regardless of is_uploaded_file()
     */
    protected function UploadedFileTrait(mixed $source, string $filename=null, string $mediaType=null, int $filesize=null, int $error=null, bool $isUploadedFile=false) {
        if (is_resource($source)) {
            $this->stream = $source;
        } else {
            $this->path = realpath($source);
            if (!$this->path) {
                throw new \InvalidArgumentException("File path '$source' does not exist");
            }
        }
        $this->source = $source;
        $this->name = $filename;
        $this->mediaType = $mediaType;
        $this->filesize = $filesize;
        $this->error = $error ?? UPLOAD_ERR_OK;
        $this->isUploadedFile = $isUploadedFile;
    }

    /**
     * Retrieve a stream representing the uploaded file.
     *
     * This method MUST return a StreamInterface instance, representing the
     * uploaded file. The purpose of this method is to allow utilizing native PHP
     * stream functionality to manipulate the file upload, such as
     * stream_copy_to_stream() (though the result will need to be decorated in a
     * native PHP stream wrapper to work with such functions).
     *
     * If the moveTo() method has been called previously, this method MUST raise
     * an exception.
     *
     * @return StreamInterface Stream representation of the uploaded file.
     * @throws \RuntimeException in cases when no stream is available.
     * @throws \RuntimeException in cases when no stream can be created.
     */
    public function getStream(): StreamInterface {
        $this->assertNoError();
        if (is_resource($this->source)) {
            return new Stream($this->source);
        }
        if (is_string($this->source) && file_exists($this->source)) {
            return new Stream(fopen($this->source, 'rbn'));
        }
        throw new \RuntimeException("Uploaded file is unavailable");
    }

    /**
     * Move the uploaded file to a new location.
     *
     * Use this method as an alternative to move_uploaded_file(). This method is
     * guaranteed to work in both SAPI and non-SAPI environments.
     * Implementations must determine which environment they are in, and use the
     * appropriate method (move_uploaded_file(), rename(), or a stream
     * operation) to perform the operation.
     *
     * $targetPath may be an absolute path, or a relative path. If it is a
     * relative path, resolution should be the same as used by PHP's rename()
     * function.
     *
     * The original file or stream MUST be removed on completion.
     *
     * If this method is called more than once, any subsequent calls MUST raise
     * an exception.
     *
     * When used in an SAPI environment where $_FILES is populated, when writing
     * files via moveTo(), is_uploaded_file() and move_uploaded_file() SHOULD be
     * used to ensure permissions and upload status are verified correctly.
     *
     * If you wish to move to a stream, use getStream(), as SAPI operations
     * cannot guarantee writing to stream destinations.
     *
     * @see http://php.net/is_uploaded_file
     * @see http://php.net/move_uploaded_file
     * @param string $targetPath Path to which to move the uploaded file.
     * @throws \InvalidArgumentException if the $targetPath specified is invalid.
     * @throws \RuntimeException on any error during the move operation.
     * @throws \RuntimeException on the second or subsequent call to the method.
     */
    public function moveTo($targetPath): void {
        $this->assertNoError();
        if ($this->stream !== null) {
            // we have a stream reference to the upload, so we'll read from that
            if (!rewind($this->stream)) {
                throw new \RuntimeException("Unable to rewind the incoming upload stream");
            }
            $fp = fopen($targetPath, "xn");
            if (!$fp) {
                throw new \InvalidArgumentException("Unable to create file '$targetPath'");
            }
            while (!feof($this->stream)) {
                $chunk = fread($this->stream, 8192);
                if ($chunk === false) {
                    unlink($targetPath);
                    throw new \RuntimeException("fread() failed on incoming upload stream");
                }
                $written = fwrite($fp, $chunk);
                if (!is_int($written)) {
                    unlink($targetPath);
                    throw new \RuntimeException("fwrite() failed on the destination file");
                }
            }
            fclose($fp);
            fclose($this->stream);
            return;
        }
        if ($this->path) {
            if (!(isset($_FILES) && ($this->isUploadedFile || is_uploaded_file($this->path)))) {
                throw new \RuntimeException("The file is not an uploaded file");
            }
            rename($this->path, $targetPath);
            return;
        }
        throw new \RuntimeException("Unable to move uploaded file");
    }

    /**
     * Retrieve the file size.
     *
     * Implementations SHOULD return the value stored in the "size" key of
     * the file in the $_FILES array if available, as PHP calculates this based
     * on the actual size transmitted.
     *
     * @return int|null The file size in bytes or null if unknown.
     */
    public function getSize(): ?int {
        if (is_int($this->filesize)) {
            return $this->filesize;
        }
        if ($this->stream && is_resource($this->stream)) {
            $stat = fstat($this->stream);
            if ($stat && key_exists('size', $stat)) {
                return $this->filesize = $stat['size'];
            }
        }
        if ($this->path && file_exists($this->path)) {
            return $this->filesize = filesize($this->path);
        }
        return null;
    }

    /**
     * Retrieve the error associated with the uploaded file.
     *
     * The return value MUST be one of PHP's UPLOAD_ERR_XXX constants.
     *
     * If the file was uploaded successfully, this method MUST return
     * UPLOAD_ERR_OK.
     *
     * Implementations SHOULD return the value stored in the "error" key of
     * the file in the $_FILES array.
     *
     * @see http://php.net/manual/en/features.file-upload.errors.php
     * @return int One of PHP's UPLOAD_ERR_XXX constants.
     */
    public function getError(): int {
        return $this->error;
    }

    /**
     * Retrieve the filename sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious filename with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "name" key of
     * the file in the $_FILES array.
     *
     * @return string|null The filename sent by the client or null if none
     *     was provided.
     */
    public function getClientFilename(): ?string {
        return $this->filename;
    }

    /**
     * Retrieve the media type sent by the client.
     *
     * Do not trust the value returned by this method. A client could send
     * a malicious media type with the intention to corrupt or hack your
     * application.
     *
     * Implementations SHOULD return the value stored in the "type" key of
     * the file in the $_FILES array.
     *
     * @return string|null The media type sent by the client or null if none
     *     was provided.
     */
    public function getClientMediaType(): ?string {
        return $this->mediaType;
    }

    /**
     * Check that no error condition exists which should prevent this operation
     */
    protected function assertNoError(): void {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException("Upload error code ".$this->error." prevented this operation");
        }
    }
}
