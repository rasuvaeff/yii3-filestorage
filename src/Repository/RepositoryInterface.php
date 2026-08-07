<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Repository;

use Rasuvaeff\Yii3Filestorage\File;

/**
 * Where file metadata lives.
 *
 * There is no "config-only" implementation of this, unlike the settings and
 * feature-flag packages: file metadata cannot come from a config file. With no
 * backend installed the fallback is
 * {@see \Rasuvaeff\Yii3Filestorage\Test\MemoryRepository}, which the
 * application must bind explicitly and which is for development only.
 *
 * @api
 */
interface RepositoryInterface
{
    /**
     * @param non-empty-string $id
     *
     * @return File|null Null rather than an exception, consistently with `yiisoft/*`
     *         repositories: "not found" is an ordinary answer, not a failure.
     */
    public function find(string $id): ?File;

    /**
     * Returns nothing: the id was assigned before the write, so there is
     * nothing for the repository to give back.
     */
    public function save(File $file): void;

    /**
     * @param non-empty-string $id
     *
     * @return bool False when there was no such row.
     */
    public function delete(string $id): bool;
}
