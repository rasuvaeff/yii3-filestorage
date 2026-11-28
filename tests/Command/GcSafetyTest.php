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
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobLedgerInterface;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\CountingLedger;
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
use Yiisoft\Files\FileHelper;

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
        Assert::same($kept['quarantine'], ['restored' => 0, 'purged' => 0, 'kept' => 1, 'failed' => 0]);
        Assert::true($this->store->isQuarantined('docs/a/old.bin'));

        $this->clock->advanceSeconds(1);
        $dry = $this->run([])->getDisplay();
        Assert::string($dry)->contains('would purge quarantined docs/a/old.bin');
        Assert::true($this->store->isQuarantined('docs/a/old.bin'), 'a dry run purges nothing');

        $purged = $this->json(['--apply' => true]);
        Assert::same($purged['quarantine'], ['restored' => 0, 'purged' => 1, 'kept' => 0, 'failed' => 0]);
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
        $this->run(['--orphans' => true, '--apply' => true], retention: new DateInterval('PT1M'));
        $this->clock->advance(new DateInterval('PT61S'));

        $this->run(['--apply' => true], retention: new DateInterval('PT1M'));

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

        Assert::same($json['quarantine'], ['restored' => 0, 'purged' => 0, 'kept' => 1, 'failed' => 0]);
        Assert::null($this->store->bytesAt($file->relativePath));
        Assert::true($this->store->isQuarantined($file->relativePath));
    }

    public function aLeaseExpiringExactlyNowIsNoLongerLive(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT5M'));
        $this->clock->advance(new DateInterval('PT5M'));

        $json = $this->json(['--apply' => true], ledger: $this->ledger, withBlobs: false);

        Assert::same($json['quarantine'], ['restored' => 1, 'purged' => 0, 'kept' => 0, 'failed' => 0]);
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

    // ---- details that decide outcomes ---------------------------------------

    public function aYoungObjectARowOwnsIsNotCountedAsAYoungOrphan(): void
    {
        $this->repository->save($this->row('owned', 'docs/a/original.bin'));
        $this->store->corrupt('docs/a/original.bin', 'owned');
        $this->store->corrupt('docs/a/thumb.webp', 'young derivative');
        $this->store->corrupt('docs/b/x.bin', 'young orphan');

        $json = $this->json(['--orphans' => true]);

        Assert::same($json['orphansTooNew'], 1);
        Assert::same($json['orphansUndated'], 0);
    }

    public function nothingKeptMeansNoKeptLine(): void
    {
        $this->oldOrphans('docs/a/x.bin');

        $display = $this->run(['--orphans' => true, '--apply' => true])->getDisplay();

        Assert::false(str_contains($display, 'Kept '));
        Assert::false(str_contains($display, 'Resume with'));
    }

    public function anUndatedObjectAloneIsReportedInTheKeptLine(): void
    {
        $this->store->corrupt('docs/a/undated.bin', 'undated');
        $this->clock->advance(new DateInterval('P30D'));
        $undated = new InterleavingStore($this->store);
        $undated->withoutTimestamps();

        $display = $this->run(['--orphans' => true], stores: new StoreRegistry([$undated]))->getDisplay();

        Assert::string($this->flatten($display))->contains(
            'Kept 0 unreferenced objects younger than --min-age and 1 with no timestamp.',
        );
    }

    public function rowsOfTwoDirectoriesProtectTheirObjectsInOnePage(): void
    {
        $this->repository->save($this->row('a', 'docs/a/original.bin'));
        $this->repository->save($this->row('b', 'docs/b/original.bin'));
        $this->oldOrphans('docs/a/original.bin', 'docs/a/thumb.webp', 'docs/b/original.bin', 'docs/b/thumb.webp', 'docs/c/x.bin');

        $this->run(['--orphans' => true, '--apply' => true]);

        Assert::same($this->store->paths(), [
            'docs/a/original.bin',
            'docs/a/thumb.webp',
            'docs/b/original.bin',
            'docs/b/thumb.webp',
        ]);
    }

    public function everyPathTheApplicationNamesIsKept(): void
    {
        $this->oldOrphans('docs/a.bin', 'docs/b.bin', 'docs/c.bin');

        $this->run(['--orphans' => true, '--apply' => true], references: new ListReferenceChecker([
            'memory:docs/a.bin',
            'memory:docs/b.bin',
        ]));

        Assert::same($this->store->paths(), ['docs/a.bin', 'docs/b.bin']);
    }

    public function aRowExactlyTheMinimumAgeOldIsNotReleased(): void
    {
        $this->stored('edge', 'avatars', 'avatars/aa/edge/original.jpg');
        $this->clock->advance(new DateInterval('PT1H'));

        $json = $this->json(['--apply' => true], references: new ListReferenceChecker());

        Assert::same($json['releasedRows'], 0);
        Assert::instanceOf($this->repository->find('edge'), File::class);
    }

    public function aReferencedRowDoesNotStopTheReleaseOfTheRowsAfterIt(): void
    {
        $used = $this->stored('a-used', 'avatars', 'avatars/aa/used/original.jpg');
        $this->stored('b-old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->clock->advance(new DateInterval('PT2H'));

        $this->run(['--apply' => true], references: new ListReferenceChecker(['memory:' . $used->relativePath]));

        Assert::instanceOf($this->repository->find('a-used'), File::class);
        Assert::null($this->repository->find('b-old'));
    }

    public function anEntryKeptForAnotherCollectorDoesNotStopTheSweep(): void
    {
        [, $blob] = $this->scheduledBlob();
        $this->crashAfterQuarantine($blob, leaseFor: new DateInterval('PT1H'));
        $this->store->corrupt('docs/orphan.bin', 'orphan');
        $this->clock->advance(new DateInterval('PT30M'));
        $this->run(['--orphans' => true, '--apply' => true, '--min-age' => 'PT0S'], ledger: $this->ledger, withBlobs: false);

        $json = $this->json(['--apply' => true, '--retention' => 'PT0S'], ledger: $this->ledger, withBlobs: false);

        Assert::same($json['quarantine'], ['restored' => 0, 'purged' => 1, 'kept' => 1, 'failed' => 0]);
    }

    public function aStoreThatCannotQuarantineDoesNotStopTheSweepOfTheNext(): void
    {
        $root = sys_get_temp_dir() . '/gc-bare-' . bin2hex(random_bytes(8));
        $bare = new \Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore('bare', $root, $this->factory);
        $this->store->corrupt('docs/orphan.bin', 'orphan');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/orphan.bin')), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);

        try {
            $display = $this->run(
                ['--apply' => true, '--retention' => 'PT0S'],
                stores: new StoreRegistry([$bare, $this->store]),
            )->getDisplay();
        } finally {
            \Yiisoft\Files\FileHelper::removeDirectory($root);
        }

        Assert::string($this->flatten($display))->contains('Quarantine of store "bare" could not be swept');
        Assert::false($this->store->isQuarantined('docs/orphan.bin'));
    }

    /**
     * Under the flat layout a blob's entry holds its derivatives too, and they
     * sort before the original (`-` before `.`): the ledger is asked about
     * every object until one is a blob it knows.
     */
    public function aFlatBlobEntryIsRecognisedByItsOriginal(): void
    {
        $flat = new InMemoryStore('flat', $this->factory, $this->clock, new FlatSiblingLayout());
        $hash = hash('sha256', 'flat bytes');
        $file = $this->sharedRow('flat', 'docs', 'flat bytes', $flat, 'b/ab/cd/' . $hash . '.jpg');
        $flat->corrupt('b/ab/cd/' . $hash . '-s.webp', 'thumb');
        $this->ledger->releaseFile('flat', $this->clock->now());
        $lease = $this->ledger->claimForDeletion($this->clock->now(), $this->clock->now()->add(new DateInterval('PT5M')));
        Assert::instanceOf($lease, BlobLease::class);
        $entry = $flat->quarantine($flat->layout()->deletion(new StoredObjectId($file->relativePath)), $this->clock->now());
        Assert::same(\count($entry?->objects ?? []), 2);
        $this->clock->advance(new DateInterval('PT10M'));

        $this->run(['--apply' => true], ledger: $this->ledger, stores: new StoreRegistry([$flat]), withBlobs: false);

        $paths = $flat->paths();
        sort($paths);
        Assert::same($paths, ['b/ab/cd/' . $hash . '-s.webp', $file->relativePath]);
    }

    public function aBlobKeptForTheApplicationDoesNotStopTheCollection(): void
    {
        [$kept] = $this->scheduledBlob('a', 'kept bytes');
        [, $collected] = $this->scheduledBlob('b', 'collected bytes');

        $this->run(['--apply' => true], ledger: $this->ledger, references: new ListReferenceChecker([
            'memory:' . $kept->relativePath,
        ]));

        Assert::same($this->store->bytesAt($kept->relativePath), 'kept bytes');
        Assert::null($this->ledger->find($collected));
    }

    // ---- helpers ------------------------------------------------------------

    /**
     * @param array<string, mixed> $arguments
     */
    // ---- review 1.0 ---------------------------------------------------------

    /**
     * Review 1.0 A1 (probe `layout_mismatch.php`): flat keys under the default
     * layout made collecting one blob quarantine — and purge — its whole
     * fan-out bucket, a live neighbour included.
     */
    public function aFlatKeyUnderTheDirectoryLayoutIsKeptAndItsNeighbourSurvives(): void
    {
        $bucket = 'sha/ab/cd/';
        $gone = $this->sharedRow('gone', 'docs', 'first', path: $bucket . 'abcd' . str_repeat('0', 60) . '.jpg');
        $live = $this->sharedRow('live', 'docs', 'second', path: $bucket . 'abcd' . str_repeat('1', 60) . '.jpg');
        $this->ledger->releaseFile('gone', $this->clock->now());
        $this->clock->advance(new DateInterval('PT2H'));

        $display = $this->flatten($this->run(['--apply' => true], ledger: $this->ledger)->getDisplay());

        Assert::same($this->store->bytesAt($live->relativePath), 'second');
        Assert::same($this->store->bytesAt($gone->relativePath), 'first');
        Assert::false($this->store->isQuarantined($live->relativePath));
        Assert::string($display)->contains('does not fit the directory layout');
    }

    /**
     * Review 1.0 B5: purging asked only the ledger, so a released file that a
     * row (or the application) points at again was lost after the retention.
     */
    public function aQuarantinedObjectARowReferencesAgainIsRestoredNotPurged(): void
    {
        $this->oldOrphans('docs/k1/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        Assert::true($this->store->isQuarantined('docs/k1/original.bin'));
        $this->repository->save($this->row('back', 'docs/k1/original.bin'));
        $this->clock->advance(new DateInterval('P8D'));

        $json = $this->json(['--apply' => true]);

        Assert::same($this->store->bytesAt('docs/k1/original.bin'), 'orphan');
        Assert::same($json['quarantine']['restored'], 1);
        Assert::same($json['quarantine']['purged'], 0);
    }

    public function aQuarantinedObjectTheApplicationReferencesAgainIsRestored(): void
    {
        $this->oldOrphans('docs/k1/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('P8D'));

        $display = $this->flatten($this->run(
            ['--apply' => true],
            references: new ListReferenceChecker(['memory:docs/k1/original.bin']),
        )->getDisplay());

        Assert::same($this->store->bytesAt('docs/k1/original.bin'), 'orphan');
        Assert::string($display)->contains('a row or the application references it again');
    }

    public function theCheckerIsAskedOncePerQuarantinePage(): void
    {
        $this->oldOrphans('docs/k1/original.bin', 'docs/k2/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('P8D'));
        $checker = new ListReferenceChecker();

        $this->run(['--apply' => true], references: $checker);

        Assert::same($checker->asked, [['docs/k1/original.bin', 'docs/k2/original.bin']]);
    }

    /**
     * Review 1.0 pass 2 (maintenance), N1: a crash between quarantining a
     * released row's bytes and deleting the row left a live row over
     * quarantined bytes. The warning promised "the next run restores it";
     * the next run only looked at rows for entries past the retention, so the
     * file was a 404 for a week. Probe `stuck_restore.php`, without the
     * `P8D` jump the B5 test needs.
     */
    public function aRowStillPointingAtQuarantinedBytesGetsThemBackOnTheNextRun(): void
    {
        $this->stuckRelease('docs', 'docs/k1/original.bin');
        $this->clock->advance(new DateInterval('PT10M'));

        $json = $this->json(['--apply' => true]);

        Assert::same($this->store->bytesAt('docs/k1/original.bin'), 'live bytes');
        Assert::false($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::same($json['quarantine']['restored'], 1);
        Assert::same($json['quarantine']['kept'], 0);
    }

    /**
     * Probe `stuck_restore_checker.php`, second half: the application points
     * at the file again. Restored at once, and the row stays.
     */
    public function anApplicationOwnedRowTheCheckerStillNamesGetsItsBytesBackOnTheNextRun(): void
    {
        $this->stuckRelease('avatars', 'avatars/k1/original.bin');
        $this->clock->advance(new DateInterval('PT10M'));

        $json = $this->json(['--apply' => true], references: new ListReferenceChecker(['memory:avatars/k1/original.bin']));

        Assert::same($this->store->bytesAt('avatars/k1/original.bin'), 'live bytes');
        Assert::true($this->repository->find('f-stuck') instanceof File);
        Assert::same($json['quarantine']['restored'], 1);
        Assert::same($json['releasedRows'], 0);
    }

    /**
     * First half of the same probe: nothing references the file, so the next
     * run puts the bytes back and then finishes the release it interrupted —
     * the row goes and the bytes are quarantined again, a fresh retention.
     */
    public function anInterruptedReleaseIsFinishedByTheNextRun(): void
    {
        $this->stuckRelease('avatars', 'avatars/k1/original.bin');
        $this->clock->advance(new DateInterval('PT10M'));

        $json = $this->json(['--apply' => true], references: new ListReferenceChecker());

        Assert::null($this->repository->find('f-stuck'));
        Assert::true($this->store->isQuarantined('avatars/k1/original.bin'));
        Assert::same($json['quarantine']['restored'], 1);
        Assert::same($json['releasedRows'], 1);
    }

    public function aDryRunOnlySaysItWouldRestore(): void
    {
        $this->stuckRelease('docs', 'docs/k1/original.bin');
        $this->clock->advance(new DateInterval('PT10M'));

        $display = $this->flatten($this->run([])->getDisplay());

        Assert::true($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::string($display)->contains('would restore quarantined docs/k1/original.bin');
    }

    /**
     * A young entry nothing references is left for the retention, as before:
     * the restore-early rule is for a row that still points at it.
     */
    public function aYoungUnreferencedEntryIsKept(): void
    {
        $this->oldOrphans('docs/k1/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('PT10M'));

        $json = $this->json(['--apply' => true]);

        Assert::true($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::same($json['quarantine']['kept'], 1);
        Assert::same($json['quarantine']['restored'], 0);
    }

    /**
     * Under a bound scope provider the rows are one tenant's: a row of
     * another tenant can point at an entry and stay invisible here. A hit
     * still restores; a miss proves nothing, so nothing is purged.
     */
    public function underTenancyAQuarantineEntryIsNeverPurgedButStillRestored(): void
    {
        $this->oldOrphans('docs/k1/original.bin', 'docs/k2/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->repository->save($this->row('back', 'docs/k2/original.bin'));
        $this->clock->advance(new DateInterval('P8D'));

        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            clock: $this->clock,
            scopes: new class implements FileScopeProviderInterface {
                #[\Override]
                public function currentScopeId(): string
                {
                    return 'tenant-a';
                }
            },
        ));
        $tester->execute(['--apply' => true, '--format' => 'json']);
        $json = $this->decode($tester);

        Assert::true($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::same($this->store->bytesAt('docs/k2/original.bin'), 'orphan');
        Assert::same($json['quarantine']['purged'], 0);
        Assert::same($json['quarantine']['restored'], 1);
        Assert::same($json['quarantine']['kept'], 1);
        /** @var list<string> $log */
        $log = $json['log'];
        Assert::string($this->flatten(implode(' ', $log)))->contains('are not purged: a FileScopeProviderInterface is bound');
    }

    /**
     * A bound provider answering null is a maintenance entry point that sees
     * every row — `-db`'s `globalMaintenanceAccess` — and purges as usual.
     */
    public function aScopeProviderWithNoCurrentScopeStillPurges(): void
    {
        $this->oldOrphans('docs/k1/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('P8D'));

        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            clock: $this->clock,
            scopes: new class implements FileScopeProviderInterface {
                #[\Override]
                public function currentScopeId(): ?string
                {
                    return null;
                }
            },
        ));
        $tester->execute(['--apply' => true, '--format' => 'json']);

        Assert::false($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::same($this->decode($tester)['quarantine']['purged'], 1);
    }

    /**
     * Review 1.0 pass 2 (maintenance), N7: every run asked the ledger about
     * every object of every entry, one query each, for the whole retention.
     * A ledger that can batch is asked once per page of the quarantine.
     */
    public function aBatchingLedgerIsAskedOncePerQuarantinePage(): void
    {
        $this->oldOrphans('docs/k1/original.bin', 'docs/k2/original.bin', 'docs/k3/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('PT10M'));
        $ledger = new CountingLedger(new MemoryBlobLedger($this->repository));

        $this->run(['--apply' => true], ledger: $ledger);

        Assert::same($ledger->batches, [3]);
        Assert::same($ledger->finds, 0);
    }

    /**
     * Review 1.0 pass 2 (maintenance), N6, probe `inmem_numeric.php`.
     */
    public function aNumericRootOrphanIsSweptWithoutEndingTheRun(): void
    {
        $this->oldOrphans('123', 'docs/k1/original.bin');

        $json = $this->json(['--orphans' => true, '--apply' => true]);

        Assert::same($json['orphanObjects'], 2);
        Assert::true($this->store->isQuarantined('123'));
        Assert::true($this->store->isQuarantined('docs/k1/original.bin'));
    }

    /**
     * Review 1.0 pass 2 (maintenance), N4, end to end: the orphan sweep of a
     * local store removes the `.part` a crashed write left, once it is older
     * than `--min-age`, and counts it against `--limit`.
     */
    public function theOrphanSweepRemovesAbandonedPartialWrites(): void
    {
        $base = sys_get_temp_dir() . '/gc-partials-' . bin2hex(random_bytes(6));
        $store = new FileSystemStore('local', $base . '/root', $this->factory, quarantinePath: $base . '/q');

        try {
            mkdir($base . '/root/docs/ab/cd/key', 0o777, recursive: true);
            file_put_contents($base . '/root/docs/ab/cd/key/original.jpg.part', 'stalled');
            touch($base . '/root/docs/ab/cd/key/original.jpg.part', $this->clock->now()->getTimestamp() - 7200);
            $stores = new StoreRegistry([$store]);

            $dry = $this->json(['--orphans' => true], stores: $stores);
            Assert::same($dry['abandonedPartials'], 1);
            Assert::string($this->logText($dry))->contains('would remove abandoned partial write docs/ab/cd/key/original.jpg.part');
            Assert::true(is_file($base . '/root/docs/ab/cd/key/original.jpg.part'));

            $json = $this->json(['--orphans' => true, '--apply' => true], stores: $stores);
            Assert::same($json['abandonedPartials'], 1);
            Assert::string($this->logText($json))->contains('removing abandoned partial write docs/ab/cd/key/original.jpg.part');
            Assert::false(is_file($base . '/root/docs/ab/cd/key/original.jpg.part'));
            Assert::same($this->json([], stores: $stores)['abandonedPartials'], null);
        } finally {
            FileHelper::removeDirectory($base);
        }
    }

    /**
     * The partial sweep spends what is left of `--limit` across every store:
     * a store reached with the budget used up is not asked, and one reached
     * with some left is asked for no more than that.
     */
    public function abandonedPartialsShareTheLimitAcrossStores(): void
    {
        $base = sys_get_temp_dir() . '/gc-partials-' . bin2hex(random_bytes(6));

        try {
            $stores = new StoreRegistry([
                $this->localStore($base, 'first', 'docs/a/original.jpg.part', 'docs/b/original.jpg.part'),
                $this->localStore($base, 'second', 'docs/c/original.jpg.part', 'docs/d/original.jpg.part'),
            ]);

            Assert::same($this->json(['--orphans' => true, '--limit' => '2'], stores: $stores)['abandonedPartials'], 2);
            Assert::same($this->json(['--orphans' => true, '--limit' => '3'], stores: $stores)['abandonedPartials'], 3);

            $this->json(['--orphans' => true, '--apply' => true, '--limit' => '3'], stores: $stores);

            Assert::false(is_file($base . '/first/docs/b/original.jpg.part'));
            Assert::false(is_file($base . '/second/docs/c/original.jpg.part'));
            Assert::true(is_file($base . '/second/docs/d/original.jpg.part'), 'the fourth was over the limit');
        } finally {
            FileHelper::removeDirectory($base);
        }
    }

    /**
     * Orphans come first; partial writes get only what they left of `--limit`,
     * and nothing once they used it all.
     */
    public function abandonedPartialsGetWhatTheOrphansLeftOfTheLimit(): void
    {
        $base = sys_get_temp_dir() . '/gc-partials-' . bin2hex(random_bytes(6));

        try {
            $store = $this->localStore($base, 'local', 'docs/a/original.jpg.part', 'docs/b/original.jpg.part');
            mkdir($base . '/local/docs/c', 0o777, recursive: true);
            file_put_contents($base . '/local/docs/c/original.bin', 'orphan');
            touch($base . '/local/docs/c/original.bin', $this->clock->now()->getTimestamp() - 7200);
            $stores = new StoreRegistry([$store]);

            $two = $this->json(['--orphans' => true, '--limit' => '2'], stores: $stores);
            Assert::same($two['orphanObjects'], 1);
            Assert::same($two['abandonedPartials'], 1);

            $one = $this->json(['--orphans' => true, '--limit' => '1'], stores: $stores);
            Assert::same($one['orphanObjects'], 1);
            Assert::same($one['abandonedPartials'], 0);
        } finally {
            FileHelper::removeDirectory($base);
        }
    }

    public function anUnreferencedQuarantinedObjectIsStillPurged(): void
    {
        $this->oldOrphans('docs/k1/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->repository->save($this->row('neighbour', 'docs/k2/original.bin'));
        $this->clock->advance(new DateInterval('P8D'));

        $json = $this->json(['--apply' => true], references: new ListReferenceChecker(['memory:docs/k2/original.bin']));

        Assert::false($this->store->isQuarantined('docs/k1/original.bin'));
        Assert::same($json['quarantine']['purged'], 1);
    }

    /**
     * Review 1.0 B6: one entry that could not be purged stopped the sweep of
     * every entry after it, on every run.
     */
    public function oneStuckQuarantineEntryDoesNotStopTheOthers(): void
    {
        $this->oldOrphans('docs/a/original.bin', 'docs/b/original.bin');
        $this->run(['--orphans' => true, '--apply' => true]);
        $this->clock->advance(new DateInterval('P8D'));
        $store = new InterleavingStore($this->store);
        $store->refuseEntriesHolding('docs/a/original.bin');

        $tester = $this->tester(stores: new StoreRegistry([$store]));
        $tester->execute(['--apply' => true, '--format' => 'json']);
        $json = $this->decode($tester);

        Assert::true($this->store->isQuarantined('docs/a/original.bin'));
        Assert::false($this->store->isQuarantined('docs/b/original.bin'));
        Assert::same($json['quarantine'], ['restored' => 0, 'purged' => 1, 'kept' => 0, 'failed' => 1]);
        Assert::string($this->logText($json))->contains('Permission denied on');
    }

    /**
     * Review 1.0 B7: a restore that failed after a refused commit ended the
     * whole run with a trace and no report.
     */
    public function aFailedRestoreAfterARefusedCommitDoesNotEndTheRun(): void
    {
        [$file] = $this->scheduledBlob();
        $this->clock->advance(new DateInterval('PT2H'));
        $store = new InterleavingStore($this->store);
        $store->refuseEntriesHolding($file->relativePath);
        $ledger = new InterleavingLedger($this->ledger);
        $ledger->refusingCompletion();

        $tester = $this->tester(ledger: $ledger, stores: new StoreRegistry([$store]));
        $status = $tester->execute(['--apply' => true, '--format' => 'json']);
        $json = $this->decode($tester);

        Assert::same($status, Command::SUCCESS);
        Assert::same($json['blobsCollected'], 0);
        Assert::string($this->logText($json))->contains('the next run restores it');
        Assert::true($this->store->isQuarantined($file->relativePath));
    }

    /**
     * Review 1.0 C16: a row the store could not release still counted.
     */
    public function aRowThatCouldNotBeReleasedIsNotCounted(): void
    {
        $this->repository->save($this->row('elsewhere', 'avatars/aa/x/original.jpg', group: 'avatars', store: 'gone'));
        $this->clock->advance(new DateInterval('PT2H'));

        $json = $this->json(['--apply' => true], references: new ListReferenceChecker());

        Assert::same($json['releasedRows'], 0);
        Assert::instanceOf($this->repository->find('elsewhere'), File::class);
    }

    /**
     * Review 1.0 C17: the first warning about a store silenced every later one
     * of a different kind for the same store.
     */
    public function warningsOfDifferentKindsForOneStoreAreAllShown(): void
    {
        $this->repository->save($this->row('elsewhere', 'avatars/aa/x/original.jpg', group: 'avatars', store: 'gone'));
        $this->sharedRow('blob', 'docs', 'gone bytes', new InMemoryStore('gone', $this->factory, $this->clock));
        $this->ledger->releaseFile('blob', $this->clock->now());
        $this->clock->advance(new DateInterval('PT2H'));

        $display = $this->flatten($this->run(['--apply' => true], ledger: $this->ledger, references: new ListReferenceChecker())->getDisplay());

        Assert::string($display)->contains('so its rows are not released');
        Assert::string($display)->contains('so its blobs cannot be collected');
    }

    /**
     * Once per store and kind: the same warning about a second store is not
     * swallowed by the first.
     */
    public function oneWarningKindIsShownForEveryStoreItConcerns(): void
    {
        $this->repository->save($this->row('a', 'avatars/aa/a/original.jpg', group: 'avatars', store: 'gone'));
        $this->repository->save($this->row('b', 'avatars/aa/b/original.jpg', group: 'avatars', store: 'lost'));
        $this->clock->advance(new DateInterval('PT2H'));

        $display = $this->flatten($this->run(['--apply' => true], references: new ListReferenceChecker())->getDisplay());

        Assert::string($display)->contains('Store "gone" cannot quarantine objects');
        Assert::string($display)->contains('Store "lost" cannot quarantine objects');
    }

    /**
     * Review 1.0 C14: a budget used up before the sweep printed "found 0
     * orphaned objects" about objects nobody looked at.
     */
    public function anExhaustedLimitSaysTheOrphanSweepWasSkipped(): void
    {
        $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->oldOrphans('docs/z/original.bin');

        $json = $this->json(['--apply' => true, '--orphans' => true, '--limit' => '1'], references: new ListReferenceChecker());

        Assert::same($json['releasedRows'], 1);
        Assert::true($json['orphanSweepSkipped']);
        Assert::string($this->logText($json))->contains('Orphan sweep skipped: --limit was used up');
        Assert::same($this->store->bytesAt('docs/z/original.bin'), 'orphan');
        Assert::same(
            [$json['orphanObjects'], $json['orphansTooNew'], $json['orphansUndated'], $json['abandonedPartials'], $json['nextCursor']],
            [0, 0, 0, 0, null],
        );
    }

    /**
     * A skipped sweep resumed nothing, so the cursor it was given is the one
     * to resume from next time — and without one there is nothing to resume.
     */
    public function aSkippedSweepHandsBackTheCursorItWasGiven(): void
    {
        $this->stored('old', 'avatars', 'avatars/aa/old/original.jpg');
        $this->oldOrphans('docs/z/original.bin');
        $arguments = ['--orphans' => true, '--limit' => '1', '--store' => 'memory'];
        $references = new ListReferenceChecker();

        $resumed = $this->json($arguments + ['--after' => 'docs/a'], references: $references);
        $fresh = $this->json($arguments, references: $references);

        Assert::true($resumed['orphanSweepSkipped']);
        Assert::same($resumed['nextCursor'], ['store' => 'memory', 'after' => 'docs/a']);
        Assert::true($fresh['orphanSweepSkipped']);
        Assert::null($fresh['nextCursor']);
    }

    public function aSweepThatRanSaysItWasNotSkipped(): void
    {
        Assert::false($this->json(['--orphans' => true])['orphanSweepSkipped']);
    }

    #[\Testo\Data\DataProvider('nonPositiveIntervalProvider')]
    public function aNonPositiveIntervalIsRefused(string $name, DateInterval $interval): void
    {
        \Testo\Expect::exception(\InvalidArgumentException::class)->withMessageContaining("{$name} must be a positive interval");

        new GcCommand(...[
            'stores' => new StoreRegistry([$this->store]),
            'repository' => $this->repository,
            'clock' => $this->clock,
            $name => $interval,
        ]);
    }

    public static function nonPositiveIntervalProvider(): iterable
    {
        $negative = new DateInterval('PT1H');
        $negative->invert = 1;

        yield 'zero grace period' => ['gracePeriod', new DateInterval('PT0S')];
        yield 'negative grace period' => ['gracePeriod', $negative];
        yield 'zero lease' => ['leaseTtl', new DateInterval('PT0S')];
        yield 'zero retention' => ['retention', new DateInterval('P0D')];
    }

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
    /**
     * The JSON log is what text mode would have printed, wrapped at the
     * terminal width: a phrase can straddle two lines.
     *
     * @param array<string, mixed> $json
     */
    private function logText(array $json): string
    {
        Assert::true(\is_array($json['log']));

        return (string) preg_replace('/\s+/', ' ', implode(' ', array_map(strval(...), $json['log'])));
    }

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

    /**
     * A row whose bytes a crashed release left in the quarantine.
     */
    private function stuckRelease(string $group, string $path): void
    {
        $this->store->corrupt($path, 'live bytes');
        $this->repository->save($this->row('f-stuck', $path, group: $group));
        $this->clock->advance(new DateInterval('PT2H'));
        $this->store->quarantine(ObjectSet::tree(new StoredObjectId(\dirname($path))), $this->clock->now());
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

    private function sharedRow(
        string $id,
        string $group,
        string $content = 'shared bytes',
        ?InMemoryStore $store = null,
        ?string $path = null,
    ): File {
        $store ??= $this->store;
        $path ??= 'cas/ab/cd/' . hash('sha256', $content) . '/original';
        $store->corrupt($path, $content);
        $blob = BlobId::create($store->name(), $path);
        $file = $this->row($id, $path, group: $group, store: $store->name());
        $this->ledger->commit(
            $this->ledger->reserve($blob, hash('sha256', $content), \strlen($content), $this->clock->now()->add(new DateInterval('PT1H'))),
            $file,
        );

        return $file;
    }

    /**
     * @return array{File, BlobId}
     */
    private function scheduledBlob(string $id = 'shared', string $content = 'shared bytes'): array
    {
        $file = $this->sharedRow($id, 'docs', $content);
        $this->ledger->releaseFile($id, $this->clock->now());

        return [$file, BlobId::create('memory', $file->relativePath)];
    }

    private function crashAfterQuarantine(BlobId $blob, DateInterval $leaseFor): void
    {
        $lease = $this->ledger->claimForDeletion($this->clock->now(), $this->clock->now()->add($leaseFor));
        Assert::instanceOf($lease, BlobLease::class);
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId($blob->relativePath())), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);
    }

    private function localStore(string $base, string $name, string ...$partials): FileSystemStore
    {
        $store = new FileSystemStore($name, $base . '/' . $name, $this->factory, quarantinePath: $base . '/' . $name . '-q');
        foreach ($partials as $partial) {
            $path = $base . '/' . $name . '/' . $partial;
            mkdir(\dirname($path), 0o777, recursive: true);
            file_put_contents($path, 'stalled');
            touch($path, $this->clock->now()->getTimestamp() - 7200);
        }

        return $store;
    }

    private function flatten(string $display): string
    {
        return trim((string) preg_replace('/[\s!]+/u', ' ', $display));
    }
}
