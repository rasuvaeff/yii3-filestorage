<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Stream;

use Psr\Http\Message\StreamInterface;

/**
 * Reads a bounded prefix in full.
 *
 * PSR-7 lets `read($n)` return fewer than `$n` bytes before EOF — a network
 * stream, a caching decorator or a stream from another store does exactly
 * that. A sniffer or a header parser that trusts one call examines a fragment
 * and, for the pixel guard, used to decide there was nothing to measure
 * (review 1.0 B1).
 *
 * @internal
 */
final class StreamReader
{
    /**
     * Up to `$length` bytes from the current position: fewer only at EOF, or
     * when the stream returns an empty read before it — a stall is reported as
     * the short answer, never waited out.
     */
    public static function readUpTo(StreamInterface $stream, int $length): string
    {
        $bytes = '';
        while (\strlen($bytes) < $length && !$stream->eof()) {
            $chunk = $stream->read($length - \strlen($bytes));
            if ($chunk === '') {
                break;
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }
}
