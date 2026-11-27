<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Closure;
use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Runs another process's work at an exact point inside this one.
 *
 * A collector paused between claiming a lease and deleting, or between reading
 * the referenced set and listing objects, is what a GC pause, a slow network
 * or a descheduled worker produces. Each hook fires once, before the delegated
 * call, so the interleaving is deterministic.
 *
 * @internal
 */
final class InterleavingStore implements MaintenanceStoreInterface
{
    private ?Closure $beforeDeleteObject = null;
    private ?Closure $beforeObjects = null;

    public function __construct(private readonly MaintenanceStoreInterface $store) {}

    public function onFirstDeleteObject(Closure $hook): void
    {
        $this->beforeDeleteObject = $hook;
    }

    public function onFirstObjects(Closure $hook): void
    {
        $this->beforeObjects = $hook;
    }

    #[Override]
    public function name(): string
    {
        return $this->store->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->store->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->store->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->store->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->store->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->store->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->store->stream($file);
    }

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        $hook = $this->beforeObjects;
        $this->beforeObjects = null;
        if ($hook !== null) {
            $hook();
        }

        return $this->store->objects($afterPath, $limit);
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        $hook = $this->beforeDeleteObject;
        $this->beforeDeleteObject = null;
        if ($hook !== null) {
            $hook($object);
        }

        $this->store->deleteObject($object);
    }
}
