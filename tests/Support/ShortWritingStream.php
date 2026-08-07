<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that accepts only part of every write, the way a real one may.
 *
 * PSR-7 permits `write()` to return fewer bytes than it was given, and a caller
 * that ignores the return value loses the tail of every chunk. This makes that
 * behaviour reproducible.
 *
 * A decorator rather than a subclass of a concrete PSR-7 stream: the ones worth
 * wrapping are `@final`, and extending one is a static-analysis failure this
 * package does not suppress. Delegation also keeps `write()`'s parameter type
 * as PSR-7 declares it.
 *
 * @internal
 */
final readonly class ShortWritingStream implements StreamInterface
{
    /**
     * @param positive-int $accept Bytes taken from each write.
     */
    public function __construct(
        private StreamInterface $inner,
        private int $accept,
    ) {}

    #[Override]
    public function write(string $string): int
    {
        return $this->inner->write(substr($string, 0, $this->accept));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    #[Override]
    public function close(): void
    {
        $this->inner->close();
    }

    #[Override]
    public function detach()
    {
        return $this->inner->detach();
    }

    #[Override]
    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    #[Override]
    public function tell(): int
    {
        return $this->inner->tell();
    }

    #[Override]
    public function eof(): bool
    {
        return $this->inner->eof();
    }

    #[Override]
    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    #[Override]
    public function rewind(): void
    {
        $this->inner->rewind();
    }

    #[Override]
    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    #[Override]
    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    #[Override]
    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    #[Override]
    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
