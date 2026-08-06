<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage;

use InvalidArgumentException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Rasuvaeff\Yii3Filestorage\Exception\UploadFailedException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;

/**
 * Ingress value object: an HTTP upload, a stream or a path, all reduced to one
 * seekable, rewound stream.
 *
 * That guarantee is what lets the pipeline sniff the MIME type, weigh the
 * upload against its policy and copy it to a store while reading the bytes
 * once per step. A non-seekable input is spooled to `php://temp` once, bounded
 * by `maxSpoolBytes` so an unbounded body cannot exhaust temporary storage.
 *
 * @api
 */
final readonly class Upload
{
    /** 256 MiB. Zero disables the cap — only sane for trusted offline imports. */
    public const int DEFAULT_MAX_SPOOL_BYTES = 268_435_456;

    private const int CHUNK = 262_144;

    /**
     * @param non-empty-string $originalName
     * @param non-empty-string|null $clientMediaTypeHint
     */
    private function __construct(
        private StreamInterface $stream,
        public string $originalName,
        public ?string $clientMediaTypeHint,
    ) {}

    /**
     *
     * @throws UploadFailedException When PHP reports the upload itself failed.
     * @throws UploadTooLargeException
     */
    public static function fromUploadedFile(
        UploadedFileInterface $file,
        StreamFactoryInterface $streamFactory,
        int $maxSpoolBytes = self::DEFAULT_MAX_SPOOL_BYTES,
    ): self {
        $error = $file->getError();
        if ($error !== \UPLOAD_ERR_OK) {
            throw new UploadFailedException("Upload failed with PHP error code {$error}");
        }

        // getStream() throws once moveTo() has been called: hand the Upload to
        // Storage instead of moving the file yourself.
        return self::fromStream(
            stream: $file->getStream(),
            originalName: $file->getClientFilename() ?? '',
            streamFactory: $streamFactory,
            maxSpoolBytes: $maxSpoolBytes,
            clientMediaTypeHint: $file->getClientMediaType(),
        );
    }

    /**
     * @param int $maxSpoolBytes Zero disables the cap.
     *
     * @throws UploadTooLargeException
     */
    public static function fromStream(
        StreamInterface $stream,
        string $originalName,
        StreamFactoryInterface $streamFactory,
        int $maxSpoolBytes = self::DEFAULT_MAX_SPOOL_BYTES,
        ?string $clientMediaTypeHint = null,
    ): self {
        if ($maxSpoolBytes < 0) {
            throw new InvalidArgumentException('maxSpoolBytes must not be negative');
        }

        return new self(
            stream: $stream->isSeekable()
                ? $stream
                : self::spool($stream, $streamFactory, $maxSpoolBytes),
            originalName: $originalName === '' ? 'file' : $originalName,
            clientMediaTypeHint: $clientMediaTypeHint === '' ? null : $clientMediaTypeHint,
        );
    }

    /**
     * @throws UploadTooLargeException
     */
    public static function fromPath(
        string $path,
        StreamFactoryInterface $streamFactory,
        ?string $originalName = null,
        int $maxSpoolBytes = self::DEFAULT_MAX_SPOOL_BYTES,
    ): self {
        return self::fromStream(
            stream: $streamFactory->createStreamFromFile($path, 'rb'),
            originalName: $originalName ?? basename($path),
            streamFactory: $streamFactory,
            maxSpoolBytes: $maxSpoolBytes,
        );
    }

    /**
     * Always rewound. Callers may read it to the end; the next call rewinds again.
     */
    public function stream(): StreamInterface
    {
        $this->stream->rewind();

        return $this->stream;
    }

    /**
     * @return int<0, max>|null Null when the stream cannot report its size.
     */
    public function size(): ?int
    {
        $size = $this->stream->getSize();

        return $size === null ? null : max(0, $size);
    }

    /**
     * @param int<0, max> $maxSpoolBytes
     *
     * @throws UploadTooLargeException
     */
    private static function spool(
        StreamInterface $stream,
        StreamFactoryInterface $streamFactory,
        int $maxSpoolBytes,
    ): StreamInterface {
        $buffered = $streamFactory->createStreamFromFile('php://temp', 'w+b');
        $written = 0;

        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(self::CHUNK);
                if ($chunk === '') {
                    if ($stream->eof()) {
                        break;
                    }

                    throw new UploadFailedException('Upload stream returned no bytes before EOF');
                }
                $written += strlen($chunk);
                if ($maxSpoolBytes > 0 && $written > $maxSpoolBytes) {
                    throw new UploadTooLargeException(
                        "Non-seekable upload exceeds the spool cap of {$maxSpoolBytes} bytes",
                    );
                }
                self::writeAll($buffered, $chunk);
            }
        } catch (\Throwable $e) {
            $buffered->close();

            throw $e;
        }
        $buffered->rewind();

        return $buffered;
    }

    /**
     * PSR-7 permits a writable stream to accept fewer bytes than requested.
     * Keep writing the remainder; accepting zero bytes would otherwise loop
     * forever and is therefore an explicit ingress failure.
     *
     * @throws UploadFailedException
     */
    private static function writeAll(StreamInterface $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $remaining = substr($contents, $offset);
            $accepted = $stream->write($remaining);
            if ($accepted <= 0 || $accepted > strlen($remaining)) {
                throw new UploadFailedException('Upload spool accepted an invalid number of bytes');
            }

            $offset += $accepted;
        }
    }
}
