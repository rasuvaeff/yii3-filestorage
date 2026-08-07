<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Id;

use Override;
use Ramsey\Uuid\Uuid;

/**
 * UUIDv7 from `ramsey/uuid`, for applications already standardised on it.
 *
 * Requires `ramsey/uuid` — an optional dependency, so this class is only
 * loadable when the library is installed. Bind it explicitly; see
 * {@see SymfonyUidIdGenerator} for why there is no auto-detection.
 *
 * @api
 */
final readonly class RamseyUuidIdGenerator implements IdGeneratorInterface
{
    #[Override]
    public function generate(): string
    {
        /** @var non-empty-string */
        return Uuid::uuid7()->toString();
    }
}
