<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * A derivative-aware store that can also hand out URLs to a rendition.
 *
 * Separate from {@see StoreUrlProviderInterface} because the two answers differ:
 * a store may be able to presign originals and not renditions, or the other way
 * round when renditions live on a CDN-backed public prefix and originals do not.
 *
 * @api
 */
interface DerivativeUrlProviderInterface extends DerivativeAwareStoreInterface
{
    /**
     * @return non-empty-string|null
     */
    public function publicDerivativeUrl(File $file, DerivativeDescriptor $derivative): ?string;

    /**
     * @return non-empty-string|null
     */
    public function temporaryDerivativeUrl(
        File $file,
        DerivativeDescriptor $derivative,
        DateTimeImmutable $expiresAt,
    ): ?string;
}
