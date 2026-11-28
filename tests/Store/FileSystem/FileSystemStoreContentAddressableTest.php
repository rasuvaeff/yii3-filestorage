<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use DateInterval;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryBlobLedger;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Rasuvaeff\Yii3Filestorage\Tests\Support\UnsizedStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

/**
 * Review 1.0 B3/B4: a local content-addressable store, so `PublicFileSystemStore`
 * + deduplication works, and keys in an existing `blobs/ab/cd/<sha>.<ext>` tree.
 */
#[Test]
#[Covers(FileSystemStore::class)]
#[Covers(PublicFileSystemStore::class)]
final class FileSystemStoreContentAddressableTest
{
    private string $base;
    private Psr17Factory $factory;
    private FileSystemStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/fs-cas-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
        $this->store = new FileSystemStore(
            'uploads',
            $this->base . '/root',
            $this->factory,
            quarantinePath: $this->base . '/quarantine',
            layout: new FlatSiblingLayout(),
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->base)) {
            FileHelper::removeDirectory($this->base);
        }
    }

    public function bothLocalStoresAreContentAddressable(): void
    {
        Assert::instanceOf($this->store, ContentAddressableStoreInterface::class);
        Assert::instanceOf(new PublicFileSystemStore($this->store, 'https://x.test/u'), ContentAddressableStoreInterface::class);
    }

    public function theFirstPutPublishesAtTheKey(): void
    {
        $key = $this->key('hello');

        $result = $this->store->putIfAbsent($this->upload('hello'), $key);

        Assert::true($result->created);
        Assert::same($result->relativePath, $key->relativePath);
        Assert::same($result->size, 5);
        Assert::same(file_get_contents($this->base . '/root/' . $key->relativePath), 'hello');
        Assert::same($this->leftovers(), [], 'no staging file is left behind');
    }

    public function aSecondPutOfTheSameBytesReusesThem(): void
    {
        $key = $this->key('hello');
        $this->store->putIfAbsent($this->upload('hello'), $key);
        $before = filemtime($this->base . '/root/' . $key->relativePath);
        touch($this->base . '/root/' . $key->relativePath, (int) $before - 100);

        $result = $this->store->putIfAbsent($this->upload('hello'), $key);

        Assert::false($result->created);
        Assert::same($result->size, 5);
        Assert::same(filemtime($this->base . '/root/' . $key->relativePath), (int) $before - 100, 'not rewritten');
    }

    public function differentBytesAtTheKeyAreAnError(): void
    {
        $key = $this->key('hello');
        $this->store->putIfAbsent($this->upload('hello'), $key);

        Expect::exception(StoreException::class)->withMessageContaining('holds 5 bytes but the upload is 4');

        $this->store->putIfAbsent($this->upload('nope'), $key);
    }

    public function aDeclaredSizeOverTheCapIsRefusedBeforeWriting(): void
    {
        $key = $this->key('hello');

        try {
            $this->store->putIfAbsent($this->upload('hello'), $key, maxBytes: 4);
            Assert::fail('expected a size refusal');
        } catch (UploadSizeException $e) {
            Assert::string($e->getMessage())->contains('5 bytes exceeds the 4 byte limit');
        }

        Assert::false(file_exists($this->base . '/root/' . $key->relativePath));
    }

    public function reusingBytesOverTheCallersCapIsRefused(): void
    {
        $key = $this->key('hello');
        $this->store->putIfAbsent($this->upload('hello'), $key);
        Expect::exception(UploadSizeException::class)->withMessageContaining('Upload of 5 bytes exceeds the 4 byte limit');

        $this->store->putIfAbsent($this->unsized('hello'), $key, maxBytes: 4);
    }

    /**
     * The cap is a limit, not a ceiling to stay under: exactly that many
     * bytes are published, and reused.
     */
    public function exactlyTheCapIsPublishedAndReused(): void
    {
        $key = $this->key('hello');

        Assert::true($this->store->putIfAbsent($this->upload('hello'), $key, maxBytes: 5)->created);
        Assert::false($this->store->putIfAbsent($this->upload('hello'), $key, maxBytes: 5)->created);
    }

    public function anEmptyObjectIsReused(): void
    {
        $key = $this->key('');
        $this->store->putIfAbsent($this->upload(''), $key);

        $result = $this->store->putIfAbsent($this->upload(''), $key);

        Assert::false($result->created);
        Assert::same($result->size, 0);
    }

    /**
     * An unsized upload is counted over every chunk it is read in, not only
     * the last one.
     */
    public function anUnsizedUploadLongerThanOneReadIsCountedWhole(): void
    {
        $content = str_repeat('x', 300_000);
        $key = $this->key($content);
        $this->store->putIfAbsent($this->upload($content), $key);

        $result = $this->store->putIfAbsent($this->unsized($content), $key);

        Assert::false($result->created);
        Assert::same($result->size, 300_000);
    }

    public function anUnsizedUploadIsCountedBeforeReuse(): void
    {
        $key = $this->key('hello');
        $this->store->putIfAbsent($this->upload('hello'), $key);

        Assert::false($this->store->putIfAbsent($this->unsized('hello'), $key)->created);

        Expect::exception(StoreException::class)->withMessageContaining('holds 5 bytes but the upload is 6');
        $this->store->putIfAbsent($this->unsized('hello!'), $key);
    }

    public function anUnsizedUploadOverTheCapLeavesNothing(): void
    {
        $key = $this->key('hello');

        try {
            $this->store->putIfAbsent($this->unsized('hello'), $key, maxBytes: 4);
            Assert::fail('expected a size refusal');
        } catch (UploadSizeException) {
        }

        Assert::false(file_exists($this->base . '/root/' . $key->relativePath));
        Assert::same($this->leftovers(), []);
    }

    public function aDirectoryAtTheKeyIsAnError(): void
    {
        $key = $this->key('hello');
        mkdir($this->base . '/root/' . $key->relativePath, 0o777, recursive: true);

        Expect::exception(StoreException::class)->withMessageContaining('is not a regular file');

        $this->store->putIfAbsent($this->upload('hello'), $key);
    }

    public function aSymlinkAtTheKeyIsNeverReused(): void
    {
        $key = $this->key('hello');
        file_put_contents($this->base . '/outside', 'hello');
        mkdir(\dirname($this->base . '/root/' . $key->relativePath), 0o777, recursive: true);
        symlink($this->base . '/outside', $this->base . '/root/' . $key->relativePath);

        Expect::exception(StoreException::class)->withMessageContaining('is not a regular file');

        $this->store->putIfAbsent($this->upload('hello'), $key);
    }

    public function thePublicStoreDelegates(): void
    {
        $public = new PublicFileSystemStore($this->store, 'https://x.test/u');
        $key = $this->key('hello');

        Assert::true($public->putIfAbsent($this->upload('hello'), $key)->created);
        Assert::false($public->putIfAbsent($this->upload('hello'), $key)->created);
    }

    public function cskaOnesTreeIsAddressedAsItIs(): void
    {
        $sha = hash('sha256', 'hello');
        $key = (new FlatSha256KeyGenerator(prefix: 'blobs'))->generate('sha', $sha, 'jpg');

        Assert::same($key, 'blobs/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha . '.jpg');
        Assert::null(LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(prefix: 'blobs'), $this->store->layout()));
        Assert::same(BlobId::create('uploads', $key)->relativePath(), $key);
    }

    /**
     * End to end on the cska-one shape: a collector that crashed between
     * quarantining a released prefixed blob and committing gets it restored
     * by the next run, because the ledger still knows the key — were the key
     * unknown to `BlobId`, the entry would sit in the quarantine as "kept".
     */
    public function aCrashedCollectionOfAPrefixedBlobIsRestored(): void
    {
        $clock = new MovableClock('2026-01-01T00:00:00+00:00');
        $repository = new MemoryRepository();
        $ledger = new MemoryBlobLedger($repository);
        $sha = hash('sha256', 'hello');
        $key = (new FlatSha256KeyGenerator(prefix: 'blobs'))->generate('sha', $sha, 'jpg');
        $this->store->putIfAbsent($this->upload('hello'), new StoredObjectId($key));
        $blob = BlobId::create('uploads', $key);
        $ledger->commit(
            $ledger->reserve($blob, $sha, 5, $clock->now()->add(new DateInterval('PT1H'))),
            File::create(id: 'f', storeName: 'uploads', groupName: 'g', relativePath: $key, originalName: 'x', size: 5, createdAt: $clock->now()),
        );
        $ledger->releaseFile('f', $clock->now());
        $lease = $ledger->claimForDeletion($clock->now(), $clock->now()->add(new DateInterval('PT5M')));
        Assert::instanceOf($lease, BlobLease::class);
        $this->store->quarantine($this->store->layout()->deletion(new StoredObjectId($key)), $clock->now());
        Assert::false(is_file($this->base . '/root/' . $key));
        $clock->advance(new DateInterval('PT10M'));

        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$this->store]),
            repository: $repository,
            clock: $clock,
            ledger: $ledger,
        ));
        $tester->execute(['--apply' => true, '--format' => 'json']);
        $json = json_decode($tester->getDisplay(), associative: true, flags: \JSON_THROW_ON_ERROR);

        Assert::true(\is_array($json));
        Assert::same($json['quarantine']['restored'] ?? null, 1);
        // Restored, then collected for real by the same run: it is released.
        Assert::same($json['blobsCollected'] ?? null, 1);
        Assert::false(is_file($this->base . '/root/' . $key));
    }

    private function key(string $content): StoredObjectId
    {
        return new StoredObjectId((new FlatSha256KeyGenerator())->generate('sha', hash('sha256', $content), 'bin'));
    }

    private function unsized(string $content): Upload
    {
        return Upload::fromStream(new UnsizedStream($this->factory->createStream($content)), 'x.bin', $this->factory);
    }

    private function upload(string $content): Upload
    {
        return Upload::fromStream($this->factory->createStream($content), 'x.bin', $this->factory);
    }

    /**
     * @return list<string>
     */
    private function leftovers(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base . '/root', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (\is_string($file->getFilename()) && str_ends_with($file->getFilename(), '.part')) {
                $found[] = $file->getFilename();
            }
        }

        return $found;
    }
}
