<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A seekable stream that returns only part of every read, the way a network
 * stream may.
 *
 * PSR-7 permits `read($n)` to return fewer than `$n` bytes before EOF, and a
 * caller that treats one read as "the prefix" sniffs and measures a fragment.
 * This makes that behaviour reproducible.
 *
 * A decorator rather than a subclass of a concrete PSR-7 stream: the ones worth
 * wrapping are `@final`, and extending one is a static-analysis failure this
 * package does not suppress.
 *
 * @internal
 */
final readonly class ShortReadingStream implements StreamInterface
{
    /**
     * @param positive-int $give Bytes handed out by each read.
     */
    public function __construct(
        private StreamInterface $inner,
        private int $give,
    ) {}

    #[Override]
    public function write(string $string): int
    {
        return $this->inner->write($string);
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

    /**
     * PSR-7 declares no return type here and PHP has no `resource` type, so
     * the contract can only be stated in a docblock.
     *
     * @return resource|null
     */
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
        return $this->inner->read(min($length, $this->give));
    }

    #[Override]
    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    /**
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
