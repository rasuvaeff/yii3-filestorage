<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;

/**
 * A store that can be walked and pruned by operations commands.
 *
 * Kept off the hot-path contract on purpose: garbage collection, verification
 * and statistics need to enumerate objects, and demanding that of every store —
 * including one backed by a bucket where listing is slow and eventually
 * consistent — would tax every upload for the benefit of a nightly command.
 * A command that cannot find this capability exits with a diagnostic rather
 * than reaching for reflection or a backend-specific downcast.
 *
 * @api
 */
interface MaintenanceStoreInterface extends StoreInterface
{
    /**
     * One page of the object inventory, ordered so the cursor is resumable.
     *
     * Never lists the quarantine, nor staging leftovers.
     *
     * @param non-empty-string|null $afterPath Exclusive cursor: resume after this path.
     * @param int<1, max> $limit
     *
     * @return iterable<int, StoredObject>
     *
     * @throws StoreException
     */
    public function objects(?string $afterPath = null, int $limit = 1000): iterable;

    /**
     * Idempotent: deleting something already gone is a success, because a
     * retried garbage collection must converge rather than fail.
     *
     * Irreversible. Garbage collection does not use it: it goes through
     * {@see quarantine()} so that a decision proven wrong afterwards can be
     * rolled back.
     *
     * @throws StoreException
     */
    public function deleteObject(StoredObjectId $object): void;

    /**
     * Takes every existing member of `$objects` off its key, into this
     * store's quarantine, under one new entry.
     *
     * The quarantine must not be served publicly (outside a web root, or a
     * private prefix) and must not appear in {@see objects()}. Locally the
     * move is a `rename()`, atomic only within one filesystem — see
     * {@see quarantineProblem()}.
     *
     * @return QuarantinedObject|null Null when no member existed.
     *
     * @throws StoreException When the store has no quarantine or the move failed.
     *         A failed move puts the members already moved back first, so the
     *         set stays whole; one that cannot go back stays under the entry,
     *         where {@see quarantined()} finds it and `gc` restores it while a
     *         row still references it.
     */
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject;

    /**
     * Puts the entry's objects back, with put-if-absent semantics per object:
     * when a writer has re-published the key meanwhile — the same bytes, since
     * keys of shared objects are content hashes — the quarantined copy is
     * simply dropped. The entry is gone afterwards. Idempotent.
     *
     * @throws StoreException
     */
    public function restore(QuarantinedObject $entry): void;

    /**
     * Deletes the entry for good. Idempotent.
     *
     * @throws StoreException
     */
    public function purge(QuarantinedObject $entry): void;

    /**
     * The quarantine's entries, ordered by token (oldest first), so a
     * collector that crashed between quarantining and purging is recovered by
     * the next run.
     *
     * @param non-empty-string|null $afterToken Exclusive cursor.
     * @param int<1, max> $limit
     *
     * @return iterable<int, QuarantinedObject>
     *
     * @throws StoreException
     */
    public function quarantined(?string $afterToken = null, int $limit = 1000): iterable;

    /**
     * What `filestorage:check` should report about the quarantine, or null
     * when it is usable as configured: missing configuration, a location
     * where a move is not atomic, a location that would be served.
     *
     * @return non-empty-string|null
     */
    public function quarantineProblem(): ?string;
}
