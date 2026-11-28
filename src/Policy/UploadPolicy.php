<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\UnsupportedMediaTypeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceAuthority;
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
     * Bounded prefix handed to the dimension reader for every format but JPEG,
     * which is walked segment by segment instead and has no such window. PNG,
     * GIF and WebP carry their dimensions in the first few dozen bytes.
     */
    public const int MAX_IMAGE_HEADER_BYTES = 262_144;

    /** @var list<non-empty-string> */
    public array $allowedMimeTypes;

    public ReferenceAuthority $referencesOwnedBy;

    /**
     * @param list<string> $allowedMimeTypes Empty list accepts anything.
     *        Matching is against the *detected* type, so a null detection fails a
     *        non-empty list.
     * @param int $maxBytes 0 means no limit.
     * @param int $maxPixels Decompression-bomb guard for raster images: a
     *        1 KiB file can declare 60000×60000 and flatten any decoder that
     *        opens it later. Read from the header; pixels are never decoded.
     *        A JPEG is walked marker by marker to its frame header, however
     *        much metadata precedes it. A JPEG, PNG, GIF or WebP whose
     *        dimensions cannot be established is refused while this is
     *        non-zero. 0 disables the check.
     * @param bool $requireImageDimensions Also reject an image in *any* raster
     *        format whose dimensions cannot be established, instead of letting
     *        it through unmeasured. Off by default because formats the runtime
     *        cannot parse (AVIF, HEIC on older builds) would otherwise be
     *        rejected wholesale; turn it on for a group that must be safe to
     *        hand to an image processor.
     * @param ReferenceAuthority|null $referencesOwnedBy Who decides the group's
     *        files are still in use. Null is {@see ReferenceAuthority::Rows}:
     *        a row lives until the application removes it.
     */
    public function __construct(
        array $allowedMimeTypes = [],
        public int $maxBytes = 0,
        public int $maxPixels = 40_000_000,
        public bool $requireImageDimensions = false,
        ?ReferenceAuthority $referencesOwnedBy = null,
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
        $this->referencesOwnedBy = $referencesOwnedBy ?? ReferenceAuthority::Rows;
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
        if ($this->allowedMimeTypes !== [] && !in_array($detectedMimeType, $this->allowedMimeTypes, strict: true)) {
            $allowed = implode(', ', $this->allowedMimeTypes);

            throw new UnsupportedMediaTypeException(
                'Media type ' . ($detectedMimeType ?? '<unrecognised>')
                . " is not accepted here. Allowed: {$allowed}",
            );
        }

        $size = $upload->size();
        if ($this->maxBytes > 0 && $size !== null && $size > $this->maxBytes) {
            throw new UploadSizeException(
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
        if ($detectedMimeType === null || !$this->isRaster($detectedMimeType)) {
            return;
        }

        $size = ImageDimensions::measure(
            stream: $upload->stream(),
            mediaType: $detectedMimeType,
            headerBytes: self::MAX_IMAGE_HEADER_BYTES,
        );
        if ($size === null) {
            // Fail closed for the formats the guard can always read: a JPEG,
            // PNG, GIF or WebP whose header cannot be found is malformed or
            // built to hide it, and letting it through unmeasured is the
            // bypass (review 1.0 A1). Formats the runtime may not parse stay
            // opt-in through requireImageDimensions.
            if ($this->requireImageDimensions) {
                throw new PolicyViolationException(
                    "Image dimensions could not be established for {$detectedMimeType}, and this group requires them",
                );
            }
            if ($this->maxPixels > 0 && ImageDimensions::isParseable($detectedMimeType)) {
                throw new PolicyViolationException(
                    "Image dimensions could not be established for {$detectedMimeType}; "
                    . 'a malformed header cannot be checked against the pixel limit',
                );
            }

            return;
        }

        [$width, $height] = $size;
        $pixels = $width * $height;
        if ($this->maxPixels > 0 && $pixels > $this->maxPixels) {
            throw new PolicyViolationException(
                "Image is {$width}x{$height} = {$pixels} pixels, over the {$this->maxPixels} pixel limit for this group",
            );
        }
    }

    private function isRaster(string $mediaType): bool
    {
        return str_starts_with($mediaType, 'image/') && $mediaType !== 'image/svg+xml';
    }
}
