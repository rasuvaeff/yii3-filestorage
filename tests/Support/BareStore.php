<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store with no optional capability whatsoever.
 *
 * Every other double here implements at least `MaintenanceStoreInterface`, so
 * nothing could exercise the report's "base only" branch — an assertion about
 * it passed because the store under test happened to have the capability being
 * looked for.
 *
 * @internal
 */
final readonly class BareStore implements StoreInterface
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(private string $name) {}

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        throw new StoreException('BareStore does not store anything');
    }

    #[Override]
    public function delete(File $file): void
    {
        throw new StoreException('BareStore does not store anything');
    }

    #[Override]
    public function exists(File $file): bool
    {
        return false;
    }

    #[Override]
    public function size(File $file): ?int
    {
        return null;
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return null;
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return null;
    }
}
