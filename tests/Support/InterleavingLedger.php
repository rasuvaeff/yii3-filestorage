<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use Closure;
use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;

/**
 * Runs another process's work right after a lease is claimed.
 *
 * A collector descheduled between claiming a blob and deleting it is the pause
 * the lease-freshness check exists for. The hook fires once, after the
 * delegated claim returns a lease, so the interleaving is deterministic.
 *
 * @internal
 */
final class InterleavingLedger implements BlobLedgerInterface
{
    private ?Closure $afterClaim = null;

    public function __construct(private readonly BlobLedgerInterface $ledger) {}

    private bool $claims = true;

    /**
     * Nothing is ever claimable, so a run exercises everything except the
     * collection pass.
     */
    public function withoutClaims(): void
    {
        $this->claims = false;
    }

    public function onFirstClaim(Closure $hook): void
    {
        $this->afterClaim = $hook;
    }

    #[Override]
    public function reserve(BlobId $blob, string $contentHash, int $size, DateTimeImmutable $expiresAt): BlobReservation
    {
        return $this->ledger->reserve($blob, $contentHash, $size, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        $this->ledger->commit($reservation, $file);
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        $this->ledger->release($reservation, $deleteAfter);
    }

    #[Override]
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool
    {
        return $this->ledger->releaseFile($fileId, $deleteAfter);
    }

    #[Override]
    public function find(BlobId $blob): ?BlobRecord
    {
        return $this->ledger->find($blob);
    }

    #[Override]
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int
    {
        return $this->ledger->expireReservations($now, $deleteAfter);
    }

    #[Override]
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease
    {
        if (!$this->claims) {
            return null;
        }

        $lease = $this->ledger->claimForDeletion($now, $leaseExpiresAt);

        $hook = $this->afterClaim;
        if ($lease instanceof BlobLease && $hook instanceof Closure) {
            $this->afterClaim = null;
            $hook($lease);
        }

        return $lease;
    }

    #[Override]
    public function completeDeletion(BlobLease $lease): bool
    {
        return $this->ledger->completeDeletion($lease);
    }

    #[Override]
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool
    {
        return $this->ledger->abandonDeletion($lease, $retryAfter);
    }
}
