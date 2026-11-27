<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Command\BackfillHashCommand;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Command\StatCommand;
use Rasuvaeff\Yii3Filestorage\Command\VerifyCommand;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\StallingStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(GcCommand::class)]
#[Covers(VerifyCommand::class)]
#[Covers(BackfillHashCommand::class)]
#[Covers(StatCommand::class)]
final class OperationsCommandsTest
{
    private Psr17Factory $factory;
    private InMemoryStore $store;
    private MemoryRepository $repository;
    private MemoryBlobLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->store = new InMemoryStore('memory', $this->factory, new StaticClock($this->at('00:00')));
        $this->repository = new MemoryRepository();
        $this->ledger = new MemoryBlobLedger($this->repository);
    }

    // ---- gc -------------------------------------------------------------

    /**
     * The default is a report. A command whose first run deletes is one
     * somebody eventually runs against the wrong database.
     */
    public function gcDeletesNothingWithoutApply(): void
    {
        $file = $this->storeFile('a');
        $this->repository->clear();

        $tester = $this->run(new GcCommand($this->registry(), $this->repository, $this->clock()), ['--orphans' => true]);

        Assert::string($tester->getDisplay())->contains('Dry run');
        Assert::string($tester->getDisplay())->contains('would delete');
        Assert::same($this->store->bytesAt($file->relativePath), 'hello');
    }

    /**
     * An object with no row: what a crash between writing bytes and saving
     * metadata leaves behind, and what nothing else will ever reclaim.
     */
    public function gcSweepsOrphansWithApply(): void
    {
        $orphan = $this->storeFile('gone');
        $kept = $this->storeFile('kept');
        $this->repository->delete('gone');

        $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::null($this->store->bytesAt($orphan->relativePath));
        Assert::same($this->store->bytesAt($kept->relativePath), 'hello', 'a referenced object must survive');
    }

    public function gcWithoutOrphansLeavesThemAlone(): void
    {
        $orphan = $this->storeFile('gone');
        $this->repository->clear();

        $this->run(new GcCommand($this->registry(), $this->repository, $this->clock()), ['--apply' => true]);

        Assert::same($this->store->bytesAt($orphan->relativePath), 'hello');
    }

    /**
     * Without `--store` every configured store is walked. Walking only the
     * default one and printing a total says "no orphans" about stores nobody
     * looked at.
     */
    public function gcSweepsEveryStoreUnlessToldOtherwise(): void
    {
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $orphan = $this->writeTo($second);

        $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::null($second->bytesAt($orphan));
    }

    public function gcWalksOnlyTheStoreItWasGiven(): void
    {
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $orphan = $this->writeTo($second);
        $ownOrphan = $this->storeFile('gone');
        $this->repository->clear();

        $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true, '--store' => 'memory'],
        );

        Assert::null($this->store->bytesAt($ownOrphan->relativePath));
        Assert::same($second->bytesAt($orphan), 'hello', 'a store outside --store must not be touched');
    }

    public function gcSaysSoWhenThereIsNoLedger(): void
    {
        $tester = $this->run(new GcCommand($this->registry(), $this->repository, $this->clock()));

        Assert::string($tester->getDisplay())->contains('No blob ledger is bound');
        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }

    /**
     * The collection pass proper: a scheduled blob past its grace period is
     * deleted, and its ledger row with it.
     */
    public function gcCollectsAScheduledBlob(): void
    {
        $file = $this->storeFile('a');
        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->commit(
            $this->ledger->reserve($blob, $this->hash(), 5, $this->at('00:10')),
            $file,
        );
        $this->ledger->releaseFile('a', $this->at('00:00'));

        $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true],
        );

        Assert::null($this->ledger->find($blob));
        Assert::null($this->store->bytesAt($file->relativePath));
    }

    /**
     * The sweep is a write: it deletes reservation rows and re-schedules what
     * they held with a fresh grace period. Running it under a dry run postpones
     * by an hour exactly the blobs the operator is checking before collecting —
     * so "dry-run, then apply", the sequence the note recommends, would collect
     * nothing and look broken.
     */
    public function gcTouchesNothingInTheLedgerWithoutApply(): void
    {
        $file = $this->storeFile('a');
        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->commit(
            $this->ledger->reserve($blob, $this->hash(), 5, $this->at('00:10')),
            $file,
        );
        $this->ledger->releaseFile('a', $this->at('00:00'));
        $command = new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger);

        $this->run($command);
        $this->run($command, ['--apply' => true]);

        Assert::null($this->ledger->find($blob), 'the dry run must not have postponed the blob');
    }

    /**
     * A count of zero is a claim; "unavailable" is the truth. A dry run cannot
     * know how many blobs are collectable without claiming them.
     */
    public function gcDoesNotReportABlobCountItCannotKnow(): void
    {
        $tester = $this->run(new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger));

        Assert::string($tester->getDisplay())->contains('none can be counted here');
        Assert::string($tester->getDisplay())->contains('Nothing was collected');
    }

    /**
     * `--limit` is one budget for the whole pass, and with `--orphans` it is
     * split between the two halves — 5 into 3 blobs + 2 orphans, not 5 + 5.
     * Every number in the summary is pinned: a formula that gave either half
     * the whole budget (or rounded the other way) moves one of them.
     */
    public function gcSplitsTheLimitBetweenBlobsAndOrphans(): void
    {
        $this->scheduledBlob('a', 'one');
        $this->scheduledBlob('b', 'two');
        $this->scheduledBlob('c', 'three');
        $this->scheduledBlob('d', 'four');
        $this->storeFile('e');
        $this->storeFile('f', contents: 'six');
        $this->storeFile('g', contents: 'seven');
        $this->repository->clear();

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--orphans' => true, '--apply' => true, '--limit' => '5'],
        )->getDisplay();

        Assert::string($display)->contains('Collected 3 blobs and 2 orphaned objects.');
    }

    /**
     * The claim loop is bounded by attempts, not successes — and the bound
     * holds: `--limit=1` collects exactly one blob even with two waiting.
     */
    public function theBlobBudgetStopsTheClaimLoop(): void
    {
        $this->scheduledBlob('a', 'one');
        $this->scheduledBlob('b', 'two');

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true, '--limit' => '1'],
        )->getDisplay();

        Assert::string($display)->contains('Collected 1 blob.');
        Assert::true($this->ledger->records() !== [], 'the second blob is still queued');
    }

    /**
     * A blob something still references must survive the pass — this is the
     * failure that would delete a live file.
     */
    public function gcLeavesAReferencedBlobAlone(): void
    {
        $file = $this->storeFile('a');
        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->commit($this->ledger->reserve($blob, $this->hash(), 5, $this->at('00:10')), $file);

        $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true],
        );

        Assert::true($this->ledger->find($blob) instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobRecord);
        Assert::same($this->store->bytesAt($file->relativePath), 'hello');
    }

    /**
     * A second run has nothing left to do — which is what makes the command
     * safe to put on a schedule.
     */
    public function gcIsIdempotent(): void
    {
        $this->storeFile('gone');
        $this->repository->clear();
        $command = new GcCommand($this->registry(), $this->repository, $this->clock());

        $this->run($command, ['--orphans' => true, '--apply' => true]);
        $second = $this->run($command, ['--orphans' => true, '--apply' => true]);

        Assert::string($second->getDisplay())->contains('0 orphaned objects');
    }


    /**
     * The exact summary, both numbers. Pluralisation and the counters that feed
     * it are the whole report: a substring match would pass while the command
     * said "1 blobs" or counted the wrong thing.
     */
    public function gcReportsExactlyWhatItCollected(): void
    {
        $one = $this->scheduledBlob('a', 'one');
        $command = new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger);

        Assert::string($this->run($command, ['--apply' => true])->getDisplay())->contains('Collected 1 blob.');

        $this->scheduledBlob('b', 'two');
        $this->scheduledBlob('c', 'three');

        Assert::string($this->run($command, ['--apply' => true])->getDisplay())->contains('Collected 2 blobs.');
        Assert::null($this->ledger->find($one));
    }

    public function gcCountsOrphansInSingularAndPlural(): void
    {
        $this->storeFile('a');
        $this->repository->clear();
        $command = new GcCommand($this->registry(), $this->repository, $this->clock());

        Assert::string($this->run($command, ['--orphans' => true, '--apply' => true])->getDisplay())
            ->contains('found 1 orphaned object.');

        $this->storeFile('b');
        $this->storeFile('c');
        $this->repository->clear();

        Assert::string($this->run($command, ['--orphans' => true, '--apply' => true])->getDisplay())
            ->contains('found 2 orphaned objects.');
    }

    /**
     * A blob and an orphan in one pass join with "and", not with the "found"
     * wording a ledger-less run uses.
     */
    public function gcJoinsBothCountsWhenItHasALedger(): void
    {
        $this->scheduledBlob('a', 'one');
        $this->storeFile('b');
        $this->repository->delete('b');

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--orphans' => true, '--apply' => true],
        )->getDisplay();

        Assert::string($display)->contains('Collected 1 blob and 1 orphaned object.');
    }

    /**
     * A ledger row can name a store that no longer implements
     * `MaintenanceStoreInterface` — an adapter dropped from configuration while
     * its rows live on. Its blobs cannot be collected, but the pass must say so
     * once (not once per blob), put each one back in the queue with a fresh
     * grace period, and still collect the stores that *are* walkable.
     */
    public function gcAbandonsBlobsWhoseStoreCannotDeleteThemAndCollectsTheRest(): void
    {
        $plain = $this->unwalkableStore('plain');
        $first = BlobId::create('plain', 'plain-one/original.bin');
        $second = BlobId::create('plain', 'plain-two/original.bin');
        $this->ledger->reserve($first, hash('sha256', 'one'), 3, $this->at('00:10'));
        $this->ledger->reserve($second, hash('sha256', 'two'), 3, $this->at('00:10'));
        // Past their grace period already: the run's own sweep must not push
        // their deletion past the clock it claims with.
        $this->ledger->expireReservations($this->at('00:30'), $this->at('01:00'));
        $collectable = $this->scheduledBlob('a', 'one');

        $display = $this->flatten(
            $this->run(
                new GcCommand(new StoreRegistry([$this->store, $plain]), $this->repository, $this->clock(), $this->ledger),
                ['--apply' => true],
            )->getDisplay(),
        );

        Assert::string($display)->contains(
            'Store "plain" cannot delete objects (' . MaintenanceStoreInterface::class
            . ' is not implemented or is not configured), so its blobs cannot be collected.',
        );
        Assert::same(substr_count($display, 'cannot delete objects'), 1, 'warned once per store, not once per blob');
        Assert::string($display)->contains('Collected 1 blob.', 'the walkable store was still collected');

        foreach ([$first, $second] as $blob) {
            $record = $this->ledger->find($blob);
            Assert::same($record?->state, BlobState::PendingDelete, 'the blob went back to the queue');
            Assert::same($record?->deleteAfter?->format('H:i'), '03:00', 'with a fresh grace period, not dropped');
            Assert::null($record?->leaseExpiresAt, 'and it no longer holds the lease it was claimed with');
        }
        Assert::null($this->ledger->find($collectable), 'the collectable blob is gone');
    }

    /**
     * A store that is briefly down must not lose the blob: the lease is
     * abandoned with a delay, the failure is named with the blob's key, and the
     * pass goes on to the blobs behind it instead of stopping at the first
     * broken one.
     */
    public function gcRequeuesABlobWhoseStoreFailsToDeleteItAndKeepsGoing(): void
    {
        $doomed = $this->scheduledBlob('a', 'one');
        $survivor = $this->scheduledBlob('b', 'two');
        $broken = $this->sickStore('memory', $doomed->relativePath());

        $display = $this->flatten(
            $this->run(
                new GcCommand(new StoreRegistry([$broken]), $this->repository, $this->clock(), $this->ledger),
                ['--apply' => true],
            )->getDisplay(),
        );

        Assert::string($display)->contains(
            sprintf('Could not delete "%s": the object store is unreachable', $doomed->key()),
        );
        Assert::string($display)->contains('Collected 1 blob.', 'the blob behind the broken one was still collected');

        $record = $this->ledger->find($doomed);
        Assert::same($record?->state, BlobState::PendingDelete, 'the failed blob went back to the queue');
        Assert::same($record?->deleteAfter?->format('H:i'), '03:00', 'with the grace period applied');
        Assert::null($this->ledger->find($survivor));
    }

    /**
     * One permission-denied object must not end the sweep, and the counts
     * accumulated before it must survive: the failure is a warning line, and
     * the report still says how many orphans were found.
     */
    /**
     * Orphans are classified per directory: the first object may sit in a
     * referenced directory, and skipping it must not stop the walk — the
     * true orphan in the next directory still has to be swept.
     */
    public function aReferencedDirectoryDoesNotEndTheOrphanSweep(): void
    {
        $keeper = File::create(
            id: 'keeper',
            storeName: 'memory',
            groupName: 'docs',
            relativePath: 'r1/aa/ee/keeper/original.bin',
            originalName: 'k.bin',
            size: 5,
            createdAt: $this->at('00:00'),
        );
        $this->repository->save($keeper);
        $this->store->corrupt('r1/aa/ee/keeper/orphan.bin', 'in a referenced directory');
        $this->store->corrupt('r2/orphan.bin', 'a true orphan');

        $display = $this->flatten($this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true],
        )->getDisplay());

        Assert::string($display)->contains('r2/orphan.bin');
        Assert::false(str_contains($display, 'r1/'), 'the referenced directory is skipped');
    }

    /**
     * Bytes published under a reservation have no file row yet, but the
     * ledger knows them: the sweep must skip them and keep walking, or the
     * next commit would point at bytes that no longer exist.
     */
    public function aLedgerKnownObjectDoesNotEndTheOrphanSweep(): void
    {
        $reserved = BlobId::create('memory', 'k1/aa/ee/writer/original.bin');
        $this->ledger->reserve($reserved, hash('sha256', 'one'), 3, $this->at('00:10'));
        $this->store->corrupt($reserved->relativePath(), 'one');
        $this->store->corrupt('k2/orphan.txt', 'a true orphan');

        $display = $this->flatten($this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--orphans' => true],
        )->getDisplay());

        Assert::string($display)->contains('k2/orphan.txt');
        Assert::false(str_contains($display, 'k1/'), 'the reserved object is not an orphan');
    }

    /**
     * A row that already carries a hash is skipped, not the end of the
     * backfill: the unhashed rows behind it still get theirs.
     */
    public function aRowWithAHashDoesNotEndTheBackfill(): void
    {
        $this->storeFile('a', hash: $this->hash());
        $this->storeFile('b');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::same($this->repository->find('b')?->contentHash, $this->hash());
    }

    /**
     * An unreadable row is reported and counted, not the end of the run: the
     * hashable rows behind it are still hashed.
     */
    public function anUnreadableRowDoesNotEndTheBackfill(): void
    {
        $this->repository->save(File::create(
            id: 'ghost',
            storeName: 'memory',
            groupName: 'docs',
            relativePath: 'gone/aa/ee/ghost/original.bin',
            originalName: 'g.bin',
            size: 5,
            createdAt: $this->at('00:00'),
        ));
        $this->storeFile('b');

        $display = $this->flatten($this->run(
            new BackfillHashCommand($this->registry(), $this->repository),
            ['--apply' => true],
        )->getDisplay());

        Assert::string($display)->contains('unreadable: ghost');
        Assert::same($this->repository->find('b')?->contentHash, $this->hash());
    }

    public function theOrphanSweepWarnsAndKeepsCountingWhenADeleteFails(): void
    {
        $orphan = $this->storeFile('gone');
        $this->repository->clear();

        $display = $this->flatten(
            $this->run(
                new GcCommand(new StoreRegistry([$this->sickStore('memory', $orphan->relativePath)]), $this->repository, $this->clock()),
                ['--orphans' => true, '--apply' => true],
            )->getDisplay(),
        );

        Assert::string($display)->contains(
            sprintf('Could not delete "%s": the object store is unreachable', $orphan->relativePath),
        );
        Assert::string($display)->contains('found 1 orphaned object.');
    }

    /**
     * The full refusal is the whole point of the command's one dangerous flag:
     * asserting only its first words would let the explanation — the part an
     * operator has to act on — drift or disappear.
     */
    public function theScopedSweepRefusalExplainsItselfInFull(): void
    {
        $this->storeFile('a');

        $display = $this->flatten($this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), scopes: $this->tenantScope()),
            ['--orphans' => true, '--apply' => true],
        )->getDisplay());

        Assert::string($display)->contains(sprintf(
            'Refusing --orphans: a %s is bound, so this installation is multi-tenant. The referenced-set comes '
            . 'from the repository, which filters by the current tenant, while the object listing is physical and '
            . 'filters by nothing — so the sweep would classify every other tenant\'s objects as orphans and '
            . '--apply would delete them. There is no tenant to run it "as": no single tenant\'s rows can prove an '
            . 'object unreferenced. Run the sweep from a maintenance entry point that leaves %s unbound, where the '
            . 'repository sees every row. Blob collection is unaffected — drop --orphans and this command still '
            . 'runs.',
            FileScopeProviderInterface::class,
            FileScopeProviderInterface::class,
        ));
    }

    /**
     * The sweep runs before the claim loop, and says how much it swept. Without
     * it a crashed upload's reservation keeps its blob alive forever.
     */
    public function gcReportsTheReservationsItSwept(): void
    {
        $file = $this->storeFile('a');
        $this->ledger->reserve(BlobId::create('memory', $file->relativePath), $this->hash(), 5, $this->at('00:10'));

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true],
        )->getDisplay();

        Assert::string($display)->contains('Swept 1 expired reservation.');
    }

    public function gcPluralisesTheSweptReservations(): void
    {
        foreach (['a', 'b'] as $id) {
            $file = $this->storeFile($id, contents: $id);
            $this->ledger->reserve(BlobId::create('memory', $file->relativePath), hash('sha256', $id), 1, $this->at('00:10'));
        }

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true],
        )->getDisplay();

        Assert::string($display)->contains('Swept 2 expired reservations.');
    }

    /**
     * `--limit` is a budget for the whole pass, not per store. Spending it in
     * the first store and then walking the second anyway is how a bounded
     * nightly job turns into an unbounded one.
     */
    public function theLimitIsSharedAcrossStores(): void
    {
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $this->storeFile('a');
        $this->repository->clear();
        $far = $this->writeTo($second);

        $display = $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true, '--limit' => '1'],
        )->getDisplay();

        Assert::string($display)->contains('found 1 orphaned object.');
        Assert::same($second->bytesAt($far), 'hello', 'the budget was already spent in the first store');
        Assert::string($display)->notContains('Walking store "archive"');
    }

    public function theLimitStopsAPassInsideOneStore(): void
    {
        $this->storeFile('a');
        $this->storeFile('b');
        $this->repository->clear();

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true, '--limit' => '1'],
        )->getDisplay();

        Assert::string($display)->contains('found 1 orphaned object.');
    }

    /**
     * A nonsense limit floors at one rather than at zero. A `--limit=0` that
     * silently did nothing would look exactly like "there was nothing to do".
     */
    public function anImpossibleLimitStillDoesOneThing(): void
    {
        $this->storeFile('a');
        $this->repository->clear();

        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true, '--limit' => '0'],
        )->getDisplay();

        Assert::string($display)->contains('found 1 orphaned object.');
    }

    public function gcNamesTheStoreItIsWalking(): void
    {
        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true],
        )->getDisplay();

        Assert::string($display)->contains('Walking store "memory".');
    }

    /**
     * An empty `--store` is not a store name. Treating it as one would look up
     * `""` in the registry and fail on a value the shell produces by accident.
     */
    public function anEmptyStoreOptionFallsBackToEveryStore(): void
    {
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));

        $display = $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--store' => ''],
        )->getDisplay();

        Assert::string($display)->contains('Walking store "memory".');
        Assert::string($display)->contains('Walking store "archive".');
    }

    /**
     * A store that cannot be walked is named, not silently counted as clean.
     */
    public function gcSaysWhenAStoreCannotBeWalked(): void
    {
        $display = $this->run(
            new GcCommand(new StoreRegistry([$this->unwalkableStore('opaque')]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true],
        )->getDisplay();

        Assert::string($display)->contains('cannot be walked');
        Assert::string($display)->contains('found 0 orphaned objects.');
    }


    /**
     * Nothing swept, nothing said. A pass that always announces "Swept 0" turns
     * the line that matters into noise operators learn to skip.
     */
    public function gcStaysQuietWhenThereWasNothingToSweep(): void
    {
        $display = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--apply' => true],
        )->getDisplay();

        Assert::string($display)->notContains('Swept');
    }

    /**
     * The budget shrinks as it is spent. Handing each store the *full* limit
     * makes `--limit` a per-store cap, so an installation with ten stores can
     * delete ten times what the operator authorised.
     */
    public function eachStoreGetsOnlyWhatIsLeftOfTheBudget(): void
    {
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $this->storeFile('a');
        $this->storeFile('b', contents: 'two');
        $this->repository->clear();
        $this->writeTo($second);
        $this->writeTo($second, 'four');

        $display = $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true, '--limit' => '3'],
        )->getDisplay();

        Assert::string($display)->contains('found 3 orphaned objects.');
    }


    /**
     * A derivative is a sibling object inside the file's key directory, and no
     * row's relativePath ever equals its path — a row points at
     * `<key>/original.<ext>`, the preview lives at `<key>/thumb.webp`. Matching
     * the referenced-set on paths therefore classified every preview in the
     * store as an orphan, and `--apply` unlinked them all. The comparison is on
     * the *directory*, which is also why StoreInterface::delete() removes the
     * directory rather than the object.
     */
    public function derivativesBesideALiveFileAreNotOrphans(): void
    {
        $file = $this->storeFile('a');
        $derivative = $file->directory() . '/thumb.webp';
        $this->store->corrupt($derivative, 'preview bytes');

        $tester = $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::string($tester->getDisplay())->notContains('thumb.webp');
        Assert::same($this->store->bytesAt($derivative), 'preview bytes', 'the derivative survived the sweep');
    }

    /**
     * Two stores can hold the same relative path. A row in one of them must not
     * make an unreferenced object in the other look referenced.
     */
    public function aRowInOneStoreDoesNotProtectAnObjectInAnother(): void
    {
        $file = $this->storeFile('a');
        $second = new InMemoryStore('archive', $this->factory, new StaticClock($this->at('00:00')));
        $second->corrupt($file->relativePath, 'unreferenced bytes');

        $this->run(
            new GcCommand(new StoreRegistry([$this->store, $second]), $this->repository, $this->clock()),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::null($second->bytesAt($file->relativePath), 'the other store\'s object was collected');
        Assert::true($this->store->bytesAt($file->relativePath) !== null, 'the referenced one was not');
    }

    /**
     * Bytes published under the ledger's protocol have no file row until
     * commit(), which is what the reservation protects. Sweeping them as
     * orphans deletes the object out from under a writer that is about to
     * commit a row pointing at it.
     */
    public function anObjectTheLedgerKnowsIsNotAnOrphan(): void
    {
        $file = $this->storeFile('a', contents: 'shared bytes');
        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->reserve($blob, hash('sha256', 'shared bytes'), $file->size, $this->at('00:10'));
        // Published, not yet committed: exactly the window.
        $this->repository->clear();

        $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock(), $this->ledger),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::true($this->store->bytesAt($file->relativePath) !== null, 'the reserved object survived');
    }

    /**
     * A row can name a store configuration no longer has — a rename, a dropped
     * backend, a restore that brought back a database without its objects.
     * That is one of the situations verify exists to report, so it has to
     * survive it rather than abort on the first such row.
     */
    public function verifyReportsARowWhoseStoreIsGoneInsteadOfAborting(): void
    {
        $this->storeFile('a');
        $this->repository->save(File::create(
            id: 'orphaned-store',
            storeName: 'retired',
            groupName: 'common',
            relativePath: 'x/original.bin',
            originalName: 'x.txt',
            size: 1,
            createdAt: $this->at('00:00'),
        ));

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository));

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('orphaned-store');
    }

    public function backfillHashSurvivesARowWhoseStoreIsGone(): void
    {
        $this->storeFile('a');
        $this->repository->save(File::create(
            id: 'orphaned-store',
            storeName: 'retired',
            groupName: 'common',
            relativePath: 'x/original.bin',
            originalName: 'x.txt',
            size: 1,
            createdAt: $this->at('00:00'),
        ));

        $tester = $this->run(
            new BackfillHashCommand($this->registry(), $this->repository),
            ['--apply' => true],
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('unreadable: orphaned-store');
    }

    /**
     * The destructive asymmetry. `--orphans` compares a *scoped* referenced-set
     * against an *unscoped* physical listing, so under tenancy every other
     * tenant's object looks unreferenced and `--apply` deletes it. There is no
     * tenant to run it "as" — no single tenant's rows can prove an object is
     * unreferenced — so the command refuses rather than guessing.
     */
    public function gcRefusesToSweepOrphansWhenTheRepositoryIsScoped(): void
    {
        $other = $this->storeFile('a');

        $tester = $this->run(
            new GcCommand(
                $this->registry(),
                $this->repository,
                $this->clock(),
                scopes: $this->tenantScope(),
            ),
            ['--orphans' => true, '--apply' => true],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('Refusing --orphans');
        Assert::same($this->store->bytesAt($other->relativePath), 'hello');
    }

    /**
     * Blob collection is not affected: it works off the ledger, whose rows are
     * keyed by physical identity rather than by tenant.
     */
    public function gcStillCollectsBlobsUnderTenancy(): void
    {
        $file = $this->storeFile('a');
        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->commit(
            $this->ledger->reserve($blob, $this->hash(), 5, $this->at('00:10')),
            $file,
        );
        $this->ledger->releaseFile('a', $this->at('00:00'));

        $tester = $this->run(
            new GcCommand(
                $this->registry(),
                $this->repository,
                $this->clock(),
                $this->ledger,
                $this->tenantScope(),
            ),
            ['--apply' => true],
        );

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::null($this->ledger->find($blob));
    }

    // ---- verify ---------------------------------------------------------

    public function verifyPassesWhenEveryRowHasItsObject(): void
    {
        $this->storeFile('a');

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository));

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Every row has its object');
        // Text mode emits no machine object, not even after the success line.
        Assert::true(json_decode(trim($tester->getDisplay())) === null, 'no JSON in text mode');
    }

    public function verifyFailsOnARowWithoutBytes(): void
    {
        $file = $this->storeFile('a');
        $this->store->deleteObject(new StoredObjectId($file->relativePath));

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository));

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('missing: a');
    }

    /**
     * Corruption a size check cannot see. Only `--deep` looks, because looking
     * costs a full read of everything.
     */
    public function verifyOnlyNoticesCorruptionWhenAskedToLook(): void
    {
        $file = $this->storeFile('a', hash: $this->hash());
        $this->store->corrupt($file->relativePath, 'tampered');

        Assert::same($this->run(new VerifyCommand($this->registry(), $this->repository))->getStatusCode(), Command::SUCCESS);

        $deep = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => true]);
        Assert::same($deep->getStatusCode(), Command::FAILURE);
        Assert::string($deep->getDisplay())->contains('corrupt: a');
    }

    public function verifyResumesAfterACursor(): void
    {
        $this->storeFile('a');
        $this->storeFile('b');

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--after' => 'a']);

        Assert::string($tester->getDisplay())->contains('Checked 1 row');
        Assert::string($tester->getDisplay())->contains('Last id: b');
    }


    public function verifyReportsTheExactRowCount(): void
    {
        $this->storeFile('a');
        Assert::string($this->run(new VerifyCommand($this->registry(), $this->repository))->getDisplay())
            ->contains('Checked 1 row.');

        $this->storeFile('b');
        Assert::string($this->run(new VerifyCommand($this->registry(), $this->repository))->getDisplay())
            ->contains('Checked 2 rows.');
    }

    public function verifyCountsMissingRowsAndCorruptOnesSeparately(): void
    {
        $gone = $this->storeFile('a', hash: $this->hash());
        $tampered = $this->storeFile('b', hash: $this->hash());
        $this->store->deleteObject(new StoredObjectId($gone->relativePath));
        $this->store->corrupt($tampered->relativePath, 'tampered');

        $display = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => true])
            ->getDisplay();

        Assert::string($display)->contains('1 row without an object, 1 with the wrong content.');
    }

    public function verifyPluralisesTheRowsWithoutAnObject(): void
    {
        foreach (['a', 'b'] as $id) {
            $file = $this->storeFile($id, contents: $id);
            $this->store->deleteObject(new StoredObjectId($file->relativePath));
        }

        $display = $this->run(new VerifyCommand($this->registry(), $this->repository))->getDisplay();

        Assert::string($display)->contains('2 rows without an object.');
    }

    public function verifyStopsAtItsLimit(): void
    {
        $this->storeFile('a');
        $this->storeFile('b');

        $display = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--limit' => '1'])
            ->getDisplay();

        Assert::string($display)->contains('Checked 1 row.');
        Assert::string($display)->contains('Last id: a');
    }

    /**
     * A row with no recorded hash cannot be compared to anything, so `--deep`
     * passes it rather than inventing a verdict.
     */
    public function deepVerifyCannotJudgeARowWithNoHash(): void
    {
        $file = $this->storeFile('a');
        $this->store->corrupt($file->relativePath, 'tampered');

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => true]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
    }


    /**
     * The intact case under `--deep`: the hash the command computes has to
     * equal the recorded one. A read loop that skipped content would report
     * every row as corrupt, and one that skipped nothing reports none.
     */
    public function deepVerifyAcceptsAnObjectWhoseHashStillMatches(): void
    {
        $this->storeFile('a', hash: $this->hash());

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => true]);

        Assert::same($tester->getStatusCode(), Command::SUCCESS);
        Assert::string($tester->getDisplay())->contains('Every row has its object');
    }

    /**
     * Content longer than one read: the loop has to keep going, and has to stop.
     */
    public function deepVerifyHashesAnObjectLargerThanOneRead(): void
    {
        $contents = str_repeat('x', 700_000);
        $this->storeFile('a', hash: hash('sha256', $contents), contents: $contents);

        Assert::same(
            $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => true])->getStatusCode(),
            Command::SUCCESS,
        );
    }

    public function anImpossibleVerifyLimitStillChecksOneRow(): void
    {
        $this->storeFile('a');

        $display = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--limit' => '0'])
            ->getDisplay();

        Assert::string($display)->contains('Checked 1 row.');
    }

    /**
     * Verify keeps going past a healthy row. Stopping there would report a
     * clean table as long as its first row happened to be fine.
     */
    public function verifyDoesNotStopAtAHealthyRow(): void
    {
        $this->storeFile('a');
        $gone = $this->storeFile('b', contents: 'other');
        $this->store->deleteObject(new StoredObjectId($gone->relativePath));

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository));

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('missing: b');
    }

    /**
     * The row can pass `exists()` and still fail the read that follows — the
     * object vanished, or the read errored, between the two calls. That is
     * exactly what "missing" means, and the deep check must say so rather than
     * carry on hashing nothing.
     */
    public function deepVerifyReportsARowWhoseReadFailsAfterExists(): void
    {
        $file = $this->storeFile('a', hash: $this->hash());
        $store = Understudy::for(\Rasuvaeff\Yii3Filestorage\Store\StoreInterface::class);
        Understudy::forwarding($store, $this->store);
        when(static fn(): ?\Psr\Http\Message\StreamInterface => $store->stream(Arg::any()))->returns(null);

        $tester = $this->run(
            new VerifyCommand(new StoreRegistry([$store]), $this->repository),
            ['--deep' => true],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains(sprintf('missing: a (%s)', $file->relativePath));
    }

    /**
     * An empty chunk before EOF is a stalled read, not the end of the data —
     * but the deep hash loop treats it as one and hashes what it got. The
     * recorded hash was of the whole body, so the row reports as corrupt; what
     * matters is that the loop *stopped* instead of spinning on the stall.
     */
    public function deepVerifyStopsHashingAtAStalledRead(): void
    {
        $file = $this->storeFile('a', hash: $this->hash());
        $store = Understudy::for(\Rasuvaeff\Yii3Filestorage\Store\StoreInterface::class);
        Understudy::forwarding($store, $this->store);
        when(static fn(): ?\Psr\Http\Message\StreamInterface => $store->stream(Arg::any()))
            ->returns(StallingStream::midStream('hello'));

        $tester = $this->run(
            new VerifyCommand(new StoreRegistry([$store]), $this->repository),
            ['--deep' => true],
        );

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains(sprintf('corrupt: a (%s)', $file->relativePath));
        Assert::string($tester->getDisplay())->contains('0 rows without an object, 1 with the wrong content.');
    }

    // ---- backfill-hash --------------------------------------------------

    public function backfillWritesNothingWithoutApply(): void
    {
        $this->storeFile('a');

        $tester = $this->run(new BackfillHashCommand($this->registry(), $this->repository));

        Assert::string($tester->getDisplay())->contains('Would hash 1 of 1 row');
        Assert::null($this->repository->find('a')?->contentHash);
    }

    public function backfillFillsInTheMissingHashes(): void
    {
        $this->storeFile('a');

        $tester = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::same($this->repository->find('a')?->contentHash, $this->hash());
        // A clean run says nothing about unreadable rows — "0 rows could not
        // be read" is noise an operator learns to skip past.
        Assert::string($tester->getDisplay())->notContains('could not be read');
    }

    /**
     * The page budget shrinks as the cursor advances: 700 rows with a limit of
     * 600 must hash exactly 600. A budget that grew with what was already seen
     * (limit + seen) would page 500 + 500 and hash all 700.
     */
    public function backfillHashesExactlyTheLimitAcrossPages(): void
    {
        for ($i = 0; $i < 700; ++$i) {
            $this->storeFile("r{$i}", contents: (string) $i);
        }

        $display = $this->run(
            new BackfillHashCommand($this->registry(), $this->repository),
            ['--apply' => true, '--limit' => '600'],
        )->getDisplay();

        Assert::string($display)->contains('Hashed 600 of 600 rows.');
    }

    /**
     * The same shrinking budget on verify: 700 rows, a limit of 600 — exactly
     * 600 checked, however many pages it takes.
     */
    public function verifyChecksExactlyTheLimitAcrossPages(): void
    {
        for ($i = 0; $i < 700; ++$i) {
            $this->row("r{$i}", 'common', 5);
        }

        $display = $this->run(
            new VerifyCommand($this->registry(), $this->repository),
            ['--limit' => '600'],
        )->getDisplay();

        Assert::string($display)->contains('Checked 600 rows.');
    }

    /**
     * Rows that already have one are skipped, so a second run over the same
     * range does nothing — and re-hashing everything nightly is not what a
     * backfill is for.
     */
    public function backfillSkipsRowsThatAlreadyHaveAHash(): void
    {
        $this->storeFile('a', hash: $this->hash());

        $tester = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::string($tester->getDisplay())->contains('Hashed 0 of 1 row');
    }

    public function backfillReportsRowsItCannotRead(): void
    {
        $file = $this->storeFile('a');
        $this->store->deleteObject(new StoredObjectId($file->relativePath));

        $tester = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::string($tester->getDisplay())->contains('unreadable: a');
        Assert::null($this->repository->find('a')?->contentHash);
    }

    /**
     * A stalled read ends the hash loop with whatever was read — here, nothing.
     * The digest of the empty string is stored honestly; a run over a stalled
     * object produces a row `filestorage:verify --deep` will flag, not a
     * silently-spun loop or a half-body digest.
     */
    public function backfillStoresTheHashOfWhatItReadWhenTheStreamStalls(): void
    {
        $this->storeFile('a');
        $store = Understudy::for(\Rasuvaeff\Yii3Filestorage\Store\StoreInterface::class);
        Understudy::forwarding($store, $this->store);
        when(static fn(): ?\Psr\Http\Message\StreamInterface => $store->stream(Arg::any()))
            ->returns(StallingStream::midStream('hello'));

        $tester = $this->run(
            new BackfillHashCommand(new StoreRegistry([$store]), $this->repository),
            ['--apply' => true],
        );

        Assert::same($this->repository->find('a')?->contentHash, hash('sha256', ''));
        Assert::string($tester->getDisplay())->contains('Hashed 1 of 1 row.');
    }


    public function backfillReportsExactCountsInBothNumbers(): void
    {
        $this->storeFile('a', hash: $this->hash());
        $this->storeFile('b', contents: 'other');

        $display = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true])
            ->getDisplay();

        Assert::string($display)->contains('Hashed 1 of 2 rows.');
        Assert::same($this->repository->find('b')?->contentHash, hash('sha256', 'other'));
    }

    public function backfillPluralisesTheRowsItCouldNotRead(): void
    {
        foreach (['a', 'b'] as $id) {
            $file = $this->storeFile($id, contents: $id);
            $this->store->deleteObject(new StoredObjectId($file->relativePath));
        }

        $display = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true])
            ->getDisplay();

        Assert::string($display)->contains('2 rows could not be read');
    }

    public function backfillResumesAfterACursor(): void
    {
        $this->storeFile('a');
        $this->storeFile('b', contents: 'other');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true, '--after' => 'a']);

        Assert::null($this->repository->find('a')?->contentHash);
        Assert::same($this->repository->find('b')?->contentHash, hash('sha256', 'other'));
    }

    public function backfillStopsAtItsLimit(): void
    {
        $this->storeFile('a');
        $this->storeFile('b', contents: 'other');

        $display = $this->run(
            new BackfillHashCommand($this->registry(), $this->repository),
            ['--apply' => true, '--limit' => '1'],
        )->getDisplay();

        Assert::string($display)->contains('Hashed 1 of 1 row.');
        Assert::null($this->repository->find('b')?->contentHash);
    }


    /**
     * The dry-run note is the difference between a report and a surprise, so it
     * appears exactly when nothing is being written.
     */
    public function backfillAnnouncesADryRunAndOnlyADryRun(): void
    {
        $this->storeFile('a');

        Assert::string($this->run(new BackfillHashCommand($this->registry(), $this->repository))->getDisplay())
            ->contains('Dry run. No hashes will be written');

        Assert::string(
            $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true])
                ->getDisplay(),
        )->notContains('Dry run');
    }

    public function anImpossibleBackfillLimitStillHashesOneRow(): void
    {
        $this->storeFile('a');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true, '--limit' => '0']);

        Assert::same($this->repository->find('a')?->contentHash, $this->hash());
    }

    /**
     * A row that already has a hash is stepped over, not stopped at. Breaking
     * out would make the command useless on any table whose first row was
     * already done — which, after one successful run, is every table.
     */
    public function backfillWalksPastRowsItSkips(): void
    {
        $this->storeFile('a', hash: $this->hash());
        $this->storeFile('b', contents: 'other');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::same($this->repository->find('b')?->contentHash, hash('sha256', 'other'));
    }

    public function backfillWalksPastRowsItCannotRead(): void
    {
        $gone = $this->storeFile('a');
        $this->store->deleteObject(new StoredObjectId($gone->relativePath));
        $this->storeFile('b', contents: 'other');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true]);

        Assert::same($this->repository->find('b')?->contentHash, hash('sha256', 'other'));
    }

    /**
     * The cursor is printed on every run, success included: it is what the next
     * invocation resumes from, and a backfill over a large table is a sequence
     * of bounded runs, not one.
     */
    public function backfillPrintsTheCursorItStoppedAt(): void
    {
        $this->storeFile('a');
        $this->storeFile('b', contents: 'other');

        $display = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true])
            ->getDisplay();

        Assert::string($display)->contains('Last id: b');
    }

    public function backfillPrintsNoCursorWhenThereWasNothingToWalk(): void
    {
        $display = $this->run(new BackfillHashCommand($this->registry(), $this->repository))->getDisplay();

        Assert::string($display)->notContains('Last id:');
    }

    /**
     * A healthy row does not end the walk. Stopping at the first one would make
     * the command find only problems that happen to sort first.
     */
    public function backfillDoesNotStopAtAHealthyRow(): void
    {
        $this->storeFile('a');
        $gone = $this->storeFile('b', contents: 'other');
        $this->store->deleteObject(new StoredObjectId($gone->relativePath));

        $display = $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => true])
            ->getDisplay();

        Assert::string($display)->contains('unreadable: b');
        Assert::string($display)->contains('1 row could not be read');
    }

    // ---- stat -----------------------------------------------------------

    public function statReportsNothingWhenThereIsNothing(): void
    {
        $tester = $this->run(new StatCommand($this->repository));

        Assert::string($tester->getDisplay())->contains('No files stored');
    }

    public function statGroupsAndTotals(): void
    {
        $this->storeFile('a', group: 'avatars');
        $this->storeFile('b', group: 'documents');
        $this->storeFile('c', group: 'documents');

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->contains('avatars');
        Assert::string($display)->contains('documents');
        Assert::string($display)->contains('total');
        Assert::string($display)->contains('Distinct objects: 3');
    }

    /**
     * The column that answers "is deduplication earning its keep": two rows on
     * one object are counted once physically and twice logically.
     */
    public function statReportsWhatSharingSaved(): void
    {
        $shared = $this->storeFile('a');
        $this->repository->save(File::create(
            id: 'b',
            storeName: 'memory',
            groupName: 'common',
            relativePath: $shared->relativePath,
            originalName: 'again.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->contains('Distinct objects: 1');
        Assert::string($display)->contains('1 row reuse an existing object');
    }


    public function statSaysSoWhenNothingIsShared(): void
    {
        $this->storeFile('a');

        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())
            ->contains('No rows share an object.');
    }

    public function statPluralisesTheSharingRows(): void
    {
        $shared = $this->storeFile('a');
        $this->reuse('b', $shared);
        $this->reuse('c', $shared);

        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())
            ->contains('2 rows reuse an existing object, saving 10 B.');
    }

    /**
     * Sizes are reported in the largest unit that fits, and stop at TiB —
     * a raw byte count is unreadable at scale, and an unbounded loop would run
     * off the end of the unit table.
     */
    public function statScalesTheUnitToTheSize(): void
    {
        $this->row('a', 'common', 512);
        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())->contains('512 B');

        $this->repository->clear();
        $this->row('b', 'common', 2_048);
        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())->contains('2.0 KiB');

        $this->repository->clear();
        $this->row('c', 'common', 5 * 1024 ** 5);
        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())->contains('TiB');
    }

    /**
     * Groups come out sorted, so two runs over the same data are comparable
     * without the reader diffing a table that reshuffled itself.
     */
    public function statOrdersGroupsByName(): void
    {
        $this->row('a', 'zulu', 1);
        $this->row('b', 'alpha', 1);

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::true(strpos($display, 'alpha') < strpos($display, 'zulu'));
    }


    /**
     * Every number in the table, exactly. Counts and byte totals accumulate
     * across a walk, and an accumulator that adds the wrong thing produces a
     * report that is confidently wrong rather than obviously broken.
     */
    public function statAddsUpEveryColumn(): void
    {
        $this->row('a', 'avatars', 100);
        $this->row('b', 'documents', 200);
        $this->row('c', 'documents', 300);

        $display = $this->flatten($this->run(new StatCommand($this->repository))->getDisplay());

        Assert::string($display)->contains('avatars 1 100 B');
        Assert::string($display)->contains('documents 2 500 B');
        Assert::string($display)->contains('total 3 600 B');
    }

    /**
     * Object identity is store plus path. Two stores can hold the same relative
     * path and share nothing — counting them as one object would report savings
     * that do not exist and hide a store's real footprint.
     */
    public function statDoesNotConfusePathsAcrossStores(): void
    {
        $this->row('a', 'common', 5);
        $this->repository->save(File::create(
            id: 'b',
            storeName: 'archive',
            groupName: 'common',
            relativePath: 'a/original.bin',
            originalName: 'a.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->contains('Distinct objects: 2');
        Assert::string($display)->contains('No rows share an object.');
    }

    /**
     * The store and the path are joined by a separator in the identity key,
     * and the separator is load-bearing: store `a` holding `bc/x` and store
     * `ab` holding `c/x` are two objects, while a key built without the
     * separator collides into one "shared" object that does not exist.
     */
    public function statDistinguishesStoreNameFromPathAtTheBoundary(): void
    {
        $this->repository->save(File::create(
            id: 'a',
            storeName: 'a',
            groupName: 'common',
            relativePath: 'bc/original.bin',
            originalName: 'a.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));
        $this->repository->save(File::create(
            id: 'b',
            storeName: 'ab',
            groupName: 'common',
            relativePath: 'c/original.bin',
            originalName: 'a.txt',
            size: 5,
            createdAt: $this->at('00:00'),
        ));

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->contains('Distinct objects: 2');
        Assert::string($display)->contains('No rows share an object.');
    }

    /**
     * The unit changes at the boundary, not past it: 1024 bytes is one KiB.
     */
    public function statSwitchesUnitExactlyAtTheBoundary(): void
    {
        $this->row('a', 'common', 1_023);
        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())->contains('1023 B');

        $this->repository->clear();
        $this->row('b', 'common', 1_024);
        Assert::string($this->run(new StatCommand($this->repository))->getDisplay())->contains('1.0 KiB');
    }

    /**
     * Nothing stored means no table at all — a header over an empty body reads
     * as "the query failed", not as "there is nothing here".
     */
    public function statPrintsNoTableWhenThereIsNothing(): void
    {
        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->notContains('Group');
        Assert::string($display)->notContains('total');
    }

    /**
     * The reporting half of the asymmetry `gc --orphans` refuses on. Distinct
     * objects and sharing savings describe physical objects, and a
     * tenant-filtered walk cannot see the rows in other scopes pointing at the
     * same ones — so it would over-count objects and under-count sharing. The
     * command withholds both rather than printing a number it cannot stand
     * behind.
     */
    public function statWithholdsThePhysicalNumbersUnderTenancy(): void
    {
        $shared = $this->storeFile('a');
        $this->reuse('b', $shared);

        $display = $this->run(new StatCommand($this->repository, $this->tenantScope()))->getDisplay();

        // With the colon: the note explaining the omission names the figure too.
        Assert::string($display)->notContains('Distinct objects:');
        Assert::string($display)->notContains('reuse an existing object');
        Assert::string($display)->notContains('No rows share an object');

        // A span *across* the concatenation, not one half of it: asserting a
        // single fragment lets the operands be reordered or dropped undetected,
        // and this note is the only place the reader is told where to get the
        // withheld figures. Whitespace and the note gutter are squeezed out
        // because SymfonyStyle wraps the block.
        Assert::string($this->flatten($display))->contains(
            'less sharing than there is. For the physical figures, run this from a maintenance entry point '
            . 'that leaves ' . FileScopeProviderInterface::class . ' unbound, where the repository sees every row.',
        );
    }

    /**
     * Withholding the physical half does not withhold the logical one: counts
     * and byte totals over a tenant's own rows are exactly what that tenant
     * asked for, and the header says whose they are.
     */
    public function statStillReportsScopedTotals(): void
    {
        $this->row('a', 'avatars', 100);
        $this->row('b', 'documents', 200);

        $display = $this->flatten(
            $this->run(new StatCommand($this->repository, $this->tenantScope()))->getDisplay(),
        );

        Assert::string($display)->contains('Group (current scope)');
        Assert::string($display)->contains('avatars 1 100 B');
        Assert::string($display)->contains('total 2 300 B');
    }

    /**
     * An empty scoped report says which emptiness it means. "No files stored"
     * about one tenant's rows reads as "the installation is empty".
     */
    public function statSaysWhoseEmptinessItIsUnderTenancy(): void
    {
        $display = $this->run(new StatCommand($this->repository, $this->tenantScope()))->getDisplay();

        Assert::string($display)->contains('No files stored in the current scope');
    }

    /**
     * Without a scope provider the physical half is printed, unlabelled — the
     * pair to the test above, so a mutant that drops the branch entirely fails
     * one of them.
     */
    public function statReportsThePhysicalNumbersWithoutAScopeProvider(): void
    {
        $this->row('a', 'avatars', 100);

        $display = $this->run(new StatCommand($this->repository))->getDisplay();

        Assert::string($display)->contains('Distinct objects: 1');
        Assert::string($display)->notContains('current scope only');
    }

    /**
     * Squeezes the box-drawing padding, the wrapping and the block gutter of a
     * rendered report out, so a row or a whole sentence can be asserted as one
     * string.
     */
    private function flatten(string $display): string
    {
        return trim((string) preg_replace('/[\s!]+/u', ' ', $display));
    }

    /**
     * A committed blob whose only reference has been released and whose grace
     * period has already passed: exactly what a collection pass exists to take.
     */
    private function scheduledBlob(string $id, string $contents): BlobId
    {
        $file = $this->storeFile($id, contents: $contents);
        $blob = BlobId::create('memory', $file->relativePath);

        $this->ledger->commit(
            $this->ledger->reserve($blob, hash('sha256', $contents), $file->size, $this->at('00:10')),
            $file,
        );
        $this->ledger->releaseFile($id, $this->at('00:00'));

        return $blob;
    }

    /**
     * A second row on somebody else's object — what deduplication produces, and
     * what `stat` reports as saved.
     */
    private function reuse(string $id, File $shared): void
    {
        $this->repository->save(File::create(
            id: $id,
            storeName: $shared->storeName,
            groupName: $shared->groupName,
            relativePath: $shared->relativePath,
            originalName: $shared->originalName,
            size: $shared->size,
            createdAt: $this->at('00:00'),
        ));
    }

    /**
     * Metadata with no object behind it. `stat` only reads rows, so a size it
     * would take gigabytes to produce physically costs nothing here.
     */
    private function row(string $id, string $group, int $size): void
    {
        $this->repository->save(File::create(
            id: $id,
            storeName: 'memory',
            groupName: $group,
            relativePath: "{$id}/original.bin",
            originalName: 'a.txt',
            size: $size,
            createdAt: $this->at('00:00'),
        ));
    }

    private function storeFile(
        string $id,
        string $group = 'common',
        ?string $hash = null,
        string $contents = 'hello',
    ): File {
        $result = $this->store->write(
            Upload::fromStream($this->factory->createStream($contents), 'a.txt', $this->factory),
            $group,
            new RandomPathGenerator(),
        );

        $file = File::create(
            id: $id,
            storeName: 'memory',
            groupName: $group,
            relativePath: $result->relativePath,
            originalName: 'a.txt',
            size: $result->size,
            createdAt: $this->at('00:00'),
            contentHash: $hash,
        );
        $this->repository->save($file);

        return $file;
    }

    /**
     * Bytes with no metadata row anywhere: an orphan by construction.
     */
    private function writeTo(InMemoryStore $store, string $contents = 'hello'): string
    {
        return $store->write(
            Upload::fromStream($this->factory->createStream($contents), 'a.txt', $this->factory),
            'common',
            new RandomPathGenerator(),
        )->relativePath;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    /**
     * Input built in code reaches a flag as it was written: `ArrayInput` keeps
     * `1` and `'1'` on an option that takes no value. Read strictly, they would
     * turn `--apply` into a dry run and `--deep` into a check of presence only.
     */
    #[DataProvider('truthyFlagProvider')]
    public function gcReadsATruthyApplyAndOrphansAsSet(int|string $flag): void
    {
        $orphan = $this->storeFile('gone');
        $this->repository->clear();

        $this->run(
            new GcCommand($this->registry(), $this->repository, $this->clock()),
            ['--orphans' => $flag, '--apply' => $flag],
        );

        Assert::null($this->store->bytesAt($orphan->relativePath));
    }

    #[DataProvider('truthyFlagProvider')]
    public function verifyReadsATruthyDeepAsSet(int|string $flag): void
    {
        $file = $this->storeFile('a', hash: $this->hash());
        $this->store->corrupt($file->relativePath, 'tampered');

        $tester = $this->run(new VerifyCommand($this->registry(), $this->repository), ['--deep' => $flag]);

        Assert::same($tester->getStatusCode(), Command::FAILURE);
        Assert::string($tester->getDisplay())->contains('corrupt: a');
    }

    #[DataProvider('truthyFlagProvider')]
    public function backfillReadsATruthyApplyAsSet(int|string $flag): void
    {
        $this->storeFile('a');

        $this->run(new BackfillHashCommand($this->registry(), $this->repository), ['--apply' => $flag]);

        Assert::same($this->repository->find('a')?->contentHash, $this->hash());
    }

    public static function truthyFlagProvider(): iterable
    {
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
    }

    private function run(Command $command, array $arguments = []): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * A tenant that never changes. Binding one at all is what makes an
     * installation multi-tenant as far as this package is concerned — which is
     * the condition `gc --orphans` refuses on.
     */
    private function tenantScope(): FileScopeProviderInterface
    {
        $scopes = Understudy::for(FileScopeProviderInterface::class);
        when(static fn(): ?string => $scopes->currentScopeId())->returns('tenant-a');

        return $scopes;
    }

    /**
     * A store with no `MaintenanceStoreInterface`: it cannot be enumerated, so
     * orphans in it cannot be found. Real adapters like this exist — an
     * API-backed store with no listing endpoint — and `gc` has to say so rather
     * than report "no orphans" about a store it never looked at.
     */
    private function unwalkableStore(string $name): StoreInterface
    {
        $store = Understudy::for(StoreInterface::class);
        when(static fn(): string => $store->name())->returns($name);

        return $store;
    }

    /**
     * A maintenance-capable store whose quarantine fails for exactly one object —
     * what a permission problem or a partial outage looks like from the
     * outside. Everything else is answered by the real in-memory store.
     */
    private function sickStore(string $name, string $failingPath): MaintenanceStoreInterface
    {
        $store = Understudy::for(MaintenanceStoreInterface::class);
        Understudy::forwarding($store, $this->store);
        when(static fn(): string => $store->name())->returns($name);
        when(static fn() => $store->quarantine(Arg::satisfies(
            static fn(mixed $objects): bool => $objects instanceof ObjectSet && $objects->contains($failingPath),
            'the failing object',
        ), Arg::any()))->throws(new StoreException('the object store is unreachable'));

        return $store;
    }

    private function registry(): StoreRegistry
    {
        return new StoreRegistry([$this->store]);
    }

    private function clock(): StaticClock
    {
        return new StaticClock($this->at('02:00'));
    }

    private function hash(): string
    {
        return hash('sha256', 'hello');
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-01-01T{$time}:00.000000+00:00");
    }
}
