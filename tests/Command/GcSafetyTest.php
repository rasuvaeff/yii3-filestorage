<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use DateInterval;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceAuthority;
use Rasuvaeff\Yii3Filestorage\Reference\ReferenceCheckerInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingLedger;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingStore;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ListReferenceChecker;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The 0.9 safety rules of `filestorage:gc`: minimum age, quarantine and
 * retention, recovery of a crashed collector, resumable sweeps, the layout,
 * and references the application owns.
 */
#[Test]
#[Covers(GcCommand::class)]
final class GcSafetyTest
{
    private const string START = '2026-01-01T00:00:00.000000+00:00';

    private Psr17Factory $factory;
    private MovableClock $clock;
    private InMemoryStore $store;
    private MemoryRepository $repository;
    private MemoryBlobLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->clock = new MovableClock(self::START);
        $this->store = new InMemoryStore('memory', $this->factory, $this->clock);
        $this->repository = new MemoryRepository();
        $this->ledger = new MemoryBlobLedger($this->repository);
    }

    // ---- minimum age ------------------------------------------------------

    public function anOrphanYoungerThanTheMinimumAgeIsKeptAndCounted(): void
    {
        $this->store->corrupt('docs/a/young.bin', 'young');
        $this->clock->advance(new DateInterval('PT59M'));

        $json = $this->json(['--orphans' => true, '--apply' => true]);

        Assert::same($this->store->bytesAt('docs/a/young.bin'), 'young');
        Assert::same($json['orphanObjects'], 0);
        Assert::same($json['orphansTooNew'], 1);
        Assert::same($json['orphansUndated'], 0);
    }

    public function theMinimumAgeIsExclusive(): void
    {
        $this->store->corrupt('docs/a/edge.bin', 'edge');
        $this->clock->advance(new DateInterval('PT1H'));

        $display = $this->run(['--orphans' => true, '--apply' => true])->getDisplay();

        Assert::same($this->store->bytesAt('docs/a/edge.bin'), 'edge', 'exactly the minimum age is still too young');
        Assert::string($display)->contains('Kept 1 unreferenced object younger than --min-age and 0 with no timestamp.');

        $this->clock->advanceSeconds(1);
        $this->run(['--orphans' => true, '--apply' => true]);

        Assert::null($this->store->bytesAt('docs/a/edge.bin'));
        Assert::true($this->store->isQuarantined('docs/a/edge.bin'));
    }

    public function theMinimumAgeCanBeLowered(): void
    {
        $this->store->corrupt('docs/a/young.bin', 'young');
        $this->clock->advanceSeconds(1);

        $this->run(['--orphans' => true, '--apply' => true, '--min-age' => 'PT0S']);

        Assert::null($this->store->bytesAt('docs/a/young.bin'));
    }

    public function theConstructorGracePeriodIsTheDefaultMinimumAge(): void
    {
        $this->store->corrupt('docs/a/young.bin', 'young');
        $this->clock->advance(new DateInterval('PT11M'));

        $this->run(['--orphans' => true, '--apply' => true], gracePeriod: new DateInterval('PT10M'));

        Assert::null($this->store->bytesAt('docs/a/young.bin'));
    }

    public function anObjectWithoutATimestampIsNeverAnOrphan(): void
    {
        $this->store->corrupt('docs/a/undated.bin', 'undated');
        $this->clock->advance(new DateInterval('P30D'));
        $undated = new InterleavingStore($this->store);
        $undated->withoutTimestamps();

        $tester = $this->tester(stores: new StoreRegistry([$undated]));
        $tester->execute(['--orphans' => true, '--apply' => true, '--format' => 'json']);
        $json = $this->decode($tester);

        Assert::same($this->store->bytesAt('docs/a/undated.bin'), 'undated');
        Assert::same($json['orphansUndated'], 1);
        Assert::same($json['orphansTooNew'], 0);
    }

    public function aDryRunNamesTheOrphanButMovesNothing(): void
    {
        $this->store->corrupt('docs/a/old.bin', 'old');
        $this->clock->advance(new DateInterval('PT2H'));

        $display = $this->run(['--orphans' => true])->getDisplay();

        Assert::string($display)->contains('would delete docs/a/old.bin');
        Assert::same($this->store->bytesAt('docs/a/old.bin'), 'old');
        Assert::false($this->store->isQuarantined('docs/a/old.bin'));
    }

    // ---- quarantine and retention ------------------------------------------

    public function aSweptOrphanStaysRestorableForTheRetention(): void
    {
        $this->store->corrupt('docs/a/old.bin', 'old');
        $this->clock->advance(new DateInterval('PT2H'));
        $this->run(['--orphans' => true, '--apply' => true]);

        $this->clock->advance(new DateInterval('P7D'));
        $this->clock->advanceSeconds(-1);
        $kept = $this->json(['--apply' => true]);
        Assert::same($kept['quarantine'], ['restored' => 0, 'purged' => 0, 'kept' => 1]);
        Assert::true($this->store->isQuarantined('docs/a/old.bin'));

        $this->clock->advanceSeconds(1);
        $dry = $this->run([])->getDisplay();
        Assert::string($dry)->contains('would purge quarantined docs/a/old.bin');
        Assert::true($this->store->isQuarantined('docs/a/old.bin'), 'a dry run purges nothing');

        $purged = $this->json(['--apply' => true]);
        Assert::same($purged['quarantine'], ['restored' => 0, 'purged' => 1, 'kept' => 0]);
        Assert::false($this->store->isQuarantined('docs/a/old.bin'));
    }

    public function theRetentionCanBeShortened(): void
    {
        $this->store->corrupt('docs/a/old.bin', 'old');
        $this->clock->advance(new DateInterval('PT2H'));
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('PT1H'));

        $display = $this->run(['--apply' => true, '--retention' => 'PT1H'])->getDisplay();

        Assert::string($display)->contains('purging quarantined docs/a/old.bin');
        Assert::false($this->store->isQuarantined('docs/a/old.bin'));
    }

    public function theConstructorRetentionIsTheDefault(): void
    {
        $this->store->corrupt('docs/a/old.bin', 'old');
        $this->clock->advance(new DateInterval('PT2H'));
        $this->run(['--orphans' => true, '--apply' => true], retention: new DateInterval('PT0S'));

        $this->run(['--apply' => true], retention: new DateInterval('PT0S'));

        Assert::false($this->store->isQuarantined('docs/a/old.bin'));
    }

    public function aCommittedBlobDeletionIsPurgedAtOnceWithItsDerivatives(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->store->corrupt($file->directory() . '/thumb.webp', 'thumb');

        $json = $this->json(['--apply' => true], ledger: $this->ledger);

        Assert::same($json['blobsCollected'], 1);
        Assert::null($this->ledger->find($blob));
        Assert::same($this->store->paths(), []);
        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
    }

    /**
     * A collector that crashed between quarantine and commit leaves the blob
     * in `deleting` with an expired lease and its bytes in the quarantine.
     * The next run puts them back first; collecting them again is then an
     * ordinary pass.
     */
    public function bytesOfACrashedCollectorAreRestoredBeforeAnything(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT5M'));
        $this->clock->advance(new DateInterval('PT10M'));

        $display = $this->run(['--apply' => true, '--limit' => '1'], ledger: $this->ledger)->getDisplay();

        Assert::string($display)->contains('restoring quarantined ' . $file->relativePath);
        Assert::null($this->ledger->find($blob), 'and then collected for real');
        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
    }

    public function aRestoreAloneLeavesTheBlobCollectable(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT5M'));
        $this->clock->advance(new DateInterval('PT10M'));

        $this->run(['--apply' => true], ledger: $this->ledger, withBlobs: false);

        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
        Assert::instanceOf($this->ledger->find($blob), BlobRecord::class);
    }

    public function anotherCollectorsLiveQuarantineIsLeftAlone(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT1H'));

        $json = $this->json(['--apply' => true], ledger: $this->ledger);

        Assert::same($json['quarantine'], ['restored' => 0, 'purged' => 0, 'kept' => 1]);
        Assert::null($this->store->bytesAt($file->relativePath));
        Assert::true($this->store->isQuarantined($file->relativePath));
    }

    public function aLeaseExpiringExactlyNowIsNoLongerLive(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT5M'));
        $this->clock->advance(new DateInterval('PT5M'));

        $json = $this->json(['--apply' => true], ledger: $this->ledger, withBlobs: false);

        Assert::same($json['quarantine'], ['restored' => 1, 'purged' => 0, 'kept' => 0]);
        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
    }

    /**
     * A writer revived the blob after the crash: the ledger row is active
     * again, so the quarantined bytes go back however old the entry is.
     */
    public function aRevivedBlobIsRestoredWhateverTheRetention(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId($file->relativePath)), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);
        $this->ledger->reserve($blob, hash('sha256', 'shared bytes'), 12, $this->clock->now()->add(new DateInterval('P1Y')));
        $this->clock->advance(new DateInterval('P30D'));

        $this->run(['--apply' => true], ledger: $this->ledger, withBlobs: false);

        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
    }

    // ---- resumable sweep ----------------------------------------------------

    public function aSweepStoppedByItsLimitPrintsWhereToResume(): void
    {
        $this->oldOrphans('docs/a.bin', 'docs/b.bin', 'docs/c.bin');

        $first = $this->json(['--orphans' => true, '--apply' => true, '--limit' => '1']);
        Assert::same($first['orphanObjects'], 1);
        Assert::same($first['nextCursor'], ['store' => 'memory', 'after' => 'docs/a.bin']);

        $display = $this->run([
            '--orphans' => true,
            '--apply' => true,
            '--limit' => '1',
            '--store' => 'memory',
            '--after' => 'docs/a.bin',
        ])->getDisplay();
        Assert::string($display)->contains('Resume with --orphans --store=memory --after=docs/b.bin');
        Assert::same($this->store->paths(), ['docs/c.bin']);
    }

    public function aCompleteSweepHasNoCursor(): void
    {
        $this->oldOrphans('docs/a.bin');

        $json = $this->json(['--orphans' => true, '--apply' => true]);

        Assert::null($json['nextCursor']);
    }

    public function maxScanBoundsTheObjectsExaminedNotOnlyTheDeletions(): void
    {
        $this->oldOrphans('docs/a/x.bin', 'docs/b/x.bin', 'docs/c/x.bin');
        $this->repository->save($this->row('keep', 'docs/a/x.bin'));

        $json = $this->json(['--orphans' => true, '--apply' => true, '--max-scan' => '2']);

        Assert::same($json['orphanObjects'], 1);
        Assert::same($json['nextCursor'], ['store' => 'memory', 'after' => 'docs/b/x.bin']);
        Assert::same($this->store->paths(), ['docs/a/x.bin', 'docs/c/x.bin']);
    }

    public function aMaxScanExactlyTheStoreSizeStillReportsACursor(): void
    {
        $this->oldOrphans('docs/a.bin');

        $json = $this->json(['--orphans' => true, '--max-scan' => '1']);

        Assert::same($json['nextCursor'], ['store' => 'memory', 'after' => 'docs/a.bin']);
    }

    public function aStopInTheFirstStoreDoesNotWalkTheNext(): void
    {
        $second = new InMemoryStore('second', $this->factory, $this->clock);
        $second->corrupt('docs/z.bin', 'z');
        $this->oldOrphans('docs/a.bin', 'docs/b.bin');

        $json = $this->json(
            ['--orphans' => true, '--apply' => true, '--max-scan' => '1'],
            stores: new StoreRegistry([$this->store, $second]),
        );

        Assert::same($json['nextCursor'], ['store' => 'memory', 'after' => 'docs/a.bin']);
        Assert::same($second->bytesAt('docs/z.bin'), 'z');
    }

    public function theCursorIsPerStore(): void
    {
        $second = new InMemoryStore('second', $this->factory, $this->clock);
        $second->corrupt('docs/a.bin', 'a');
        $second->corrupt('docs/b.bin', 'b');
        $this->oldOrphans('docs/a.bin');

        $this->run(
            ['--orphans' => true, '--apply' => true, '--store' => 'second', '--after' => 'docs/a.bin'],
            stores: new StoreRegistry([$this->store, $second]),
        );

        Assert::same($second->paths(), ['docs/a.bin']);
        Assert::same($this->store->paths(), ['docs/a.bin'], 'the other store was not walked');
    }

    public function afterNeedsAStoreAndTheOrphanSweep(): void
    {
        $withoutStore = $this->run(['--orphans' => true, '--after' => 'docs/a.bin']);
        $withoutOrphans = $this->run(['--store' => 'memory', '--after' => 'docs/a.bin']);

        foreach ([$withoutStore, $withoutOrphans] as $tester) {
            Assert::same($tester->getStatusCode(), Command::FAILURE);
            Assert::string($this->flatten($tester->getDisplay()))->contains(
                "--after resumes one store's orphan sweep: pass it with --orphans and --store",
            );
        }
    }

    public function anInvalidDurationIsRefusedAsJsonToo(): void
    {
        $minAge = $this->tester();
        $minAge->execute(['--min-age' => '1 hour', '--format' => 'json']);
        $retention = $this->tester();
        $retention->execute(['--retention' => 'P1X']);

        Assert::same($minAge->getStatusCode(), Command::FAILURE);
        Assert::same(
            $this->decode($minAge)['error'],
            'Invalid --min-age: expected an ISO 8601 duration such as PT1H',
        );
        Assert::same($retention->getStatusCode(), Command::FAILURE);
        Assert::string($retention->getDisplay())->contains('Invalid --retention: expected an ISO 8601 duration such as P7D');
    }

    // ---- layout -------------------------------------------------------------

    public function theFlatLayoutMatchesDerivativesToTheirOriginal(): void
    {
        $store = new InMemoryStore('flat', $this->factory, $this->clock, new FlatSiblingLayout());
        foreach (['b/ab/abc.jpg', 'b/ab/abc-s.webp', 'b/ab/abd.jpg', 'b/ab/abd-s.webp'] as $path) {
            $store->corrupt($path, 'x');
        }
        $this->repository->save($this->row('abc', 'b/ab/abc.jpg', store: 'flat'));
        $this->clock->advance(new DateInterval('PT2H'));

        $this->run(['--orphans' => true, '--apply' => true], stores: new StoreRegistry([$store]));

        Assert::same($store->paths(), ['b/ab/abc.jpg', 'b/ab/abc-s.webp']);
    }

    // ---- application-owned references --------------------------------------

    public function anUnreferencedApplicationRowIsReleasedIntoTheQuarantine(): void
    {
        $released = $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $referenced = $this->stored('used', 'avatars', 'avatars/aa/used/original.jpg');
        $rows = $this->stored('docs', 'docs', 'docs/aa/doc/original.pdf');
        $this->clock->advance(new DateInterval('PT2H'));
        $young = $this->stored('young', 'avatars', 'avatars/aa/young/original.jpg');
        $checker = new ListReferenceChecker(['memory:' . $referenced->relativePath]);

        $json = $this->json(['--apply' => true], references: $checker);

        Assert::same($json['releasedRows'], 1);
        Assert::null($this->repository->find('old'));
        Assert::null($this->store->bytesAt($released->relativePath));
        Assert::true($this->store->isQuarantined($released->relativePath), 'restorable for the retention');
        Assert::instanceOf($this->repository->find('used'), File::class);
        Assert::instanceOf($this->repository->find('docs'), File::class, 'a row-owned group is never asked about');
        Assert::instanceOf($this->repository->find('young'), File::class, 'younger than the minimum age');
        Assert::same($this->store->bytesAt($young->relativePath), 'bytes');
        Assert::same($this->store->bytesAt($rows->relativePath), 'bytes');
        Assert::same($checker->asked, [[$released->relativePath, $referenced->relativePath]]);
    }

    public function aDryRunNamesTheRowsItWouldRelease(): void
    {
        $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->clock->advance(new DateInterval('PT2H'));

        $tester = $this->tester(references: new ListReferenceChecker());
        $tester->execute([]);

        Assert::string($tester->getDisplay())->contains('would release old (avatars/aa/old/original.jpg)');
        Assert::instanceOf($this->repository->find('old'), File::class);
    }

    public function withoutACheckerNothingIsReleased(): void
    {
        $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->clock->advance(new DateInterval('PT2H'));

        $json = $this->json(['--apply' => true]);

        Assert::null($json['releasedRows']);
        Assert::instanceOf($this->repository->find('old'), File::class);
    }

    public function releasesCountAgainstTheLimit(): void
    {
        $this->stored('a', 'avatars', 'avatars/aa/a/original.jpg');
        $this->stored('b', 'avatars', 'avatars/aa/b/original.jpg');
        $this->oldOrphans('docs/orphan.bin');

        $json = $this->json(['--apply' => true, '--orphans' => true, '--limit' => '1'], references: new ListReferenceChecker());

        Assert::same($json['releasedRows'], 1);
        Assert::same($json['orphanObjects'], 0);
        Assert::instanceOf($this->repository->find('b'), File::class);
    }

    public function aBlobBackedApplicationRowIsReleasedThroughTheLedger(): void
    {
        $file = $this->sharedRow('shared', 'avatars');
        $this->clock->advance(new DateInterval('PT2H'));

        $this->run(['--apply' => true], ledger: $this->ledger, references: new ListReferenceChecker(), withBlobs: false);

        Assert::null($this->repository->find('shared'));
        $record = $this->ledger->find(BlobId::create('memory', $file->relativePath));
        Assert::instanceOf($record, BlobRecord::class);
        Assert::same($record->state, BlobState::PendingDelete, 'scheduled, not deleted inside the release');
        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
    }

    public function aUniqueApplicationRowIsReleasedThroughTheLedgerWhenOneIsBound(): void
    {
        $file = $this->stored('unique', 'avatars', 'avatars/aa/unique/original.jpg');
        $this->clock->advance(new DateInterval('PT2H'));

        $this->run(['--apply' => true], ledger: $this->ledger, references: new ListReferenceChecker());

        Assert::null($this->repository->find('unique'));
        Assert::true($this->store->isQuarantined($file->relativePath));
    }

    public function aFailedRowDeleteBringsTheBytesBack(): void
    {
        $file = $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->clock->advance(new DateInterval('PT2H'));
        $failing = new InterleavingRepository($this->repository);
        $failing->failDeletesWith(new \RuntimeException('database is gone'));

        try {
            $this->tester(references: new ListReferenceChecker(), repository: $failing)->execute(['--apply' => true]);
            Assert::true(actual: false, message: 'the repository failure should have propagated');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'database is gone');
        }

        Assert::same($this->store->bytesAt($file->relativePath), 'bytes');
        Assert::false($this->store->isQuarantined($file->relativePath));
    }

    public function aBlobTheApplicationStillReferencesIsKept(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $checker = new ListReferenceChecker(['memory:' . $file->relativePath]);

        $display = $this->run(['--apply' => true], ledger: $this->ledger, references: $checker)->getDisplay();

        // Without any whitespace: the console wraps the long key mid-word.
        Assert::string((string) preg_replace('/[\s!]+/u', '', $display))->contains((string) preg_replace('/\s+/', '', sprintf(
            'Kept "%s": the application references it although no file row does. Adopt it into a row.',
            $blob->key(),
        )));
        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
        $record = $this->ledger->find($blob);
        Assert::same($record?->state, BlobState::PendingDelete);
        Assert::same($record?->deleteAfter?->getTimestamp(), $this->clock->now()->add(new DateInterval('PT1H'))->getTimestamp());
    }

    public function anOrphanTheApplicationReferencesIsKept(): void
    {
        $this->oldOrphans('docs/a.bin', 'docs/b.bin');
        $checker = new ListReferenceChecker(['memory:docs/a.bin']);

        $this->run(['--orphans' => true, '--apply' => true], references: $checker);

        Assert::same($this->store->paths(), ['docs/a.bin']);
        Assert::same($checker->asked, [['docs/a.bin', 'docs/b.bin']]);
    }

    public function theCheckerIsNotAskedAboutAPageWithoutCandidates(): void
    {
        $this->oldOrphans('docs/a.bin');
        $this->repository->save($this->row('a', 'docs/a.bin'));
        $checker = new ListReferenceChecker();

        $this->run(['--orphans' => true, '--apply' => true], references: $checker);

        Assert::same($checker->asked, []);
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     */
    private function run(
        array $arguments,
        ?BlobLedgerInterface $ledger = null,
        ?ReferenceCheckerInterface $references = null,
        ?StoreRegistry $stores = null,
        ?DateInterval $gracePeriod = null,
        ?DateInterval $retention = null,
        bool $withBlobs = true,
    ): CommandTester {
        if (!$withBlobs && $ledger instanceof \Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface) {
            $ledger = new InterleavingLedger($ledger);
            $ledger->withoutClaims();
        }

        $tester = $this->tester($ledger, $references, $stores, null, $gracePeriod, $retention);
        $tester->execute($arguments);

        return $tester;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function json(
        array $arguments,
        ?BlobLedgerInterface $ledger = null,
        ?ReferenceCheckerInterface $references = null,
        ?StoreRegistry $stores = null,
        bool $withBlobs = true,
    ): array {
        return $this->decode($this->run($arguments + ['--format' => 'json'], $ledger, $references, $stores, withBlobs: $withBlobs));
    }

    private function tester(
        ?BlobLedgerInterface $ledger = null,
        ?ReferenceCheckerInterface $references = null,
        ?StoreRegistry $stores = null,
        ?MaintenanceRepositoryInterface $repository = null,
        ?DateInterval $gracePeriod = null,
        ?DateInterval $retention = null,
    ): CommandTester {
        return new CommandTester(new GcCommand(
            stores: $stores ?? new StoreRegistry([$this->store]),
            repository: $repository ?? $this->repository,
            clock: $this->clock,
            ledger: $ledger,
            gracePeriod: $gracePeriod ?? new DateInterval('PT1H'),
            references: $references,
            policies: new PolicyRegistry([
                'avatars' => new UploadPolicy(referencesOwnedBy: ReferenceAuthority::Application),
                'docs' => new UploadPolicy(),
            ]),
            retention: $retention ?? new DateInterval('P7D'),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), associative: true, flags: \JSON_THROW_ON_ERROR);
        Assert::true(\is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function oldOrphans(string ...$paths): void
    {
        foreach ($paths as $path) {
            $this->store->corrupt($path, 'orphan');
        }
        $this->clock->advance(new DateInterval('PT2H'));
    }

    private function stored(string $id, string $group, string $path): File
    {
        $this->store->corrupt($path, 'bytes');
        $file = $this->row($id, $path, group: $group);
        $this->repository->save($file);

        return $file;
    }

    private function row(string $id, string $path, string $group = 'docs', string $store = 'memory'): File
    {
        return File::create(
            id: $id,
            storeName: $store,
            groupName: $group,
            relativePath: $path,
            originalName: 'x',
            size: 5,
            createdAt: $this->clock->now(),
        );
    }

    private function sharedRow(string $id, string $group): File
    {
        $path = 'cas/ab/cd/' . hash('sha256', 'shared bytes') . '/original';
        $this->store->corrupt($path, 'shared bytes');
        $blob = BlobId::create('memory', $path);
        $file = $this->row($id, $path, group: $group);
        $this->ledger->commit(
            $this->ledger->reserve($blob, hash('sha256', 'shared bytes'), 12, $this->clock->now()->add(new DateInterval('PT1H'))),
            $file,
        );

        return $file;
    }

    /**
     * @return array{File, BlobId}
     */
    private function scheduledBlob(): array
    {
        $file = $this->sharedRow('shared', 'docs');
        $this->ledger->releaseFile('shared', $this->clock->now());

        return [$file, BlobId::create('memory', $file->relativePath)];
    }

    private function crashAfterQuarantine(BlobId $blob, DateInterval $leaseFor): void
    {
        $lease = $this->ledger->claimForDeletion($this->clock->now(), $this->clock->now()->add($leaseFor));
        Assert::instanceOf($lease, BlobLease::class);
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId($blob->relativePath())), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);
    }

    private function flatten(string $display): string
    {
        return trim((string) preg_replace('/[\s!]+/u', ' ', $display));
    }
}
