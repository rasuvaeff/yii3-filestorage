<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Policy;

use Rasuvaeff\Yii3Filestorage\File;

/**
 * The response shape a file must be delivered with, wherever it is served from.
 *
 * Passed to a store that can presign so an S3 URL carries the same
 * disposition and content type the application would have sent itself. A
 * provider that cannot encode them returns null instead of a URL, and delivery
 * falls back to the proxy — a weaker URL is preferable to one that serves an
 * HTML file inline from your bucket.
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

        return new self(
            forceDownload: $policy->forceDownload,
            responseMediaType: $file->mimeType ?? self::DEFAULT_MEDIA_TYPE,
            downloadName: $name === '' ? self::DEFAULT_DOWNLOAD_NAME : $name,
        );
    }
}
