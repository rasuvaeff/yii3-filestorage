<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Command;

use DateInterval;
use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\InterleavingStore;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

/**
 * Interleavings of `filestorage:gc` with a second collector or an upload,
 * driven at exact points through hooks, against real bytes on disk.
 *
 * Each test asserts the invariant "a committed file row points at bytes that
 * exist". See review findings #2 and #3 of 2026-11-27.
 */
#[Test]
#[Covers(GcCommand::class)]
final class GcRaceTest
{
    private string $root;
    private Psr17Factory $factory;
    private MovableClock $clock;
    private FileSystemStore $disk;
    private InterleavingStore $store;
    private MemoryRepository $repository;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gc-race-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
        // Wall-clock, not the fixed default: FileSystemStore stamps objects with
        // real mtimes, and an age check against a clock in the past would make
        // every object look new and pass these tests for the wrong reason.
        $this->clock = new MovableClock(new DateTimeImmutable());
        $this->disk = new FileSystemStore('cas', $this->root . '/store', $this->factory, quarantinePath: $this->root . '/quarantine');
        $this->store = new InterleavingStore($this->disk);
        $this->repository = new MemoryRepository();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root)) {
            FileHelper::removeDirectory($this->root);
        }
    }

    /**
     * Finding #2. Collector 1 claims a blob, then stalls past its lease TTL
     * before deleting. Collector 2 steals the lease, deletes the bytes and the
     * ledger row. A writer then re-publishes the same content at the same
     * content-addressed key and commits a file row. Collector 1 resumes and
     * deletes the new bytes; its completeDeletion() correctly returns false,
     * but only after the damage is done.
     */
    public function stalledCollectorDoesNotDeleteBytesRepublishedAfterItsLeaseExpired(): void
    {
        $ledger = new MemoryBlobLedger($this->repository);
        $content = 'shared bytes';
        $hash = hash('sha256', $content);
        $blob = BlobId::create('cas', (new Sha256KeyGenerator())->generate('default', $hash));

        $first = $this->publishAndCommit($ledger, $blob, $hash, $content, 'file-1');
        $ledger->releaseFile($first->id, $this->clock->now());

        $republished = null;
        $this->store->onFirstQuarantine(function () use ($ledger, $blob, $hash, $content, &$republished): void {
            // Collector 1 is paused here, holding a lease that is about to expire.
            $this->clock->advance(new DateInterval('PT10M'));
            $this->gc($ledger, $this->repository, ['--apply' => true]);
            Assert::false(is_file($this->root . '/store/' . $blob->relativePath()), 'collector 2 should have deleted the blob');

            $republished = $this->publishAndCommit($ledger, $blob, $hash, $content, 'file-2');
        });

        $this->gc($ledger, $this->repository, ['--apply' => true]);

        Assert::instanceOf($republished, File::class);
        Assert::instanceOf($this->repository->find('file-2'), File::class);
        Assert::true(
            $this->disk->exists($republished),
            'a committed file row points at bytes the stalled collector deleted',
        );
    }

    /**
     * Finding #3, narrow window. The base facade publishes bytes, then saves
     * the row. An orphan sweep that runs in between sees an object nobody
     * references and deletes it; the upload then saves a row pointing at
     * nothing and reports success.
     */
    public function orphanSweepDoesNotDeleteAnUploadBetweenPublicationAndMetadataSave(): void
    {
        $repository = new InterleavingRepository($this->repository);
        $repository->onFirstSave(function () use ($repository): void {
            $this->gc(null, $repository, ['--orphans' => true, '--apply' => true]);
        });

        $file = $this->storage($repository)->add($this->upload('fresh upload'));

        Assert::instanceOf($this->repository->find($file->id), File::class);
        Assert::true($this->disk->exists($file), 'the sweep deleted the bytes of an upload in flight');
    }

    /**
     * Finding #3, wide window. The sweep reads the referenced set once, then
     * walks the store. An upload that completes entirely — bytes and row —
     * after the snapshot but before the walk reaches it is in the listing and
     * not in the snapshot, so a fully committed file loses its bytes. The
     * window is the whole duration of the walk, not a few milliseconds.
     */
    public function orphanSweepDoesNotDeleteAFileCommittedAfterItsReferenceSnapshot(): void
    {
        $file = null;
        $this->store->onFirstObjects(function () use (&$file): void {
            $file = $this->storage($this->repository)->add($this->upload('committed during the walk'));
        });

        $this->gc(null, $this->repository, ['--orphans' => true, '--apply' => true]);

        Assert::instanceOf($file, File::class);
        Assert::instanceOf($this->repository->find($file->id), File::class);
        Assert::true($this->disk->exists($file), 'the sweep deleted the bytes of a committed file');
    }

    /**
     * The documented writer protocol: reserve, publish, commit.
     *
     * @param non-empty-string $hash
     * @param non-empty-string $id
     */
    private function publishAndCommit(MemoryBlobLedger $ledger, BlobId $blob, string $hash, string $content, string $id): File
    {
        $reservation = $ledger->reserve($blob, $hash, \strlen($content), $this->clock->now()->add(new DateInterval('PT1H')));

        // What putIfAbsent() does when the key is absent.
        $absolute = $this->root . '/store/' . $blob->relativePath();
        FileHelper::ensureDirectory(\dirname($absolute));
        file_put_contents($absolute, $content);

        $file = File::create(
            id: $id,
            storeName: $blob->storeName,
            groupName: 'docs',
            relativePath: $blob->relativePath(),
            originalName: 'a.txt',
            size: \strlen($content),
            createdAt: $this->clock->now(),
            contentHash: $hash,
        );
        $ledger->commit($reservation, $file);

        return $file;
    }

    /**
     * @param array<string, bool> $arguments
     */
    private function gc(?MemoryBlobLedger $ledger, MaintenanceRepositoryInterface $repository, array $arguments): CommandTester
    {
        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $repository,
            clock: $this->clock,
            ledger: $ledger,
        ));
        $tester->execute($arguments);

        return $tester;
    }

    private function storage(MaintenanceRepositoryInterface $repository): Storage
    {
        return new Storage(
            stores: new StoreRegistry([$this->store]),
            repository: $repository,
            pathGenerator: new RandomPathGenerator(),
            mimeTypeDetector: new FinfoMimeTypeDetector(),
            idGenerator: new Uuid7IdGenerator($this->clock),
            policies: new PolicyRegistry(),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            clock: $this->clock,
            defaultUrlTtl: new DateInterval('PT1H'),
        );
    }

    private function upload(string $content): Upload
    {
        return Upload::fromStream($this->factory->createStream($content), 'a.txt', $this->factory);
    }
}
