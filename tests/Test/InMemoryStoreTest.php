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
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Tests\Support\StallingStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
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

    /**
     * An empty chunk before EOF is a stalled read, not the end of the upload:
     * storing the bytes read so far would store a truncated object as a
     * complete one. The write fails, and nothing at all is left behind.
     */
    public function aStalledReadMidStreamIsAnErrorRatherThanATruncation(): void
    {
        $upload = Upload::fromStream(StallingStream::midStream('hello'), 'thing.txt', $this->factory);

        try {
            $this->store->write($upload, 'docs', new RandomPathGenerator(), 'text/plain');
            Assert::true(actual: false, message: 'the stalled write should have been refused');
        } catch (StoreException $e) {
            Assert::string($e->getMessage())->contains('before EOF');
            Assert::same($this->store->paths(), [], 'nothing was stored');
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

    /**
     * The prefix is the directory *with its separator*: a sibling key
     * directory whose name merely extends another's (`key` vs `key-extra`)
     * is a different object tree, and deleting one must not swallow the
     * other's bytes.
     */
    public function deleteDoesNotSwallowASiblingDirectoryWhoseNameExtendsItsOwn(): void
    {
        $file = $this->write('original');
        $neighbour = $file->directory() . '-extra/original.txt';
        $this->store->corrupt($neighbour, 'other bytes');

        $this->store->delete($file);

        Assert::null($this->store->bytesAt($file->relativePath), 'the deleted object is gone');
        Assert::same($this->store->bytesAt($neighbour), 'other bytes', 'the neighbour survived');
    }

    /**
     * A body larger than one read-chunk accumulates across the loop: a
     * per-chunk overwrite would store only the last piece under the right path.
     */
    public function aMultiChunkBodyIsStoredWhole(): void
    {
        $body = str_repeat('a', 262_144) . 'tail';
        $result = $this->store->write($this->upload($body), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::same($result->size, 262_148);
        Assert::same($this->store->bytesAt($result->relativePath), $body);
    }

    /**
     * The cap is exclusive: a body exactly at the limit is stored, and only
     * crossing it refuses.
     */
    public function aBodyExactlyAtTheByteCapIsStored(): void
    {
        $result = $this->store->write(
            $this->upload('1234567890'),
            'docs',
            new RandomPathGenerator(),
            'text/plain',
            maxBytes: 10,
        );

        Assert::same($result->size, 10);
        Assert::same($this->store->writeCount(), 1);
    }

    public function theInventoryPagesAndResumes(): void
    {
        $paths = [];
        for ($i = 0; $i < 4; ++$i) {
            $paths[] = $this->write("body {$i}")->relativePath;
        }
        sort($paths);

        $first = array_map(
            static fn(StoredObject $object): string => $object->relativePath,
            iterator_to_array($this->store->objects(limit: 2), preserve_keys: false),
        );
        Assert::same($first, array_slice($paths, 0, 2));

        $second = array_map(
            static fn(StoredObject $object): string => $object->relativePath,
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

    public function theInventoryCarriesTimestampAndSize(): void
    {
        $file = $this->write('four');

        $objects = iterator_to_array($this->store->objects(), preserve_keys: false);

        Assert::same($objects[0]->relativePath, $file->relativePath);
        Assert::same($objects[0]->size, 4);
        Assert::same($objects[0]->lastModified, $this->clock->now());
    }

    public function touchBackDatesAnObjectAndIgnoresAMissingOne(): void
    {
        $file = $this->write('x');
        $old = new DateTimeImmutable('2020-01-01T00:00:00+00:00');

        $this->store->touch($file->relativePath, $old);
        $this->store->touch('nowhere/x', $old);

        Assert::same($this->store->lastModified($file), $old);
        Assert::same($this->store->paths(), [$file->relativePath]);
    }

    public function anObjectPlacedBehindTheStoresBackIsStampedButAReplacedOneKeepsItsStamp(): void
    {
        $file = $this->write('x');
        $old = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $this->store->touch($file->relativePath, $old);

        $this->store->corrupt($file->relativePath, 'y');
        $this->store->corrupt('placed/x.bin', 'z');

        Assert::same($this->store->lastModified($file), $old);
        Assert::same($this->store->lastModified($this->file('placed/x.bin')), $this->clock->now());
    }

    public function quarantineRestoreAndPurgeRoundTrip(): void
    {
        $file = $this->write('original');
        $this->store->corrupt($file->directory() . '/thumb.webp', 'thumb');
        $this->store->corrupt('docs/other/original.txt', 'other');
        $now = new DateTimeImmutable('@1764237600');

        $entry = $this->store->quarantine($this->store->layout()->deletion(new StoredObjectId($file->relativePath)), $now);

        Assert::instanceOf($entry, QuarantinedObject::class);
        Assert::same(\count($entry->objects), 2);
        Assert::same($entry->quarantinedAt->getTimestamp(), 1_764_237_600);
        Assert::same($this->store->paths(), ['docs/other/original.txt']);
        Assert::true($this->store->isQuarantined($file->relativePath));
        Assert::false($this->store->isQuarantined('docs/other/original.txt'));
        Assert::same(
            array_map(static fn(QuarantinedObject $e): string => $e->token, iterator_to_array($this->store->quarantined(), preserve_keys: false)),
            [$entry->token],
        );

        $this->store->corrupt($file->relativePath, 'republished');
        $this->store->restore($entry);

        Assert::same($this->store->bytesAt($file->relativePath), 'republished', 'put-if-absent keeps the newer copy');
        Assert::same($this->store->bytesAt($file->directory() . '/thumb.webp'), 'thumb');
        Assert::false($this->store->isQuarantined($file->relativePath));
        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
        Assert::null($this->store->quarantineProblem());
    }

    public function aRestoredObjectKeepsItsTimestamp(): void
    {
        $file = $this->write('x');
        $old = new DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $this->store->touch($file->relativePath, $old);

        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId($file->relativePath)), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);
        $this->store->restore($entry);

        Assert::same($this->store->lastModified($file), $old);
    }

    public function purgedObjectsAreGoneForGood(): void
    {
        $file = $this->write('x');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId($file->relativePath)), $this->clock->now());
        Assert::instanceOf($entry, QuarantinedObject::class);

        $this->store->purge($entry);
        $this->store->restore($entry);

        Assert::same($this->store->paths(), []);
        Assert::false($this->store->isQuarantined($file->relativePath));
    }

    public function quarantiningNothingReturnsNull(): void
    {
        Assert::null($this->store->quarantine(ObjectSet::tree(new StoredObjectId('none')), $this->clock->now()));
    }

    public function theQuarantinePagesInTokenOrder(): void
    {
        $tokens = [];
        foreach ([3, 1, 2] as $second) {
            $file = $this->write("x{$second}");
            $entry = $this->store->quarantine(
                ObjectSet::tree(new StoredObjectId($file->relativePath)),
                new DateTimeImmutable('@' . (1_764_237_600 + $second)),
            );
            Assert::instanceOf($entry, QuarantinedObject::class);
            $tokens[$second] = $entry->token;
        }

        $tokensOf = static fn(iterable $entries): array => array_map(
            static fn(QuarantinedObject $e): string => $e->token,
            iterator_to_array($entries, preserve_keys: false),
        );

        Assert::same($tokensOf($this->store->quarantined(limit: 2)), [$tokens[1], $tokens[2]]);
        Assert::same($tokensOf($this->store->quarantined($tokens[1])), [$tokens[2], $tokens[3]]);
    }

    public function clearEmptiesTheQuarantineToo(): void
    {
        $file = $this->write('x');
        $this->store->quarantine(ObjectSet::tree(new StoredObjectId($file->relativePath)), $this->clock->now());

        $this->store->clear();

        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
    }

    public function theLayoutDecidesWhatDeleteRemoves(): void
    {
        $store = new InMemoryStore('memory', $this->factory, $this->clock, new FlatSiblingLayout());
        $store->corrupt('b/ab/abc.jpg', 'original');
        $store->corrupt('b/ab/abc-s.webp', 'thumb');
        $store->corrupt('b/ab/abcd.jpg', 'another');

        $store->delete($this->file('b/ab/abc.jpg'));

        Assert::same($store->paths(), ['b/ab/abcd.jpg']);
        Assert::same($store->layout()::class, FlatSiblingLayout::class);
        Assert::same($this->store->layout()::class, DirectoryLayout::class);
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
