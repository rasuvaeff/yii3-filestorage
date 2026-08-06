<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Mime;

use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Determines the authoritative media type of an upload from its own bytes.
 *
 * Implementations must never fall back to the client-supplied media type:
 * that value crosses a trust boundary and a caller controls it completely.
 * Unknown stays unknown, and a non-empty allow-list then rejects it.
 *
 * @api
 */
interface MimeTypeDetectorInterface
{
    /**
     * @return non-empty-string|null Null when the type could not be established.
     */
    public function detect(Upload $upload): ?string;
}
