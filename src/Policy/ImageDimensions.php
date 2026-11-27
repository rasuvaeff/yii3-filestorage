<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Stream\StreamReader;
use RuntimeException;

/**
 * Establishes a raster image's dimensions from its header, without decoding.
 *
 * JPEG is walked marker by marker, following each segment's length with
 * `seek()`: the frame header (SOF) may sit behind any amount of APPn metadata —
 * ICC profiles, XMP, Photoshop blocks — and a fixed prefix is a window an
 * attacker fills with padding (review 1.0 A1, summary A2). The walk never
 * *scans* for `FFC0`: the EXIF thumbnail inside APP1 is a whole JPEG with its
 * own frame header, and a scan would report the thumbnail's size.
 *
 * Every other format is handed to `getimagesizefromstring()` over a bounded
 * prefix; PNG, GIF and WebP put their dimensions in the first few dozen bytes.
 *
 * @internal
 */
final class ImageDimensions
{
    /**
     * Formats this class can always measure when the bytes are well-formed. For
     * these, "undetermined" means malformed or crafted, and the policy refuses.
     */
    public const array PARSEABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Segments walked before the frame header. Real encoders write a handful;
     * an ICC profile split into 64 KiB chunks is a few dozen. The cap bounds
     * the walk for a file made of nothing but empty segments.
     */
    public const int MAX_JPEG_SEGMENTS = 1024;

    /** Longest run of `FF` bytes — the marker prefix plus fill — before one marker. */
    public const int MAX_FILL_BYTES = 64;

    /**
     * @return array{int, int}|null Width and height; null when
     *         they could not be established.
     */
    public static function measure(StreamInterface $stream, string $mediaType, int $headerBytes = 262_144): ?array
    {
        $stream->rewind();

        try {
            return $mediaType === 'image/jpeg'
                ? self::jpeg($stream)
                : self::fromHeader(StreamReader::readUpTo($stream, $headerBytes));
        } catch (RuntimeException) {
            // A seek the stream refuses is a shape the walk cannot follow.
            return null;
        } finally {
            $stream->rewind();
        }
    }

    public static function isParseable(string $mediaType): bool
    {
        return \in_array($mediaType, self::PARSEABLE, strict: true);
    }

    /**
     * @return array{int, int}|null
     */
    private static function fromHeader(string $header): ?array
    {
        // getimagesizefromstring() parses the header only and ships with PHP
        // itself (ext-standard, no ext-gd), so this costs no dependency and
        // never decodes a single pixel.
        $size = @getimagesizefromstring($header);
        if ($size === false) {
            return null;
        }

        return [$size[0], $size[1]];
    }

    /**
     * @return array{int, int}|null
     */
    private static function jpeg(StreamInterface $stream): ?array
    {
        if (StreamReader::readUpTo($stream, 2) !== "\xFF\xD8") {
            return null;
        }

        $size = $stream->getSize();

        for ($segment = 0; $segment < self::MAX_JPEG_SEGMENTS; ++$segment) {
            $marker = self::nextMarker($stream);
            if ($marker === null) {
                return null;
            }

            // TEM and RST0–7 stand alone: no length field follows them.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            // A second SOI, EOI, or the start of scan before any frame header:
            // there is no frame to measure.
            if (in_array($marker, [0xD8, 0xD9, 0xDA], strict: true)) {
                return null;
            }

            $lengthField = StreamReader::readUpTo($stream, 2);
            if (\strlen($lengthField) !== 2) {
                return null;
            }

            $length = (\ord($lengthField[0]) << 8) | \ord($lengthField[1]);
            if ($length < 2) {
                return null;
            }

            if (self::isFrameHeader($marker)) {
                $header = StreamReader::readUpTo($stream, 5);
                if (\strlen($header) !== 5) {
                    return null;
                }

                $height = (\ord($header[1]) << 8) | \ord($header[2]);
                $width = (\ord($header[3]) << 8) | \ord($header[4]);

                // Height 0 means "defined later by DNL" — unmeasurable here.
                return $height === 0 || $width === 0 ? null : [$width, $height];
            }

            $next = $stream->tell() + $length - 2;
            if ($size !== null && $next > $size) {
                return null;
            }

            $stream->seek($next);
        }

        return null;
    }

    /**
     * The marker byte after `FF` and any fill bytes; null for anything else.
     */
    private static function nextMarker(StreamInterface $stream): ?int
    {
        if (StreamReader::readUpTo($stream, 1) !== "\xFF") {
            return null;
        }

        for ($run = 1; ; ++$run) {
            $byte = StreamReader::readUpTo($stream, 1);
            if ($byte === '') {
                return null;
            }
            if ($byte !== "\xFF") {
                $marker = \ord($byte);

                // `FF 00` is a stuffed data byte, never a marker.
                return $marker === 0x00 ? null : $marker;
            }
            if ($run >= self::MAX_FILL_BYTES) {
                return null;
            }
        }
    }

    /**
     * SOF0–SOF15, except the three markers that share the range: DHT (C4),
     * JPG (C8) and DAC (CC).
     */
    private static function isFrameHeader(int $marker): bool
    {
        return $marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC;
    }
}
