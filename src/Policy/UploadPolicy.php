<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * What one group accepts.
 *
 * A group is a use case, not a folder: avatars take small square images,
 * documents take PDFs, a public-content group must never take SVG. Without a
 * policy every caller re-implements the same checks before every `add()`, and
 * the caller that forgets is the security hole.
 *
 * This exists in the first release on purpose. Introducing acceptance rules
 * later turns uploads that used to succeed into failures — a behavioural break
 * that no version bump makes comfortable. The default is permissive; an
 * application tightens it whenever it wants.
 *
 * @api
 */
final readonly class UploadPolicy
{
    /**
     * Bounded prefix handed to the dimension reader. Large enough for a JPEG
     * that carries a fat EXIF block before its SOF marker, small enough that a
     * hostile upload cannot make the check itself expensive.
     */
    public const int MAX_IMAGE_HEADER_BYTES = 262_144;

    /** @var list<non-empty-string> */
    public array $allowedMimeTypes;

    /**
     * @param list<string> $allowedMimeTypes Empty list accepts anything.
     *        Matching is against the *detected* type, so a null detection fails a
     *        non-empty list.
     * @param int $maxBytes 0 means no limit.
     * @param int $maxPixels Decompression-bomb guard for raster images: a
     *        1 KiB file can declare 60000×60000 and flatten any decoder that
     *        opens it later. Read from the header; pixels are never decoded.
     *        0 disables the check.
     * @param bool $requireImageDimensions Reject a raster image whose dimensions
     *        cannot be established within the bounded prefix, instead of letting
     *        it through unmeasured. Off by default because formats the runtime
     *        cannot parse (AVIF, HEIC on older builds) would otherwise be
     *        rejected wholesale; turn it on for a group that must be safe to
     *        hand to an image processor.
     */
    public function __construct(
        array $allowedMimeTypes = [],
        public int $maxBytes = 0,
        public int $maxPixels = 40_000_000,
        public bool $requireImageDimensions = false,
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('maxBytes must not be negative');
        }
        if ($maxPixels < 0) {
            throw new InvalidArgumentException('maxPixels must not be negative');
        }

        $validated = [];
        foreach ($allowedMimeTypes as $mimeType) {
            if ($mimeType === '') {
                throw new InvalidArgumentException('Allowed media types must not be empty strings');
            }
            $validated[] = $mimeType;
        }

        $this->allowedMimeTypes = $validated;
    }

    /**
     * Checked before the store is touched, so a rejected upload writes nothing.
     *
     * Size is only checked here when the stream knows it. An unknown-length
     * stream is bounded by the same `maxBytes` inside
     * {@see \Rasuvaeff\Yii3Filestorage\Store\StoreInterface::write()}, which
     * counts bytes while copying and removes its partial output.
     *
     * @param non-empty-string|null $detectedMimeType Authoritative type, never the client hint.
     *
     * @throws PolicyViolationException
     */
    public function assertAcceptable(Upload $upload, ?string $detectedMimeType): void
    {
        if ($this->allowedMimeTypes !== [] && !in_array($detectedMimeType, $this->allowedMimeTypes, true)) {
            $allowed = implode(', ', $this->allowedMimeTypes);

            throw new PolicyViolationException(
                'Media type ' . ($detectedMimeType ?? '<unrecognised>')
                . " is not accepted here. Allowed: {$allowed}",
            );
        }

        $size = $upload->size();
        if ($this->maxBytes > 0 && $size !== null && $size > $this->maxBytes) {
            throw new PolicyViolationException(
                "Upload is {$size} bytes, over the {$this->maxBytes} byte limit for this group",
            );
        }

        $this->assertPixelsAcceptable($upload, $detectedMimeType);
    }

    /**
     * @param non-empty-string|null $detectedMimeType
     *
     * @throws PolicyViolationException
     */
    private function assertPixelsAcceptable(Upload $upload, ?string $detectedMimeType): void
    {
        if ($this->maxPixels === 0 && !$this->requireImageDimensions) {
            return;
        }
        if ($detectedMimeType === null || !self::isRaster($detectedMimeType)) {
            return;
        }

        $stream = $upload->stream();
        $header = $stream->read(self::MAX_IMAGE_HEADER_BYTES);
        $stream->rewind();

        // getimagesizefromstring() parses the header only and ships with PHP
        // itself (ext-standard, no ext-gd), so this guard costs no dependency
        // and never decodes a single pixel.
        $size = @getimagesizefromstring($header);
        if ($size === false) {
            if ($this->requireImageDimensions) {
                throw new PolicyViolationException(
                    "Image dimensions could not be established for {$detectedMimeType}, and this group requires them",
                );
            }

            return;
        }

        $pixels = $size[0] * $size[1];
        if ($this->maxPixels > 0 && $pixels > $this->maxPixels) {
            throw new PolicyViolationException(
                "Image is {$size[0]}x{$size[1]} = {$pixels} pixels, over the {$this->maxPixels} pixel limit for this group",
            );
        }
    }

    private static function isRaster(string $mediaType): bool
    {
        return str_starts_with($mediaType, 'image/') && $mediaType !== 'image/svg+xml';
    }
}
