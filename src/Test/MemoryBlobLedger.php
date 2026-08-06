<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Test;

use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\LedgerException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;

/**
 * The blob ledger in an array, for tests.
 *
 * Enforces exactly the same state machine as the database implementation —
 * revival, reservation expiry, lease stealing, conditional completion — so a
 * consumer can exercise a deduplicating flow, including its failure branches,
 * without a database. What it deliberately does not reproduce is concurrency:
 * a single PHP array has no isolation levels, so a test that needs to prove two
 * writers race correctly needs the real thing.
 *
 * @psalm-type BlobRow = array{
 *     blob: BlobId,
 *     hash: non-empty-string,
 *     size: int<0, max>,
 *     state: BlobState,
 *     references: int<0, max>,
 *     reservations: array<non-empty-string, DateTimeImmutable>,
 *     deleteAfter: DateTimeImmutable|null,
 *     lease: BlobToken|null,
 *     leaseExpiresAt: DateTimeImmutable|null
 * }
 *
 * @api
 */
final class MemoryBlobLedger implements BlobLedgerInterface
{
    /**
     * @var array<non-empty-string, BlobRow>
     */
    private array $blobs = [];

    /** @var array<non-empty-string, non-empty-string> File id to blob key. */
    private array $fileBlobs = [];

    public function __construct(private readonly RepositoryInterface $repository) {}

    #[Override]
    public function reserve(
        BlobId $blob,
        string $contentHash,
        int $size,
        DateTimeImmutable $expiresAt,
    ): BlobReservation {
        $key = $blob->key();
        $token = BlobToken::random();
        $row = $this->blobs[$key] ?? null;

        if ($row === null) {
            $this->blobs[$key] = [
                'blob' => $blob,
                'hash' => $contentHash,
                'size' => $size,
                'state' => BlobState::Writing,
                'references' => 0,
                'reservations' => [$token->value => $expiresAt],
                'deleteAfter' => null,
                'lease' => null,
                'leaseExpiresAt' => null,
            ];

            return new BlobReservation($blob, $token, $expiresAt);
        }

        if (!$row['state']->isJoinable()) {
            throw new BlobBusyException(
                "Blob \"{$key}\" is being deleted. Retry once the deletion lease expires",
            );
        }
        if ($row['hash'] !== $contentHash || $row['size'] !== $size) {
            throw new LedgerException(
                "Blob \"{$key}\" already holds different content"
                . " (hash {$row['hash']}, {$row['size']} bytes)",
            );
        }

        $row['reservations'][$token->value] = $expiresAt;
        $row['deleteAfter'] = null;
        if ($row['state'] === BlobState::PendingDelete) {
            $row['state'] = $row['references'] > 0 ? BlobState::Active : BlobState::Writing;
        }
        $this->blobs[$key] = $row;

        return new BlobReservation($blob, $token, $expiresAt);
    }

    #[Override]
    public function commit(BlobReservation $reservation, File $file): void
    {
        $key = $reservation->blob->key();
        $row = $this->blobs[$key] ?? null;

        if ($row === null || !isset($row['reservations'][$reservation->token->value])) {
            throw new LedgerException("Unknown reservation for blob \"{$key}\"");
        }
        if (
            $file->storeName !== $reservation->blob->storeName
            || $file->relativePath !== $reservation->blob->relativePath()
        ) {
            throw new LedgerException(
                "File \"{$file->id}\" does not point at blob \"{$key}\"",
            );
        }

        $this->repository->save($file);

        unset($row['reservations'][$reservation->token->value]);
        $row['references']++;
        $row['state'] = BlobState::Active;
        $row['deleteAfter'] = null;
        $this->blobs[$key] = $row;
        $this->fileBlobs[$file->id] = $key;
    }

    #[Override]
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void
    {
        $key = $reservation->blob->key();
        $row = $this->blobs[$key] ?? null;
        if ($row === null) {
            return;
        }

        unset($row['reservations'][$reservation->token->value]);
        $this->blobs[$key] = $this->schedule($row, $deleteAfter);
    }

