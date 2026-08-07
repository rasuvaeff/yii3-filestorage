<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Stream;

use InvalidArgumentException;
use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only window `[offset, offset + length)` over another stream.
 *
 * What a `206 Partial Content` response needs: the range is served straight
 * from the underlying file handle, so an emitter looping over {@see read()} to
 * serve 100 MiB out of a 4 GiB video costs 100 MiB of transfer and holds one
 * chunk at a time. Copying the range into `php://temp` first would work and
 * would also let a client choose how much memory or disk the server spends.
 *
 * That property belongs to {@see read()}, not to the class. {@see getContents()}
 * and {@see __toString()} materialise the whole window by definition — they
 * return a string — so a 100 MiB range through either of them is 100 MiB of
 * memory. Use them for small windows; emit large ones through `read()`.
 *
 * Written here rather than pulled in: `guzzlehttp/psr7` has `LimitStream`, but
 * requiring a whole PSR-7 implementation at runtime — one that would then
 * compete with whichever implementation the application already ships — is a
 * heavier price than this class.
 *
 * @api
 */
final class LimitedStream implements StreamInterface
{
    /** Read size for the copy loops. Big enough not to be the bottleneck. */
    private const int CHUNK = 262_144;

    private int $position = 0;

    /**
     * @throws InvalidArgumentException When the arguments or the stream cannot support a window.
     */
    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $offset,
        private readonly int $length,
    ) {
        if ($offset < 0 || $length < 0) {
            throw new InvalidArgumentException('offset and length must not be negative');
        }
        if (!$stream->isSeekable()) {
            throw new InvalidArgumentException('LimitedStream requires a seekable stream');
        }

        $stream->seek($offset);
    }

    #[Override]
    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    #[Override]
    public function close(): void
    {
        $this->stream->close();
    }

    #[Override]
    public function detach()
    {
        return $this->stream->detach();
    }

    #[Override]
    public function getSize(): int
    {
        $size = $this->stream->getSize();
        if ($size === null) {
            return $this->length;
        }

        return max(0, min($this->length, $size - $this->offset));
    }

    #[Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->position >= $this->length || $this->stream->eof();
    }

    #[Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $target = match ($whence) {
            \SEEK_SET => $offset,
            \SEEK_CUR => $this->position + $offset,
            \SEEK_END => $this->length + $offset,
            default => throw new RuntimeException("Unsupported seek whence {$whence}"),
        };

        if ($target < 0 || $target > $this->length) {
            throw new RuntimeException('Cannot seek outside the range');
        }

        $this->stream->seek($this->offset + $target);
        $this->position = $target;
    }

    #[Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function write(string $string): int
    {
        throw new RuntimeException('LimitedStream is read-only');
    }

    #[Override]
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    #[Override]
    public function read(int $length): string
    {
        $remaining = $this->length - $this->position;
        if ($remaining <= 0 || $length <= 0) {
            return '';
        }

        $chunk = $this->stream->read(min($length, $remaining));
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[Override]
    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(self::CHUNK);
            if ($chunk === '') {
                if ($this->eof()) {
                    break;
                }

                throw new RuntimeException('Underlying stream returned no bytes before EOF');
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
