<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Test;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Exception\BlobBusyException;
use Rasuvaeff\Yii3Filestorage\Exception\LedgerException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(MemoryBlobLedger::class)]
final class MemoryBlobLedgerTest
{
    private const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const string OTHER_HASH = 'da39a3ee5e6b4b0d3255bfef95601890afd80709da39a3ee5e6b4b0d32550000';

    private MemoryRepository $repository;
    private MemoryBlobLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->repository = new MemoryRepository();
        $this->ledger = new MemoryBlobLedger($this->repository);
    }

    public function aFirstReservationCreatesAWritingBlob(): void
    {
        $reservation = $this->reserve();

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::same($record?->reservationCount, 1);
        Assert::same($record?->referenceCount, 0);
        Assert::same($record?->contentHash, self::HASH);
        Assert::same($reservation->blob->key(), $this->blob()->key());
    }

    /**
     * Two writers uploading identical content is the normal case, not a
     * conflict. Each gets its own token so one of them dying does not release
     * the other's claim.
     */
    public function aSecondWriterJoinsWithItsOwnToken(): void
    {
        $first = $this->reserve();
        $second = $this->reserve();

        Assert::false($first->token->equals($second->token));
        Assert::same($this->ledger->find($this->blob())?->reservationCount, 2);
    }

    public function commitInsertsTheFileRowAndTurnsTheReservationIntoAReference(): void
    {
        $reservation = $this->reserve();

        $this->ledger->commit($reservation, $this->file('a'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
        Assert::same($record?->reservationCount, 0);
        Assert::same($this->repository->find('a')?->id, 'a');
    }

    public function commitRejectsATokenTheLedgerNeverIssued(): void
    {
        $this->reserve();
        $forged = new BlobReservation($this->blob(), new BlobToken('deadbeefdeadbeef'), $this->at('00:10'));

        Expect::exception(LedgerException::class)->withMessageContaining('Unknown reservation');

        $this->ledger->commit($forged, $this->file('a'));
    }

    public function commitRejectsAReservationForAnUnknownBlob(): void
    {
        $reservation = new BlobReservation(
            BlobId::create('upload', 'other/original'),
            new BlobToken('deadbeefdeadbeef'),
            $this->at('00:10'),
        );

        Expect::exception(LedgerException::class)->withMessageContaining('Unknown reservation');

        $this->ledger->commit($reservation, $this->file('a'));
    }

    /**
     * The file row is what a later `releaseFile()` decrements, so a row whose
     * store or path disagrees with the blob would decrement something it never
     * incremented.
     */
    public function commitRejectsAFileThatDoesNotPointAtTheBlob(): void
    {
        $reservation = $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('does not point at blob');

        $this->ledger->commit($reservation, $this->file('a', relativePath: 'elsewhere/original'));
    }

    public function commitRejectsAFileStoredInAnotherStore(): void
    {
        $reservation = $this->reserve();

        Expect::exception(LedgerException::class)->withMessageContaining('does not point at blob');

        $this->ledger->commit($reservation, $this->file('a', storeName: 'archive'));
    }

    /**
     * Same content key, different content: either a hash collision or a bug
     * upstream. Reusing the stored bytes would hand the second writer somebody
     * else's file.
     */
    public function reserveRejectsContentThatDisagreesWithTheStoredBlob(): void
    {
        $this->reserve();

        // spans the concatenation on purpose: asserting one half lets the operands swap undetected
        Expect::exception(LedgerException::class)
            ->withMessageContaining('already holds different content (hash ' . self::HASH . ', 12 bytes)');

        $this->reserve(hash: self::OTHER_HASH);
    }

    public function reserveRejectsASizeThatDisagreesWithTheStoredBlob(): void
    {
        $this->reserve();

        // spans the concatenation on purpose: asserting one half lets the operands swap undetected
        Expect::exception(LedgerException::class)
            ->withMessageContaining('already holds different content (hash ' . self::HASH . ', 12 bytes)');

        $this->reserve(size: 999);
    }

    public function releasingTheLastClaimSchedulesTheBlobWithoutDeletingIt(): void
    {
        $reservation = $this->reserve();

        $this->ledger->release($reservation, $this->at('01:00'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->deleteAfter?->format('H:i'), '01:00');
    }

    public function releasingOneOfTwoClaimsLeavesTheBlobAlone(): void
    {
        $first = $this->reserve();
        $this->reserve();

        $this->ledger->release($first, $this->at('01:00'));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::same($record?->reservationCount, 1);
    }

    public function releasingAnUnknownReservationIsANoOp(): void
    {
        $reservation = new BlobReservation($this->blob(), new BlobToken('deadbeefdeadbeef'), $this->at('00:10'));

        $this->ledger->release($reservation, $this->at('01:00'));

        Assert::null($this->ledger->find($this->blob()));
    }

    /**
     * Reviving is what makes the ledger safe to reuse: a blob scheduled for
     * deletion that somebody wants again must lose its schedule, not get a
     * second copy of the same bytes.
     */
    public function reservingAPendingDeleteBlobRevivesIt(): void
    {
        $reservation = $this->reserve();
        $this->ledger->release($reservation, $this->at('01:00'));

        $this->reserve();

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Writing);
        Assert::null($record?->deleteAfter);
    }

    public function revivingABlobThatStillHasReferencesReturnsItToActive(): void
    {
        $this->ledger->commit($this->reserve(), $this->file('a'));
        // a second file arrives while the first still holds a reference
        $this->reserve();
        $record = $this->ledger->find($this->blob());

        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
        Assert::same($record?->reservationCount, 1);
    }

    public function releaseFileDropsTheRowAndDecrementsTheBlob(): void
    {
        $this->ledger->commit($this->reserve(), $this->file('a'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));

        Assert::null($this->repository->find('a'));
        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->referenceCount, 0);
    }

    public function releaseFileKeepsABlobOtherFilesStillReference(): void
    {
        $this->ledger->commit($this->reserve(), $this->file('a'));
        $this->ledger->commit($this->reserve(), $this->file('b'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::Active);
        Assert::same($record?->referenceCount, 1);
    }

    public function releaseFileReportsAnUnknownIdentifier(): void
    {
        Assert::false($this->ledger->releaseFile('nope', $this->at('01:00')));
    }

    /**
     * A row written by the base, non-sharing facade has no blob. Removing it
     * must not invent one, and the caller stays responsible for its unique
     * object.
     */
    public function releaseFileHandlesARowWithNoBlob(): void
    {
        $this->repository->save($this->file('a'));

        Assert::true($this->ledger->releaseFile('a', $this->at('01:00')));
        Assert::null($this->ledger->find($this->blob()));
        // no ledger row was invented for a file that never had one
        Assert::same($this->ledger->records(), []);
    }

    public function expiredReservationsAreSweptAndWhatTheyHeldIsScheduled(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:30'), $this->at('01:00')), 1);

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->reservationCount, 0);
    }

    public function aLiveReservationSurvivesTheSweep(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:05'), $this->at('01:00')), 0);
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Writing);
    }

    /**
     * The boundary is inclusive on both sides of the ledger: a reservation
     * whose deadline is exactly now is gone, matching
     * `BlobReservation::isExpired()`. Anything else would leave a claim that
     * reports itself expired but still blocks collection.
     */
    public function aReservationIsSweptExactlyAtItsDeadline(): void
    {
        $this->reserve();

        Assert::same($this->ledger->expireReservations($this->at('00:09'), $this->at('01:00')), 0);
        Assert::same($this->ledger->expireReservations($this->at('00:10'), $this->at('01:00')), 1);
    }

    /**
     * The sweep touches only what lost a claim. A pass that rescheduled every
     * blob would push each already-scheduled one's grace period forward on
     * every collection run — and since a run always sweeps first, nothing
     * would ever become collectable.
     */
    public function theSweepDoesNotPostponeAnAlreadyScheduledBlob(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::same($this->ledger->expireReservations($this->at('02:00'), $this->at('03:00')), 0);

        Assert::same($this->ledger->find($this->blob())?->deleteAfter?->format('H:i'), '01:00');
        Assert::true($this->ledger->claimForDeletion($this->at('02:00'), $this->at('02:05')) !== null);
    }

    public function nothingIsCollectableBeforeItsGracePeriodEnds(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::null($this->ledger->claimForDeletion($this->at('00:59'), $this->at('01:05')));
    }

    /**
     * A blob that is not collectable yet must not stop the collector reaching
     * the ones behind it, or one long grace period stalls the whole queue.
     */
    public function aBlobThatIsNotReadyIsSkippedRatherThanEndingTheScan(): void
    {
        $this->ledger->release($this->reserve(), $this->at('02:00'));
        $this->ledger->release($this->reserveOther(), $this->at('01:00'));

        $lease = $this->ledger->claimForDeletion($this->at('01:30'), $this->at('01:35'));

        Assert::same($lease?->blob->key(), $this->otherBlob()->key());
    }

    public function anExpiredScheduleIsClaimedExclusively(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::same($lease?->blob->key(), $this->blob()->key());
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
        // a second collector finds nothing while the lease is live
        Assert::null($this->ledger->claimForDeletion($this->at('01:01'), $this->at('01:06')));
    }

    public function anAbandonedLeaseIsStolenAfterItExpires(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $second = $this->ledger->claimForDeletion($this->at('01:05'), $this->at('01:10'));

        Assert::instanceOf($first, BlobLease::class);
        Assert::instanceOf($second, BlobLease::class);
        \assert($first !== null && $second !== null);
        Assert::false($first->token->equals($second->token));
    }

    /**
     * A collector that stalled past its expiry must not delete a row the new
     * holder is working on.
     */
    public function aStolenLeaseCanNoLongerCompleteTheDeletion(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $first = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));
        $this->ledger->claimForDeletion($this->at('01:05'), $this->at('01:10'));

        Assert::false($this->ledger->completeDeletion($first ?? $this->lease()));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
    }

    public function completingADeletionRemovesTheLedgerRow(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->completeDeletion($lease ?? $this->lease()));
        Assert::null($this->ledger->find($this->blob()));
    }

    public function completingWithoutALeaseIsRefused(): void
    {
        $this->reserve();

        Assert::false($this->ledger->completeDeletion($this->lease()));
    }

    /**
     * The dangerous shape: nothing references the blob, so only the *absence of
     * a lease* stands between a forged token and a deleted row.
     */
    public function completingAnUnleasedButCollectableBlobIsRefused(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::false($this->ledger->completeDeletion($this->lease()));
        Assert::same($this->ledger->find($this->blob())?->state, BlobState::PendingDelete);
    }

    public function abandoningADeletionReturnsTheBlobToTheQueueWithBackoff(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Assert::true($this->ledger->abandonDeletion($lease ?? $this->lease(), $this->at('02:00')));

        $record = $this->ledger->find($this->blob());
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->deleteAfter?->format('H:i'), '02:00');
        Assert::null($record?->leaseExpiresAt);
    }

    public function abandoningWithoutALeaseIsRefused(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));

        Assert::false($this->ledger->abandonDeletion($this->lease(), $this->at('02:00')));
    }

    /**
     * The blob a collector holds is off limits to the sweep as well: only the
     * lease holder, or the expiry, decides what happens to it next.
     */
    public function aSweepDoesNotResetALeasedBlob(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $lease = $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        $this->ledger->expireReservations($this->at('01:01'), $this->at('03:00'));

        Assert::same($this->ledger->find($this->blob())?->state, BlobState::Deleting);
        Assert::true($this->ledger->completeDeletion($lease ?? $this->lease()));
    }

    public function recordsAndClearExposeTheWholeLedgerForAssertions(): void
    {
        $this->reserve();

        Assert::same(\count($this->ledger->records()), 1);
        Assert::same($this->ledger->records()[0]->blob->key(), $this->blob()->key());

        $this->ledger->clear();

        Assert::same($this->ledger->records(), []);
    }

    /**
     * Reserving something a collector is already removing is the one refusal
     * that is transient: the caller retries once the lease ends.
     */
    public function reservingADeletingBlobIsRefusedAsBusy(): void
    {
        $this->ledger->release($this->reserve(), $this->at('01:00'));
        $this->ledger->claimForDeletion($this->at('01:00'), $this->at('01:05'));

        Expect::exception(BlobBusyException::class)->withMessageContaining('is being deleted');

        $this->reserve();
    }

    private function reserve(string $hash = self::HASH, int $size = 12): BlobReservation
    {
        return $this->ledger->reserve($this->blob(), $hash, $size, $this->at('00:10'));
    }

    private function reserveOther(): BlobReservation
    {
        return $this->ledger->reserve($this->otherBlob(), self::OTHER_HASH, 34, $this->at('00:10'));
    }

    private function blob(): BlobId
    {
        return BlobId::create('upload', 'sha/e3/b0/original');
    }

    private function otherBlob(): BlobId
    {
        return BlobId::create('upload', 'sha/da/39/original');
    }

    private function lease(): BlobLease
    {
        return new BlobLease($this->blob(), new BlobToken('deadbeefdeadbeef'), $this->at('01:05'));
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }

    private function file(
        string $id,
        string $storeName = 'upload',
        string $relativePath = 'sha/e3/b0/original',
    ): File {
        return File::create(
            id: $id,
            storeName: $storeName,
            groupName: 'common',
            relativePath: $relativePath,
            originalName: 'thing.txt',
            size: 12,
            createdAt: $this->at('00:00'),
            contentHash: self::HASH,
        );
    }
}
