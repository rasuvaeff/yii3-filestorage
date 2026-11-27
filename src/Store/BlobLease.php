<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;

/**
 * Exclusive permission to delete one blob's bytes, held for a bounded time.
 *
 * Unlike a reservation there is at most one of these per blob: deletion is the
 * single operation that must not run twice concurrently, because the second
 * collector would be deleting an object the first one has already replaced
 * through a revived write.
 *
 * The expiry is what makes a crashed collector recoverable. A lease that runs
 * out is simply reclaimable by the next collector, which then repeats an
 * idempotent physical delete — including the case where the bytes are already
 * gone but the ledger row survived the crash.
 *
 * The lease guards the ledger row, not the physical delete. A collector that
 * was merely *paused* past the expiry does not know it has been superseded:
 * if a writer re-published the same key in the meantime, its delete removes
 * live bytes, and only the ledger row survives because `completeDeletion()`
 * refuses the stale token. `filestorage:gc` therefore skips the delete when
 * less than half of the lease is left, which narrows the window without
 * closing it.
 *
 * @api
 */
final readonly class BlobLease
{
    public function __construct(
        public BlobId $blob,
        public BlobToken $token,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
