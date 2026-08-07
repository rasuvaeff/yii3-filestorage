<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A read-only snapshot of one ledger row.
 *
 * Returned by lookups and inventory walks so operations code — `gc`, `verify`,
 * `stat` — can reason about a blob without every command inventing its own row
 * shape. Deliberately not a mutable entity: every state change goes through a
 * conditional `BlobLedgerInterface` method that checks the counts in SQL, so a
 * snapshot that could be edited and saved back would be a way to lose exactly
 * the concurrency guarantee the ledger exists to provide.
 *
 * The constructor accepts plain `string` and `int` because these values arrive
 * from a database driver, which has no idea what a `non-empty-string` is. The
 * narrow types live on the properties, where they are true.
 *
 * @api
 */
final readonly class BlobRecord
{
    /** @var non-empty-string Lowercase SHA-256 hex. */
    public string $contentHash;

    /** @var int<0, max> */
    public int $size;

    /** @var int<0, max> Committed file rows pointing at these bytes. */
    public int $referenceCount;

    /** @var int<0, max> Live writer claims. */
    public int $reservationCount;

    /**
     * @param DateTimeImmutable|null $deleteAfter Set once nothing references the blob.
     * @param DateTimeImmutable|null $leaseExpiresAt Set while a collector holds the blob.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public BlobId $blob,
        string $contentHash,
        int $size,
        public BlobState $state,
        int $referenceCount,
        int $reservationCount,
        public ?DateTimeImmutable $deleteAfter = null,
        public ?DateTimeImmutable $leaseExpiresAt = null,
    ) {
        if ($contentHash === '' || preg_match('/^[a-f0-9]{64}\z/', $contentHash) !== 1) {
            throw new InvalidArgumentException("Invalid SHA-256 content hash \"{$contentHash}\"");
        }
        if ($size < 0) {
            throw new InvalidArgumentException('Blob size must not be negative');
        }
        if ($referenceCount < 0 || $reservationCount < 0) {
            throw new InvalidArgumentException('Blob counters must not be negative');
        }

        $this->contentHash = $contentHash;
        $this->size = $size;
        $this->referenceCount = $referenceCount;
        $this->reservationCount = $reservationCount;
    }

    /**
     * Nothing is keeping the bytes alive.
     *
     * Not the same as "delete it": a collector still has to wait for
     * `deleteAfter` and claim a lease. The grace period is what lets a writer
     * that reserved microseconds ago finish, instead of racing a collector that
     * looked at the counters first.
     */
    public function isUnreferenced(): bool
    {
        return $this->referenceCount === 0 && $this->reservationCount === 0;
    }
}
