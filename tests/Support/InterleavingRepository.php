<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Closure;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;

/**
 * Runs another process's work just before the first metadata save, which is
 * the window between an upload publishing its bytes and its row existing.
 *
 * @internal
 */
final class InterleavingRepository implements MaintenanceRepositoryInterface
{
    private ?Closure $beforeSave = null;

    public function __construct(private readonly MaintenanceRepositoryInterface $repository) {}

    public function onFirstSave(Closure $hook): void
    {
        $this->beforeSave = $hook;
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->repository->find($id);
    }

    #[Override]
    public function save(File $file): void
    {
        $hook = $this->beforeSave;
        $this->beforeSave = null;
        if ($hook !== null) {
            $hook($file);
        }

        $this->repository->save($file);
    }

    #[Override]
    public function delete(string $id): bool
    {
        return $this->repository->delete($id);
    }

    #[Override]
    public function files(?string $afterId = null, int $limit = 1000): iterable
    {
        return $this->repository->files($afterId, $limit);
    }

    #[Override]
    public function updateContentHash(string $id, string $contentHash): bool
    {
        return $this->repository->updateContentHash($id, $contentHash);
    }
}
