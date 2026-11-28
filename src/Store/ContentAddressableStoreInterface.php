<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Rasuvaeff\Yii3Filestorage\Exception\SizeLimitExceededException;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store whose writes to a content-addressed key are immutable and atomic.
 *
 * Required by deduplication and by nothing else. The base facade never reaches
 * this contract, because sharing bytes between logical files is only safe when
 * a ledger counts references — see the dedup design in
 * `rasuvaeff/yii3-filestorage-db`.
 *
 * "Atomic" is the load-bearing word. Without atomic visibility, a
 * `fileExists()` followed by a `write()` is not an implementation of this
 * interface: two callers race, one observes a half-written object and reuses
 * it, and the file that comes back is corrupt. With atomic visibility — a
 * reader sees the whole object or none — the same check-then-write is a valid
 * implementation. An adapter that cannot promise atomic publication simply does
 * not implement this — it stays a perfectly good store that cannot enable
 * dedup, and `filestorage:check` names the missing capability.
 *
 * The guarantee is atomic publication of identical content, not a single
 * creator. Two writers that both find the key absent may both publish the same
 * bytes and both report {@see StoreResult::$created} as true. The ledger's
 * reservations and references decide when bytes may be deleted, never
 * `created`.
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
     * @throws SizeLimitExceededException
     */
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult;
}
