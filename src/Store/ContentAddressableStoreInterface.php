<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store whose writes to a content-addressed key are immutable and atomic.
 *
 * Required by deduplication and by nothing else. The base facade never reaches
 * this contract, because sharing bytes between logical files is only safe when
 * a ledger counts references — see the dedup design in
 * `rasuvaeff/yii3-filestorage-db`.
 *
 * "Atomic" is the load-bearing word. A `fileExists()` followed by a `write()`
 * is not an implementation of this interface: two callers race, one observes a
 * half-written object and reuses it, and the file that comes back is corrupt.
 * An adapter that cannot promise atomic publication simply does not implement
 * this — it stays a perfectly good store that cannot enable dedup, and
 * `filestorage:check` names the missing capability.
 *
 * @api
 */
interface ContentAddressableStoreInterface extends StoreInterface
{
    /**
     * Publishes the upload at a content-addressed key, or reuses what is there.
     *
     * Existing bytes are reused only after a size check; a mismatch is a
     * {@see StoreException}, never a silent reuse.
     *
     * @param int $maxBytes Zero means no cap.
     *
     * @throws StoreException
     * @throws UploadTooLargeException
     */
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult;
}
