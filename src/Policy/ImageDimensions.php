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
 * scans *inside* a segment for `FFC0`: the EXIF thumbnail inside APP1 is a
 * whole JPEG with its own frame header, and a scan would report the
 * thumbnail's size. Between two segments it does what libjpeg does — skips
 * extraneous bytes up to the next marker (review 1.0 pass 2, N2) — so it reads
 * the same frame header the decoder will.
 *
 * GIF is walked block by block (review 1.0 pass 2, N1): the logical screen
 * that `getimagesize()` reports says nothing about the frames, each of which
 * carries its own size, and a decoder that grows its canvas to the frame
 * allocates the frame. A frame that does not fit inside the screen is
 * undetermined, which the policy refuses.
 *
 * TIFF is walked IFD by IFD with `seek()` (review 1.0 pass 2, N3): writers
 * commonly put the directory after the image data, out of any prefix. The
 * largest page of the chain is the answer; BigTIFF is undetermined.
 *
 * PNG and WebP are handed to `getimagesizefromstring()` over a bounded
 * prefix: both put their dimensions in the first few dozen bytes, and their
 * frames cannot outgrow the canvas (the decoders check). Any other format gets
 * the same prefix treatment, and nothing more.
 *
 * @internal
 */
final class ImageDimensions
{
    /**
     * Formats this class can always measure when the bytes are well-formed. For
     * these, "undetermined" means malformed or crafted, and the policy refuses.
     */
    public const array PARSEABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff'];

    /**
     * Segments walked before the frame header. Real encoders write a handful;
     * an ICC profile split into 64 KiB chunks is a few dozen. The cap bounds
     * the walk for a file made of nothing but empty segments.
     */
    public const int MAX_JPEG_SEGMENTS = 1024;

    /** Frames walked in one GIF; a longer animation is undetermined. */
    public const int MAX_GIF_FRAMES = 4096;

    /**
     * Data and extension sub-blocks walked in one GIF. Encoders write 255-byte
     * sub-blocks, so this covers about 250 MB of image data; a file of
     * one-byte sub-blocks cannot make the walk the expensive part.
     */
    public const int MAX_GIF_SUB_BLOCKS = 1_000_000;

    /** Directories (pages) walked in one TIFF. */
    public const int MAX_TIFF_IFDS = 1024;

    /** Entries one TIFF directory may declare. */
    private const int MAX_TIFF_ENTRIES = 4096;

    private const int SCAN_CHUNK = 8192;

