<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Stream\StreamReader;

/**
 * A forward reader over a buffered window, for {@see ImageDimensions}' GIF
 * walk: a GIF is a chain of sub-blocks of at most 255 bytes, and one stream
 * call per sub-block would make the walk cost more than the upload.
 *
 * @internal
 */
final class GifReader
{
    private const int CHUNK = 65_536;

    private string $buffer = '';

    private int $position = 0;

    private int $subBlocks = 0;

    private bool $exhausted = false;

    public function __construct(private readonly StreamInterface $stream) {}

    /**
     * Null at EOF.
     */
    public function byte(): ?int
    {
        $bytes = $this->bytes(1);

        return $bytes === null ? null : \ord($bytes);
    }

    /**
     * Exactly `$length` bytes, or null when the stream ends first.
     */
    public function bytes(int $length): ?string
    {
        if (!$this->fill($length)) {
            return null;
        }

        $bytes = substr($this->buffer, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    public function skip(int $length): bool
    {
        return $this->bytes($length) !== null;
    }

    /**
     * Steps over an extension block: its label, then its sub-blocks.
     */
    public function skipExtension(): bool
    {
        return $this->skip(1) && $this->skipSubBlocks();
    }

    /**
     * Steps over a sub-block chain up to its zero terminator. False at EOF,
     * or once the walk has passed {@see ImageDimensions::MAX_GIF_SUB_BLOCKS}
     * sub-blocks — then {@see self::exhausted()} says so.
     */
    public function skipSubBlocks(): bool
    {
        while (($size = $this->byte()) !== null) {
            if ($size === 0) {
                return true;
            }
            if (++$this->subBlocks > ImageDimensions::MAX_GIF_SUB_BLOCKS) {
                $this->exhausted = true;

                return false;
            }
            if (!$this->skip($size)) {
                return false;
            }
        }

        return false;
    }

    /**
     * Whether the walk stopped at its cap rather than at the end of the file.
     */
    public function exhausted(): bool
    {
        return $this->exhausted;
    }

    private function fill(int $length): bool
    {
        while (\strlen($this->buffer) - $this->position < $length) {
            $chunk = StreamReader::readUpTo($this->stream, self::CHUNK);
            if ($chunk === '') {
                return false;
            }

            $this->buffer = substr($this->buffer, $this->position) . $chunk;
            $this->position = 0;
        }

        return true;
    }
}
