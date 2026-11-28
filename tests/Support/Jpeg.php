<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

/**
 * Builds JPEG marker streams byte by byte, so a test can place a frame header
 * exactly where an attack or a real encoder would.
 *
 * Nothing here is a decodable picture: the pixel guard reads headers only, and
 * that is the point — the bytes that matter are the segment lengths and the SOF
 * fields.
 *
 * @internal
 */
final class Jpeg
{
    /**
     * @param list<string> $segments
     */
    public static function of(array $segments, bool $withEoi = true): string
    {
        return "\xFF\xD8" . implode('', $segments) . ($withEoi ? "\xFF\xD9" : '');
    }

    public static function segment(int $marker, string $payload): string
    {
        return "\xFF" . \chr($marker) . pack('n', \strlen($payload) + 2) . $payload;
    }

    /**
     * An APPn segment of the given payload size, filled with zeros.
     */
    public static function app(int $n, int $size): string
    {
        return self::segment(0xE0 + $n, str_repeat("\0", $size));
    }

    public static function sof(int $width, int $height, int $marker = 0xC0): string
    {
        return self::segment(
            $marker,
            "\x08" . pack('n', $height) . pack('n', $width) . "\x03" . str_repeat("\x01\x11\x00", 3),
        );
    }

    /**
     * Enough APPn segments of 65 000 bytes to push the frame header past the
     * old 256 KiB header window — probe p3 of the 1.0 review.
     */
    public static function padding(int $segments = 5): string
    {
        return str_repeat(self::app(2, 64_998), $segments);
    }
}
