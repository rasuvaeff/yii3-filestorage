<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

/**
 * Where a shared physical object is in its lifecycle.
 *
 * Four states rather than a boolean "deleted" flag, because every interesting
 * failure in deduplication happens between two of them: bytes exist but no row
 * references them yet, or the last row is gone but the bytes are still being
 * removed. A writer that only knew "present / absent" would have to guess which
 * of those it is looking at, and the guess is what corrupts data.
 *
 * @api
 */
enum BlobState: string
{
    /** Reserved, bytes not confirmed published yet. Reservations keep it alive. */
    case Writing = 'writing';

    /** At least one committed file row references the bytes. */
    case Active = 'active';

    /** Nothing references the bytes; they may be collected after `delete_after`. */
    case PendingDelete = 'pending_delete';

    /** A garbage collector holds a lease and is removing the bytes right now. */
    case Deleting = 'deleting';

    /**
     * A blob a new writer may join instead of having to wait.
     *
     * `Deleting` is the exception: joining it would race the collector that is
     * already removing the object, and the winner of that race decides whether
     * a committed row points at bytes that exist.
     */
    public function isJoinable(): bool
    {
        return $this !== self::Deleting;
    }
}
