<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store with no `MaintenanceStoreInterface`: it cannot be enumerated, so
 * orphans in it cannot be found. Real adapters like this exist — an API-backed
 * store with no listing endpoint — and `gc` has to say so rather than report
 * "no orphans" about a store it never looked at.
 *
 * @internal
 */
final readonly class UnwalkableStore implements StoreInterface
{
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
        throw new \LogicException('not needed');
    }

    #[Override]
    public function delete(File $file): void {}

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