    #[Override]
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool
    {
        if (!$this->repository->delete($fileId)) {
            return false;
        }

        $key = $this->fileBlobs[$fileId] ?? null;
        unset($this->fileBlobs[$fileId]);
        if ($key === null || !isset($this->blobs[$key])) {
            return true;
        }

        $row = $this->blobs[$key];
        $row['references'] = max(0, $row['references'] - 1);
        $this->blobs[$key] = $this->schedule($row, $deleteAfter);

        return true;
    }

    #[Override]
    public function find(BlobId $blob): ?BlobRecord
    {
        $row = $this->blobs[$blob->key()] ?? null;

        return $row === null ? null : $this->toRecord($row);
    }

    #[Override]
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int
    {
        $removed = 0;
        foreach ($this->blobs as $key => $row) {
            foreach ($row['reservations'] as $token => $expiresAt) {
                if ($expiresAt <= $now) {
                    unset($row['reservations'][$token]);
                    $removed++;
                }
            }
            $this->blobs[$key] = $this->schedule($row, $deleteAfter);
        }

        return $removed;
    }

    #[Override]
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease
    {
        foreach ($this->blobs as $key => $row) {
            if ($row['references'] > 0 || $row['reservations'] !== []) {
                continue;
            }

            $claimable = match ($row['state']) {
                BlobState::PendingDelete => $row['deleteAfter'] !== null && $row['deleteAfter'] <= $now,
                // stealing an abandoned lease is how a crashed collector is recovered
                BlobState::Deleting => $row['leaseExpiresAt'] !== null && $row['leaseExpiresAt'] <= $now,
                default => false,
            };
            if (!$claimable) {
                continue;
            }

            $token = BlobToken::random();
            $row['state'] = BlobState::Deleting;
            $row['lease'] = $token;
            $row['leaseExpiresAt'] = $leaseExpiresAt;
            $this->blobs[$key] = $row;

            return new BlobLease($row['blob'], $token, $leaseExpiresAt);
        }

        return null;
    }

    #[Override]
    public function completeDeletion(BlobLease $lease): bool
    {
        $key = $lease->blob->key();
        if (!$this->holdsLease($key, $lease)) {
            return false;
        }

        $row = $this->blobs[$key];
        if ($row['references'] > 0 || $row['reservations'] !== []) {
            return false;
        }

        unset($this->blobs[$key]);

        return true;
    }

    #[Override]
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool
    {
        $key = $lease->blob->key();
        if (!$this->holdsLease($key, $lease)) {
            return false;
        }

        $row = $this->blobs[$key];
        $row['state'] = BlobState::PendingDelete;
        $row['deleteAfter'] = $retryAfter;
        $row['lease'] = null;
        $row['leaseExpiresAt'] = null;
        $this->blobs[$key] = $row;

        return true;
    }

    /**
     * @return list<BlobRecord>
     */
    public function records(): array
    {
        return array_values(array_map($this->toRecord(...), $this->blobs));
    }

    public function clear(): void
    {
        $this->blobs = [];
        $this->fileBlobs = [];
    }

    /**
     * @param BlobRow $row
     *
     * @return BlobRow
     */
    private function schedule(array $row, DateTimeImmutable $deleteAfter): array
    {
        // A blob under an active deletion lease keeps its lease: only the
        // holder, or the expiry, decides what happens to it next.
        if ($row['state'] === BlobState::Deleting) {
            return $row;
        }

        if ($row['references'] > 0 || $row['reservations'] !== []) {
            return $row;
        }

        $row['state'] = BlobState::PendingDelete;
        $row['deleteAfter'] = $deleteAfter;

        return $row;
    }

    private function holdsLease(string $key, BlobLease $lease): bool
    {
        $row = $this->blobs[$key] ?? null;

        return $row !== null
            && $row['state'] === BlobState::Deleting
            && $row['lease']?->equals($lease->token) === true;
    }

    /**
     * @param BlobRow $row
     */
    private function toRecord(array $row): BlobRecord
    {
        return new BlobRecord(
            blob: $row['blob'],
            contentHash: $row['hash'],
            size: $row['size'],
            state: $row['state'],
            referenceCount: $row['references'],
            reservationCount: \count($row['reservations']),
            deleteAfter: $row['deleteAfter'],
            leaseExpiresAt: $row['leaseExpiresAt'],
        );
    }
}
