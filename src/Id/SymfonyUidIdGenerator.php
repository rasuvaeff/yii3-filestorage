<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Id;

use Override;
use Symfony\Component\Uid\UuidV7;

/**
 * UUIDv7 from `symfony/uid`, for applications already standardised on it.
 *
 * Requires `symfony/uid` — an optional dependency, so this class is only
 * loadable when the library is installed. Nothing selects it automatically:
 * bind it explicitly, because silently switching generator based on what
 * happens to be in `vendor/` would be worse than an explicit default.
 *
 * @api
 */
final readonly class SymfonyUidIdGenerator implements IdGeneratorInterface
{
    #[Override]
    public function generate(): string
    {
        /** @var non-empty-string */
        return (new UuidV7())->toRfc4122();
    }
}
