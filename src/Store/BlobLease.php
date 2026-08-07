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
