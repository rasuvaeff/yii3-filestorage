<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;

/**
 * One writer's claim on a blob while its bytes are being published.
 *
 * A reservation is not a lock. Several writers may hold one on the same blob
 * at the same time — they are, after all, uploading identical content — and
 * each is recorded separately so that one of them dying does not release the
 * others' claim. What it does guarantee is that garbage collection cannot
 * remove the object underneath a writer who has not committed yet.
 *
 * It expires. A process that dies between reserving and committing therefore
 * costs one grace period, not a permanently unreclaimable object.
 *
 * @api
 */
final readonly class BlobReservation
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
