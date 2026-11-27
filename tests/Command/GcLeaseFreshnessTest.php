<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use DateInterval;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingLedger;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * A collector paused between claiming a blob and deleting it may already have
 * been superseded: its lease expired, a second collector took the blob, and a
 * writer re-published the same key. The lease protects the ledger row, not the
 * unlink, so the delete is skipped when less than half of the lease is left.
 * Review finding #2 of 2026-11-27; this narrows the window, it does not close it.
 */
#[Test]
#[Covers(GcCommand::class)]
final class GcLeaseFreshnessTest
{
    private Psr17Factory $factory;
    private MovableClock $clock;
    private InMemoryStore $store;
    private MemoryRepository $repository;
    private MemoryBlobLedger $memory;
    private InterleavingLedger $ledger;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->clock = new MovableClock();
        $this->store = new InMemoryStore('memory', $this->factory, $this->clock);
        $this->repository = new MemoryRepository();
        $this->memory = new MemoryBlobLedger($this->repository);
        $this->ledger = new InterleavingLedger($this->memory);
    }

    public function aCollectorPausedPastHalfItsLeaseDoesNotDelete(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->pauseAfterClaim(seconds: 151);

        $display = $this->gc()->getDisplay();

        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
        $record = $this->memory->find($blob);
        Assert::instanceOf($record, BlobRecord::class);
        Assert::same($record->state, BlobState::PendingDelete);
        Assert::string($display)->contains('less than half of its lease was left');
        Assert::string($display)->contains('Collected 0 blobs.');
    }

    public function exactlyHalfALeaseLeftIsStillEnough(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->pauseAfterClaim(seconds: 150);

        $display = $this->gc()->getDisplay();

        Assert::null($this->store->bytesAt($file->relativePath));
        Assert::null($this->memory->find($blob));
        Assert::string($display)->contains('Collected 1 blob.');
    }

    public function theHalfIsMeasuredAgainstTheConfiguredTtl(): void
    {
        [$file] = $this->scheduledBlob();
        // Ten minutes: a pause of four, which the default five-minute lease
        // would not survive, leaves six — more than half.
        $this->pauseAfterClaim(seconds: 240);

        $this->gc(new DateInterval('PT10M'));

        Assert::null($this->store->bytesAt($file->relativePath));
    }

    public function aCalendarTtlConvertsLikeAFixedOne(): void
    {
        [$file] = $this->scheduledBlob();
        $this->pauseAfterClaim(seconds: 12 * 3600 + 1);

        $this->gc(new DateInterval('P1D'));

        Assert::same($this->store->bytesAt($file->relativePath), 'shared bytes');
    }

    public function aSkippedBlobIsRetriedAtOnce(): void
    {
        [$file, $blob] = $this->scheduledBlob();
        $this->pauseAfterClaim(seconds: 151);
        $this->gc();

        $this->gc();

        Assert::null($this->store->bytesAt($file->relativePath), 'the next run collects it with a fresh lease');
        Assert::null($this->memory->find($blob));
    }

    private function pauseAfterClaim(int $seconds): void
    {
        $this->ledger->onFirstClaim(function () use ($seconds): void {
            $this->clock->advanceSeconds($seconds);
        });
    }

    private function gc(DateInterval $leaseTtl = new DateInterval('PT5M')): CommandTester
    {
        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $this->repository,
            clock: $this->clock,
            ledger: $this->ledger,
            leaseTtl: $leaseTtl,
        ));
        $tester->execute(['--apply' => true, '--limit' => '1']);

        return $tester;
    }

    /**
     * @return array{File, BlobId}
     */
    private function scheduledBlob(): array
    {
        $contents = 'shared bytes';
        $result = $this->store->write(
            Upload::fromStream($this->factory->createStream($contents), 'a.txt', $this->factory),
            'common',
            new RandomPathGenerator(),
        );
        $file = File::create(
            id: 'a',
            storeName: 'memory',
            groupName: 'common',
            relativePath: $result->relativePath,
            originalName: 'a.txt',
            size: $result->size,
            createdAt: $this->clock->now(),
        );
        $this->repository->save($file);

        $blob = BlobId::create('memory', $file->relativePath);
        $this->ledger->commit(
            $this->ledger->reserve($blob, hash('sha256', $contents), $file->size, $this->clock->now()->modify('+10 minutes')),
            $file,
        );
        $this->ledger->releaseFile('a', $this->clock->now());

        return [$file, $blob];
    }
}
