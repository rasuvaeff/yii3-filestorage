<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Test;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;

/**
 * Metadata in an array, for tests and for a first run with no backend installed.
 *
 * Not for production: an application binding this loses every file record when
 * the process ends. It exists so `composer require rasuvaeff/yii3-filestorage`
 * plus five lines of config gives a working upload, and so consumers can test
 * their own upload flows without a database.
 *
 * @api
 */
final class MemoryRepository implements MaintenanceRepositoryInterface
{
    /** @var array<non-empty-string, File> */
    private array $files = [];

    #[Override]
    public function find(string $id): ?File
    {
        return $this->files[$id] ?? null;
    }

    #[Override]
    public function save(File $file): void
    {
        $this->files[$file->id] = $file;
    }

    #[Override]
    public function delete(string $id): bool
    {
        if (!isset($this->files[$id])) {
            return false;
        }

        unset($this->files[$id]);

        return true;
    }

    #[Override]
    public function files(?string $afterId = null, int $limit = 1000): iterable
    {
        $ids = array_keys($this->files);
        sort($ids);

        $yielded = 0;
        foreach ($ids as $id) {
            if ($afterId !== null && strcmp($id, $afterId) <= 0) {
                continue;
            }

            yield $this->files[$id];

            if (++$yielded >= $limit) {
                return;
            }
        }
    }

    #[Override]
    public function updateContentHash(string $id, string $contentHash): bool
    {
        $file = $this->files[$id] ?? null;
        if ($file === null) {
            return false;
        }

        $this->files[$id] = File::create(
            id: $file->id,
            storeName: $file->storeName,
            groupName: $file->groupName,
            relativePath: $file->relativePath,
            originalName: $file->originalName,
            size: $file->size,
            createdAt: $file->createdAt,
            externalId: $file->externalId,
            mimeType: $file->mimeType,
            description: $file->description,
            contentHash: $contentHash,
            metadata: $file->metadata,
            updatedAt: $file->updatedAt,
        );

        return true;
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->files);
    }

    /**
     * @return list<File>
     */
    public function all(): array
    {
        return array_values($this->files);
    }

    public function clear(): void
    {
        $this->files = [];
    }
}
