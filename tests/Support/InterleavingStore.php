<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Closure;
use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Runs another process's work at an exact point inside this one.
 *
 * A collector paused between claiming a lease and quarantining, or before
 * listing objects, is what a GC pause, a slow network
 * or a descheduled worker produces. Each hook fires once, before the delegated
 * call, so the interleaving is deterministic.
 *
 * @internal
 */
final class InterleavingStore implements MaintenanceStoreInterface
{
    private ?Closure $beforeQuarantine = null;
    private ?Closure $beforeObjects = null;
    private bool $undated = false;

    /** @var array<string, true> Entries holding one of these paths cannot be restored or purged. */
    private array $stuck = [];

    public function refuseEntriesHolding(string $relativePath): void
    {
        $this->stuck[$relativePath] = true;
    }

    public function __construct(private readonly MaintenanceStoreInterface $store) {}

    public function onFirstQuarantine(Closure $hook): void
    {
        $this->beforeQuarantine = $hook;
    }

    /**
     * Lists every object without a timestamp, as an adapter that cannot tell does.
     */
    public function withoutTimestamps(): void
    {
        $this->undated = true;
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
    public function layout(): ObjectLayoutInterface
    {
        return $this->store->layout();
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
        if ($hook instanceof \Closure) {
            $hook();
        }

        if (!$this->undated) {
            return $this->store->objects($afterPath, $limit);
        }

        $objects = [];
        foreach ($this->store->objects($afterPath, $limit) as $object) {
            $objects[] = new StoredObject($object->id, null, $object->size);
        }

        return $objects;
    }

    #[Override]
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject
    {
        $hook = $this->beforeQuarantine;
        $this->beforeQuarantine = null;
        if ($hook instanceof \Closure) {
            $hook($objects);
        }

        return $this->store->quarantine($objects, $now);
    }

    #[Override]
    public function restore(QuarantinedObject $entry): void
    {
        $this->refuseStuck($entry);
        $this->store->restore($entry);
    }

    #[Override]
    public function purge(QuarantinedObject $entry): void
    {
        $this->refuseStuck($entry);
        $this->store->purge($entry);
    }

    private function refuseStuck(QuarantinedObject $entry): void
    {
        foreach ($entry->objects as $object) {
            if (isset($this->stuck[$object->relativePath])) {
                throw new StoreException("Permission denied on \"{$object->relativePath}\"");
            }
        }
    }

    #[Override]
    public function quarantined(?string $afterToken = null, int $limit = 1000): iterable
    {
        return $this->store->quarantined($afterToken, $limit);
    }

    #[Override]
    public function quarantineProblem(): ?string
    {
        return $this->store->quarantineProblem();
    }
}
