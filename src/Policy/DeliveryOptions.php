<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;

/**
 * The response shape a file must be delivered with, wherever it is served from.
 *
 * Passed to a store that can presign so an S3 URL carries the same
 * disposition and content type the application would have sent itself. A
 * provider that cannot encode them returns null instead of a URL, and delivery
 * falls back to the proxy — a weaker URL is preferable to one that serves an
 * HTML file inline from your bucket.
 *
 * `forceDownload` is true for every {@see ActiveMediaTypes} type, whatever the
 * group's policy says.
 *
 * @api
 */
final readonly class DeliveryOptions
{
    /** @var non-empty-string */
    public const string DEFAULT_MEDIA_TYPE = 'application/octet-stream';

    /** @var non-empty-string */
    public const string DEFAULT_DOWNLOAD_NAME = 'file';

    /**
     * @param non-empty-string $responseMediaType
     * @param non-empty-string $downloadName
     */
    private function __construct(public bool $forceDownload, public string $responseMediaType, public string $downloadName) {}

    public static function fromFile(File $file, DeliveryPolicy $policy): self
    {
        // A filename reaches a response header, and a header cannot contain a
        // line break without becoming two headers.
        $name = str_replace(["\r", "\n", "\0"], '', $file->originalName);

        $mediaType = $file->mimeType ?? self::DEFAULT_MEDIA_TYPE;

        return new self(
            forceDownload: self::forceDownload($policy, $mediaType),
            responseMediaType: $mediaType,
            downloadName: $name === '' ? self::DEFAULT_DOWNLOAD_NAME : $name,
        );
    }

    /**
     * The delivery shape of a derivative rendition: the group's policy still
     * governs the disposition, but the media type and the extension come from
     * the rendition itself — a WebP thumbnail of a JPEG upload is served as
     * WebP, under the original's name wearing the rendition's extension.
     */
    public static function forVariant(File $file, DeliveryPolicy $policy, DerivativeDescriptor $derivative): self
    {
        $name = str_replace(["\r", "\n", "\0"], '', $file->originalName);
        $base = pathinfo($name, PATHINFO_FILENAME);

        return new self(
            forceDownload: self::forceDownload($policy, $derivative->mediaType),
            responseMediaType: $derivative->mediaType,
            downloadName: ($base === '' ? self::DEFAULT_DOWNLOAD_NAME : $base) . '.' . $derivative->extension,
        );
    }

    /**
     * Active content is an attachment whatever the policy says. The policy
     * chooses between "download" and "display", not between "safe" and
     * "unsafe": a presigned URL serving an SVG inline from a bucket on your
     * domain is stored XSS (review 1.0 A5), and `X-Content-Type-Options` cannot
     * be set on one.
     */
    private static function forceDownload(DeliveryPolicy $policy, string $mediaType): bool
    {
        return $policy->forceDownload || (new ActiveMediaTypes())->contains($mediaType);
    }
}
