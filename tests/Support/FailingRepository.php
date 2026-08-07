<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use RuntimeException;

/**
 * A repository whose write always fails, so the compensation path in
 * {@see \Rasuvaeff\Yii3Filestorage\Storage::add()} can be exercised.
 *
 * @internal
 */
final class FailingRepository implements RepositoryInterface
{
    #[Override]
    public function find(string $id): ?File
    {
        return null;
    }

    #[Override]
    public function save(File $file): void
    {
        throw new RuntimeException('database is down');
    }

    #[Override]
    public function delete(string $id): bool
    {
        return false;
    }
}