    /**
     * @return array{int, int}|null Width and height; null when
     *         they could not be established.
     */
    public static function measure(StreamInterface $stream, string $mediaType, int $headerBytes = 262_144): ?array
    {
        $stream->rewind();

        try {
            return match ($mediaType) {
                'image/jpeg' => self::jpeg($stream),
                'image/gif' => self::gif($stream),
                'image/tiff' => self::tiff($stream),
                default => self::fromHeader(StreamReader::readUpTo($stream, $headerBytes)),
            };
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
        $frame = null;

        // The walk goes on past the frame header to the start of scan: a
        // second frame header is a file libjpeg refuses and Pillow measures by
        // the *last* one, so a decoy 1×1 frame first would otherwise hide the
        // real size. The segment cap counts the segments before the frame;
        // the ones after it (tables) get a budget of their own.
        for ($segment = 0; $segment < 2 * self::MAX_JPEG_SEGMENTS; ++$segment) {
            if ($frame === null && $segment >= self::MAX_JPEG_SEGMENTS) {
                return null;
            }

            $marker = self::nextMarker($stream);
            if ($marker === null) {
                return null;
            }

            // TEM and RST0–7 stand alone: no length field follows them.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }

            // The start of scan, or the end of the image, after one frame
            // header: that is the frame. Before any, or a second SOI: there is
            // no frame to measure.
            if ($marker === 0xDA || $marker === 0xD9) {
                return $frame;
            }
            if ($marker === 0xD8) {
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

            $next = $stream->tell() + $length - 2;

            if (self::isFrameHeader($marker)) {
                $header = StreamReader::readUpTo($stream, 5);
                if ($frame !== null || \strlen($header) !== 5) {
                    return null;
                }

                $height = (\ord($header[1]) << 8) | \ord($header[2]);
                $width = (\ord($header[3]) << 8) | \ord($header[4]);

                // Height 0 means "defined later by DNL" — unmeasurable here.
                if ($height === 0 || $width === 0) {
                    return null;
                }

                $frame = [$width, $height];
            }

            if ($size !== null && $next > $size) {
                return null;
            }

            $stream->seek($next);
        }

        return null;
    }

    /**
     * The next marker, as libjpeg's `next_marker()` finds it: bytes up to an
     * `FF` are extraneous, any run of `FF` is fill, and `FF 00` is a stuffed
     * data byte to be skipped like the rest. Null at EOF. Leaves the stream
     * just after the marker byte.
     *
     * Only ever called where a marker is due — after SOI, after a standalone
     * marker, after a segment stepped over by its length — so the scan covers
     * the gap between segments and never a segment's own bytes.
     */
    private static function nextMarker(StreamInterface $stream): ?int
    {
        $afterFf = false;

        while (true) {
            $start = $stream->tell();
            $chunk = StreamReader::readUpTo($stream, self::SCAN_CHUNK);
            $length = \strlen($chunk);
            if ($length === 0) {
                return null;
            }

            $i = 0;
            while ($i < $length) {
                if (!$afterFf) {
                    $ff = strpos($chunk, "\xFF", $i);
                    if ($ff === false) {
                        break;
                    }

                    $afterFf = true;
                    $i = $ff + 1;

                    continue;
                }

                $byte = \ord($chunk[$i]);
                ++$i;
                if ($byte === 0xFF) {
                    continue;
                }
                if ($byte === 0x00) {
                    $afterFf = false;

                    continue;
                }

                $stream->seek($start + $i);

                return $byte;
            }
        }
    }

    /**
     * @return array{int, int}|null
     */
    private static function gif(StreamInterface $stream): ?array
    {
        $header = StreamReader::readUpTo($stream, 13);
        if (\strlen($header) !== 13 || !\in_array(substr($header, 0, 6), ['GIF87a', 'GIF89a'], strict: true)) {
            return null;
        }

        /** @var array{w: int, h: int, flags: int} $screen */
        $screen = unpack('vw/vh/Cflags', $header, 6);
        if ($screen['w'] === 0 || $screen['h'] === 0) {
            return null;
        }

        $reader = new GifReader($stream);
        if (($screen['flags'] & 0x80) !== 0 && !$reader->skip(3 << (($screen['flags'] & 0x07) + 1))) {
            return null;
        }

        $frames = 0;
        while (($introducer = $reader->byte()) !== null && $introducer !== 0x3B) {
            if ($introducer === 0x21) {
                if (!$reader->skipExtension()) {
                    break;
                }

                continue;
            }
            if ($introducer !== 0x2C) {
                return null;
            }

            $descriptor = $reader->bytes(9);
            if ($descriptor === null) {
                break;
            }

            /** @var array{x: int, y: int, w: int, h: int, flags: int} $frame */
            $frame = unpack('vx/vy/vw/vh/Cflags', $descriptor);
            if (
                ++$frames > self::MAX_GIF_FRAMES
                || $frame['w'] === 0
                || $frame['h'] === 0
                || $frame['x'] + $frame['w'] > $screen['w']
                || $frame['y'] + $frame['h'] > $screen['h']
            ) {
                return null;
            }

            $localTable = ($frame['flags'] & 0x80) !== 0 ? 3 << (($frame['flags'] & 0x07) + 1) : 0;
            // The local colour table, then the LZW minimum code size.
            if (!$reader->skip($localTable + 1) || !$reader->skipSubBlocks()) {
                break;
            }
        }

        if ($reader->exhausted() || $frames === 0) {
            return null;
        }

        return [$screen['w'], $screen['h']];
    }

    /**
     * @return array{int, int}|null
     */
    private static function tiff(StreamInterface $stream): ?array
    {
        $header = StreamReader::readUpTo($stream, 8);
        $order = substr($header, 0, 2);
        if (\strlen($header) !== 8 || ($order !== 'II' && $order !== 'MM')) {
            return null;
        }

        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        /** @var array{magic: int, offset: int} $fields */
        $fields = unpack("{$short}magic/{$long}offset", $header, 2);
        if ($fields['magic'] !== 42) {
            // 43 is BigTIFF, with 64-bit offsets: undetermined.
            return null;
        }

        $largest = null;
        $visited = [];
        $offset = $fields['offset'];

        for ($ifd = 0; $offset !== 0; ++$ifd) {
            if ($ifd >= self::MAX_TIFF_IFDS || $offset < 8 || isset($visited[$offset])) {
                return null;
            }

            $visited[$offset] = true;
            $stream->seek($offset);
            $countField = StreamReader::readUpTo($stream, 2);
            if (\strlen($countField) !== 2) {
                return null;
            }

            /** @var array{n: int} $count */
            $count = unpack("{$short}n", $countField);
            if ($count['n'] > self::MAX_TIFF_ENTRIES) {
                return null;
            }

            $entries = StreamReader::readUpTo($stream, $count['n'] * 12 + 4);
            if (\strlen($entries) !== $count['n'] * 12 + 4) {
                return null;
            }

            $size = [256 => 0, 257 => 0];
            for ($entry = 0; $entry < $count['n']; ++$entry) {
                /** @var array{tag: int, type: int} $field */
                $field = unpack("{$short}tag/{$short}type", $entries, $entry * 12);
                if ($field['tag'] !== 256 && $field['tag'] !== 257) {
                    continue;
                }

                /** @var array{v: int} $value */
                $value = unpack($field['type'] === 3 ? "{$short}v" : "{$long}v", $entries, $entry * 12 + 8);
                if ($field['type'] !== 3 && $field['type'] !== 4) {
                    return null;
                }

                $size[$field['tag']] = $value['v'];
            }

            // A directory without both tags (a thumbnail table, private
            // data) is no page a decoder can render; skip it. A chain with no
            // measured page at all stays undetermined.
            if (
                $size[256] > 0
                && $size[257] > 0
                && ($largest === null || $size[256] * $size[257] > $largest[0] * $largest[1])
            ) {
                $largest = [$size[256], $size[257]];
            }

            /** @var array{next: int} $next */
            $next = unpack("{$long}next", $entries, $count['n'] * 12);
            $offset = $next['next'];
        }

        return $largest;
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
