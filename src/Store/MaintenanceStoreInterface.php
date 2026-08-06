<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Rasuvaeff\Yii3Filestorage\Exception\StoreException;

/**
 * A store that can be walked and pruned by operations commands.
 *
 * Kept off the hot-path contract on purpose: garbage collection, verification
 * and statistics need to enumerate objects, and demanding that of every store —
 * including one backed by a bucket where listing is slow and eventually
 * consistent — would tax every upload for the benefit of a nightly command.
 * A command that cannot find this capability exits with a diagnostic rather
 * than reaching for reflection or a backend-specific downcast.
 *
 * @api
 */
interface MaintenanceStoreInterface extends StoreInterface
{
    /**
     * One page of the object inventory, ordered so the cursor is resumable.
     *
     * @param non-empty-string|null $afterPath Exclusive cursor: resume after this path.
     * @param int<1, max> $limit
     *
     * @return iterable<int, StoredObjectId>
     *
     * @throws StoreException
     */
    public function objects(?string $afterPath = null, int $limit = 1000): iterable;

    /**
     * Idempotent: deleting something already gone is a success, because a
     * retried garbage collection must converge rather than fail.
     *
     * @throws StoreException
     */
    public function deleteObject(StoredObjectId $object): void;
}
