<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A readable stream whose first `read()` hands back an empty string, the way a
 * stalled network read or a slow disk does. Real sources can answer an empty
 * chunk without being finished, and every copy loop in this package has to
 * tell that apart from the end of the data.
 *
 * Two shapes:
 *
 * - `midStream()`: the empty chunk arrives mid-stream — position unchanged,
 *   `eof()` still false, and the *next* read returns real bytes. This is what
 *   the "no bytes before EOF" guards exist for.
 *
 * - `atEnd()`: the empty chunk coincides with the end — position jumps to the
 *   end and `eof()` becomes true afterwards. Loop guards that only break on an
 *   empty chunk *and* a true `eof()` take this path.
 *
 * Seekable, so `Upload::fromStream()` keeps it as-is instead of spooling it.
 *
 * @internal
 */
final class StallingStream implements StreamInterface
{
    private int $position = 0;
    private bool $stalled = false;

    private function __construct(
        private readonly string $body,
        private readonly bool $jumpToEndOnStall,
    ) {}

    /**
     * The first read returns '' mid-stream; reads after it behave normally.
     */
    public static function midStream(string $body): self
    {
        return new self($body, jumpToEndOnStall: false);
    }

    /**
     * The first read returns '' and lands the position at the end of the body,
     * so every read after it is empty and `eof()` is true.
     */
    public static function atEnd(string $body): self
    {
        return new self($body, jumpToEndOnStall: true);
    }

    #[Override]
    public function __toString(): string
    {
        return $this->body;
    }

    #[Override]
    public function close(): void {}

    #[Override]
    public function detach()
    {
        return null;
    }

    #[Override]
    public function getSize(): int
    {
        return \strlen($this->body);
    }

    #[Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->position >= \strlen($this->body);
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
            \SEEK_END => \strlen($this->body) + $offset,
            default => throw new RuntimeException("Unsupported seek whence {$whence}"),
        };

        if ($target < 0 || $target > \strlen($this->body)) {
            throw new RuntimeException('Cannot seek outside the body');
        }

        $this->position = $target;
        $this->stalled = false;
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
        throw new RuntimeException('Stream is not writable');
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function read(int $length): string
    {
        if (!$this->stalled) {
            $this->stalled = true;
            if ($this->jumpToEndOnStall) {
                $this->position = \strlen($this->body);
            }

            return '';
        }

        $chunk = substr($this->body, $this->position, max(0, $length));
        $this->position += \strlen($chunk);

        return $chunk;
    }

    #[Override]
    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $chunk = $this->read(\strlen($this->body) - $this->position);
            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
