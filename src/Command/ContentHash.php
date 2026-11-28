<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Command;

use Psr\Http\Message\StreamInterface;

/**
 * SHA-256 of a whole stream, or nothing when the read stopped before EOF.
 *
 * PSR-7 lets `read()` return `''` before the end — a stalled network body —
 * and a hash of that prefix is not the content's (review 1.0 B9).
 *
 * @internal
 */
final class ContentHash
{
    private const int CHUNK = 262_144;

    private function __construct() {}

    /**
     * @return array{sha256: non-empty-string, bytes: int<0, max>}|null
     */
    public static function of(StreamInterface $stream): ?array
    {
        $context = hash_init('sha256');
        $bytes = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                return null;
            }

            $bytes += \strlen($chunk);
            hash_update($context, $chunk);
        }

        return ['sha256' => hash_final($context), 'bytes' => $bytes];
    }
}
