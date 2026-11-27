<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Repository;

use Rasuvaeff\Yii3Filestorage\File;

/**
 * A repository that operations commands can walk and amend.
 *
 * Separate from the hot path for the same reason as
 * {@see \Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface}: `verify`,
 * `stat` and `backfill-hash` need to page through every row, and that is not
 * something every implementation of a three-method interface should have to
 * provide. It is declared before the first release so growing it later is not
 * a breaking change for third-party repositories.
 *
 * @api
 */
interface MaintenanceRepositoryInterface extends RepositoryInterface
{
    /**
     * One resumable page, ordered by id.
     *
     * @param non-empty-string|null $afterId Exclusive cursor.
     * @param int<1, max> $limit
     *
     * @return iterable<int, File>
     */
    public function files(?string $afterId = null, int $limit = 1000): iterable;

    /**
     * The relative paths of this store's rows that live in any of the given
     * directories — rows whose {@see File::directory()} is one of them.
     *
     * What the orphan sweep asks per listing page, after the page is listed,
     * instead of reading every row up front. Paths rather than a yes/no per
     * directory because the store's layout decides which object a row owns:
     * under a flat layout one directory holds many files.
     *
     * A SQL implementation matches `relative_path LIKE '<dir>/%'` (or equals
     * `<dir>` for a root-level row) and compares the directory exactly; an
     * index on `(store_name, relative_path)` keeps it a range scan.
     *
     * @param non-empty-string $storeName
     * @param non-empty-list<non-empty-string> $directories
     *
     * @return list<non-empty-string>
     */
    public function referencedPaths(string $storeName, array $directories): array;

    /**
     * @param non-empty-string $id
     * @param non-empty-string $contentHash Lowercase SHA-256 hex.
     *
     * @return bool False when there was no such row.
     */
    public function updateContentHash(string $id, string $contentHash): bool;
}
