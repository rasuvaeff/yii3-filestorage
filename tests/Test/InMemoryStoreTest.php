<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Test;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(InMemoryStore::class)]
final class InMemoryStoreTest
{
    private Psr17Factory $factory;
    private StaticClock $clock;
    private InMemoryStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->clock = new StaticClock(new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'));
        $this->store = new InMemoryStore('memory', $this->factory, $this->clock);
    }

    /**
     * The point of this double: it implements the base contract and
     * maintenance, and nothing else. A fake that implemented every capability
     * would make "what does my code do when the store cannot presign?"
     * untestable — and that is the branch most likely to be wrong.
     */
    public function implementsNeitherUrlsNorRanges(): void
    {
        Assert::false($this->store instanceof StoreUrlProviderInterface);
        Assert::false($this->store instanceof RangeReadableStoreInterface);
    }

    public function writeKeepsTheBytesAndReportsTheSize(): void
    {
        $result = $this->store->write($this->upload('hello'), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::same($result->size, 5);
        Assert::same($this->store->bytesAt($result->relativePath), 'hello');
        Assert::same($this->store->paths(), [$result->relativePath]);
        Assert::same($this->store->writeCount(), 1);
    }

    public function readsReportWhatWasWritten(): void
    {
        $file = $this->write('hello');

        Assert::true($this->store->exists($file));
        Assert::same($this->store->size($file), 5);
        Assert::same($this->store->stream($file)?->getContents(), 'hello');
        Assert::same(
            $this->store->lastModified($file)?->format(File::TIMESTAMP_FORMAT),
            '2026-08-06T12:00:00.000000+00:00',
            'timestamps come from the injected clock',
        );
    }

    public function readsOfAMissingObjectReturnNull(): void
    {
        $file = $this->file('docs/nowhere/original.txt');

        Assert::false($this->store->exists($file));
        Assert::null($this->store->size($file));
        Assert::null($this->store->stream($file));
        Assert::null($this->store->lastModified($file));
        Assert::null($this->store->bytesAt('docs/nowhere/original.txt'));
    }

    /**
     * Mirrors the real store: an existing path is an error, never sharing.
     */
    public function aPathCollisionIsAnError(): void
    {
        $fixed = $this->fixedPathGenerator('docs/fixed/original.txt');

        $this->store->write($this->upload('first'), 'docs', $fixed, 'text/plain');

        Expect::exception(StoreException::class)->withMessageContaining('already exists');

        $this->store->write($this->upload('second'), 'docs', $fixed, 'text/plain');
    }

    /**
     * Also mirrors the real store: crossing the cap stores nothing, rather than
     * leaving a truncated object behind.
     */
    public function crossingTheByteCapStoresNothing(): void
    {
        try {
            $this->store->write(
                $this->upload(str_repeat('x', 100)),
                'docs',
                new RandomPathGenerator(),
                'text/plain',
                maxBytes: 10,
            );
            Assert::true(actual: false, message: 'the write should have been refused');
        } catch (UploadTooLargeException) {
            Assert::same($this->store->paths(), []);
            Assert::same($this->store->writeCount(), 0);
        }
    }

    public function deleteRemovesTheWholeDirectoryIncludingSiblings(): void
    {
        $file = $this->write('original');
        $this->store->corrupt($file->directory() . '/thumb.webp', 'a rendition');

        $this->store->delete($file);

        Assert::same($this->store->paths(), []);
    }

    public function theInventoryPagesAndResumes(): void
    {
        $paths = [];
        for ($i = 0; $i < 4; ++$i) {
            $paths[] = $this->write("body {$i}")->relativePath;
        }
        sort($paths);

        $first = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(limit: 2), preserve_keys: false),
        );
        Assert::same($first, array_slice($paths, 0, 2));

        $second = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(afterPath: $first[1], limit: 10), preserve_keys: false),
        );
        Assert::same($second, array_slice($paths, 2));
    }

    public function deleteObjectRemovesOneObjectAndIsIdempotent(): void
    {
        $file = $this->write('x');
        $object = new StoredObjectId($file->relativePath);

        $this->store->deleteObject($object);
        $this->store->deleteObject($object);

        Assert::same($this->store->paths(), []);
    }

    /**
     * Simulates an object being replaced behind the package's back — what the
     * `content()` cap and `filestorage:verify` exist to cope with.
     */
    public function corruptReplacesTheStoredBytes(): void
    {
        $file = $this->write('small');

        $this->store->corrupt($file->relativePath, str_repeat('x', 1_000));

        Assert::same($this->store->size($file), 1_000);
    }

    public function clearResetsEverythingIncludingTheWriteCount(): void
    {
        $this->write('x');

        $this->store->clear();

        Assert::same($this->store->paths(), []);
        Assert::same($this->store->writeCount(), 0);
    }

    public function withoutAClockTimestampsStillExist(): void
    {
        $store = new InMemoryStore('memory', $this->factory);
        $result = $store->write($this->upload('x'), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::instanceOf($store->lastModified($this->file($result->relativePath)), DateTimeImmutable::class);
    }

    public function anInvalidStoreNameIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid store name');

        new InMemoryStore('bad name', $this->factory);
    }

    /**
     * A generator pinned to one path, so two writes can be aimed at the same
     * target.
     */
    private function fixedPathGenerator(string $path): PathGeneratorInterface
    {
        $generator = Understudy::for(PathGeneratorInterface::class);
        when(static fn(): string => $generator->generate(Arg::any(), Arg::any(), Arg::any()))->returns($path);

        return $generator;
    }

    private function upload(string $body): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), 'thing.txt', $this->factory);
    }

    private function write(string $body): File
    {
        return $this->file(
            $this->store->write($this->upload($body), 'docs', new RandomPathGenerator(), 'text/plain')->relativePath,
        );
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f-' . bin2hex(random_bytes(4)),
            storeName: 'memory',
            groupName: 'docs',
            relativePath: $relativePath,
            originalName: 'thing.txt',
            size: 0,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }
}
