<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;

/**
 * A store that can hand out URLs pointing straight at the object.
 *
 * Both methods are nullable, and that is the design: support is a *result*,
 * not a PHP type that appears conditionally at runtime. One `FlysystemStore`
 * class therefore wraps every adapter — it delegates, catches the
 * unsupported-operation exceptions and returns null — instead of the class
 * hierarchy changing shape depending on what is in `vendor/`.
 *
 * `temporaryUrl()` also returns null when it cannot encode the response
 * headers {@see DeliveryOptions} demands. A presigned URL that serves an HTML
 * file inline from your bucket is worse than no presigned URL: delivery then
 * falls back to the application proxy, which can enforce them.
 *
 * @api
 */
interface StoreUrlProviderInterface extends StoreInterface
{
    /**
     * @return non-empty-string|null Permanent object URL, or null when unavailable.
     */
    public function publicUrl(File $file): ?string;

    /**
     * @return non-empty-string|null Store-native presigned URL honouring the
     *         delivery options, or null when unavailable or unsupported.
     */
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string;
}
