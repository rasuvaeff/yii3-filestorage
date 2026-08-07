<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\LedgerException;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * Who owns shared bytes, and for how long.
 *
 * Deduplication without this is a reference count keyed by content hash, and a
 * content hash is not ownership: it is identical across stores, groups and
 * tenants, so a delete in one of them removes bytes another is still reading.
 * Ownership here follows {@see BlobId} — one physical object in one store — and
 * every transition is conditional on the counters as the database sees them,
 * not as PHP last read them.
 *
 * The protocol an adding writer follows:
 *
 * 1. {@see reserve()} the content key. The row is created in
 *    {@see BlobState::Writing}, or an existing one is joined and revived.
 * 2. Publish the bytes through
 *    {@see ContentAddressableStoreInterface::putIfAbsent()}.
 * 3. {@see commit()} — one transaction inserting the file row, converting the
 *    reservation into a committed reference and marking the blob active.
 * 4. On any failure, {@see release()}. The blob falls back to whatever other
 *    writers and references still hold it, and becomes collectable if none do.
 *
 * Removal is {@see releaseFile()}, and it never performs store I/O. The last
 * release only schedules the object; only {@see claimForDeletion()} — that is,
 * only the garbage collector — ever deletes shared bytes. This is the whole
 * point: an object deleted inside a request is an object some concurrent add
 * has already decided to reuse.
 *
 * The contract lives in core rather than in `rasuvaeff/yii3-filestorage-db`
 * because {@see \Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger} implements it
 * and core cannot depend on the database package. The transactional
 * implementation is `DbBlobLedger` there.
 *
 * @api
 */
interface BlobLedgerInterface
{
    /**
     * Claims the right to publish bytes at a content key.
     *
     * A row that does not exist is created in `writing`. A row in `writing`,
     * `active` or `pending_delete` is joined: the caller gets its own
     * reservation token, and a `pending_delete` row is revived — its scheduled
     * deletion is cancelled, because something wants the bytes again.
     *
     * A row in `deleting` is not joinable. The collector holding the lease may
     * already have removed the object, so joining it would produce a committed
     * file row pointing at nothing. The caller waits for the lease to finish
     * and retries.
     *
     * @param non-empty-string $contentHash Lowercase SHA-256 hex of the complete content.
     * @param int<0, max> $size
     * @param DateTimeImmutable $expiresAt When an uncommitted reservation stops protecting the blob.
     *
     * @throws BlobBusyException When the blob is being deleted; retry after the lease expires.
     * @throws LedgerException When a stored blob disagrees about hash or size.
     */
    public function reserve(
        BlobId $blob,
        string $contentHash,
        int $size,
        DateTimeImmutable $expiresAt,
    ): BlobReservation;

    /**
     * Turns a reservation into a committed reference, together with the file row.
     *
     * One transaction, and it belongs here rather than being sequenced by the
     * caller: a file row written before the reference is a row that garbage
     * collection can orphan, and a reference incremented before the row is a
     * leak whenever the insert fails.
     *
     * The file must already carry the store name and relative path of `$blob`.
     *
     * @throws LedgerException When the reservation is unknown — never issued,
     *         or already swept for having expired — or does not match the file.
     */
    public function commit(BlobReservation $reservation, File $file): void;

    /**
     * Drops an uncommitted reservation. Idempotent.
     *
     * Never deletes bytes, even when it drops the last claim: that only
     * schedules the blob, and the collector still waits out `$deleteAfter`.
     * Deleting here instead would race the writer who reserved the same content
     * key one statement ago.
     *
     * @param DateTimeImmutable $deleteAfter Grace period end for a blob whose last claim this was.
     */
    public function release(BlobReservation $reservation, DateTimeImmutable $deleteAfter): void;

    /**
     * Deletes a logical file row and decrements the blob it referenced.
     *
     * One transaction. A row with no blob — written by the base, non-sharing
     * facade — is simply deleted, and the caller stays responsible for its
     * unique object.
     *
     * @param non-empty-string $fileId
     * @param DateTimeImmutable $deleteAfter Grace period end for a blob whose last reference this was.
     *
     * @return bool False when there was no such row.
     */
    public function releaseFile(string $fileId, DateTimeImmutable $deleteAfter): bool;

    public function find(BlobId $blob): ?BlobRecord;

    /**
     * Removes reservations that ran out, and schedules whatever they were protecting.
     *
     * Run before any collection pass: a `writing` blob left behind by a dead
     * process is invisible to {@see claimForDeletion()} until its reservation is
     * gone, and would otherwise never be reclaimed.
     *
     * @param DateTimeImmutable $deleteAfter Grace period end for blobs this leaves unclaimed.
     *
     * @return int<0, max> Reservations removed.
     */
    public function expireReservations(DateTimeImmutable $now, DateTimeImmutable $deleteAfter): int;

    /**
     * Takes exclusive ownership of one collectable blob, or returns null.
     *
     * Claims only a row whose grace period has passed and whose reference and
     * reservation counts are both zero *in the same statement* that sets the
     * lease — checking first and updating after is the race this exists to
     * close. A lease whose expiry has passed may be stolen from its previous
     * holder, which is how a crashed collector is recovered.
     *
     * @param DateTimeImmutable $leaseExpiresAt Deadline by which the holder must finish or lose the claim.
     */
    public function claimForDeletion(DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): ?BlobLease;

    /**
     * Removes the ledger row after its bytes are gone.
     *
     * Applies only while the same lease is still held and the counts are still
     * zero, so a collector that stalled past its expiry — while another writer
     * revived the blob — cannot delete a row that is alive again.
     *
     * @return bool False when the lease was lost or the blob was revived.
     */
    public function completeDeletion(BlobLease $lease): bool;

    /**
     * Returns a leased blob to the collection queue after a failed delete.
     *
     * @param DateTimeImmutable $retryAfter Backoff, so a store that is down is not hammered.
     *
     * @return bool False when the lease was already lost.
     */
    public function abandonDeletion(BlobLease $lease, DateTimeImmutable $retryAfter): bool;
}
