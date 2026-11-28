<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerBatchLookupInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;

/**
 * Counts the lookups `gc` makes, around a real ledger.
 *
 * @internal
 */
final class CountingLedger implements BlobLedgerBatchLookupInterface
{
    public int $finds = 0;

    /** @var list<int> Size of each batch. */
    public array $batches = [];

    public function __construct(private readonly MemoryBlobLedger $inner) {}

    #[Override]
    public function findMany(array $blobs): array
    {
        $this->batches[] = \count($blobs);

        return $this->inner->findMany($blobs);
    }

    #[Override]
    public function find(BlobId $blob): ?BlobRecord
    {
        $this->finds++;

        return $this->inner->find($blob);
    }

    #[Override]
    public function reserve(BlobId $blob, string $contentHash, int $size, DateTimeImmutable $expiresAt): BlobReservation
    {
        return $this->inner->reserve($blob, $contentHash, $size, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        $this->inner->commit($reservation, $file);
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        $this->inner->release($reservation, $deleteAfter);
    }

    #[Override]
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool
    {
        return $this->inner->releaseFile($fileId, $deleteAfter);
    }

    #[Override]
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int
    {
        return $this->inner->expireReservations($now, $deleteAfter);
    }

    #[Override]
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease
    {
        return $this->inner->claimForDeletion($now, $leaseExpiresAt);
    }

    #[Override]
    public function completeDeletion(BlobLease $lease): bool
    {
        return $this->inner->completeDeletion($lease);
    }

    #[Override]
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool
    {
        return $this->inner->abandonDeletion($lease, $retryAfter);
    }
}
