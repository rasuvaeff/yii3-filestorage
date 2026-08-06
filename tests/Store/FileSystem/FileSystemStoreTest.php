<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

#[Test]
#[Covers(FileSystemStore::class)]
final class FileSystemStoreTest
{
    private string $root;
    private Psr17Factory $factory;
    private FileSystemStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fs-store-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
        $this->store = new FileSystemStore('upload', $this->root, $this->factory);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root)) {
            FileHelper::removeDirectory($this->root);
        }
    }

    public function createsItsRootWhenItIsMissing(): void
    {
        Assert::true(is_dir($this->root));
        Assert::same($this->store->name(), 'upload');
    }

    public function writeStoresTheBytesAtTheGeneratedPath(): void
    {
        $result = $this->store->write($this->upload('hello'), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::same($result->size, 5);
        Assert::true($result->created);
        Assert::same(file_get_contents($this->root . '/' . $result->relativePath), 'hello');
    }

    /**
     * Nothing may be visible until the whole copy succeeded, so a reader can
     * never observe a half-written file.
     */
    public function noStagingFileSurvivesASuccessfulWrite(): void
    {
        $this->store->write($this->upload('hello'), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::same(self::filesUnder($this->root, '.part'), []);
    }

    /**
     * A generated path that already exists is an error, never sharing: the
     * caller asked for a new object, and returning somebody else's would tie
     * two logical files to bytes only one of them owns.
     */
    public function aPathCollisionIsAnErrorRatherThanSilentSharing(): void
    {
        $fixed = new class () implements PathGeneratorInterface {
            #[Override]
            public function generate(string $groupName, Upload $upload, ?string $mediaType): string
            {
                return 'docs/fixed/original.txt';
            }
        };

        $this->store->write($this->upload('first'), 'docs', $fixed, 'text/plain');

        Expect::exception(StoreException::class)->withMessageContaining('already exists');

        $this->store->write($this->upload('second'), 'docs', $fixed, 'text/plain');
    }

    /**
     * The cap has to bite mid-copy, not after: the entire point is an
     * unknown-length body that must not be written out in full first.
     */
    public function crossingTheByteCapRemovesThePartialOutput(): void
    {
        try {
            $this->store->write(
                $this->upload(str_repeat('x', 100)),
                'docs',
                new RandomPathGenerator(),
                'text/plain',
                maxBytes: 10,
            );
            Assert::true(false, 'the write should have been refused');
        } catch (UploadTooLargeException $e) {
            Assert::true(str_contains($e->getMessage(), '10 byte limit'));
            Assert::same(self::filesUnder($this->root), [], 'nothing at all was left behind');
        }
    }

    public function aBodyExactlyAtTheCapIsWritten(): void
    {
        $result = $this->store->write(
            $this->upload('1234567890'),
            'docs',
            new RandomPathGenerator(),
            'text/plain',
            maxBytes: 10,
        );

        Assert::same($result->size, 10);
    }

    public function aZeroCapMeansNoLimit(): void
    {
        $result = $this->store->write(
            $this->upload(str_repeat('x', 5_000)),
            'docs',
            new RandomPathGenerator(),
            'text/plain',
            maxBytes: 0,
        );

        Assert::same($result->size, 5_000);
    }

    public function readsReportSizeContentAndModificationTime(): void
    {
        $file = $this->write('hello there');

        Assert::true($this->store->exists($file));
        Assert::same($this->store->size($file), 11);
        Assert::same($this->store->stream($file)?->getContents(), 'hello there');
        Assert::instanceOf($this->store->lastModified($file), DateTimeImmutable::class);
    }

    public function readsOfAMissingObjectReturnNull(): void
    {
        $file = $this->file('docs/nowhere/original.txt');

        Assert::false($this->store->exists($file));
        Assert::null($this->store->size($file));
        Assert::null($this->store->stream($file));
        Assert::null($this->store->lastModified($file));
        Assert::null($this->store->streamRange($file, 0, 10));
    }

    /**
     * Deleting the directory rather than the object is what stops every
     * derivative of a deleted file leaking, indistinguishably from live ones.
     */
    public function deleteRemovesTheWholeDirectoryIncludingSiblings(): void
    {
        $file = $this->write('original bytes');
        $sibling = $this->root . '/' . $file->directory() . '/thumb.webp';
        file_put_contents($sibling, 'a thumbnail');

        $this->store->delete($file);

        Assert::false(file_exists($sibling));
        Assert::false(is_dir($this->root . '/' . $file->directory()));
    }

    /**
     * A retried removal has to converge, not fail on the second attempt.
     */
    public function deletingSomethingAlreadyGoneSucceeds(): void
    {
        $file = $this->write('x');

        $this->store->delete($file);
        $this->store->delete($file);

        Assert::false($this->store->exists($file));
    }

    public function streamRangeServesExactlyTheRequestedWindow(): void
    {
        $file = $this->write('0123456789');

        Assert::same($this->store->streamRange($file, 2, 4)?->getContents(), '2345');
        Assert::same($this->store->streamRange($file, 0, 1)?->getContents(), '0');
    }

    public function aRangeIsClampedToTheEndOfTheObject(): void
    {
        $file = $this->write('0123456789');

        Assert::same($this->store->streamRange($file, 8, 100)?->getContents(), '89');
        Assert::same($this->store->streamRange($file, 8, 100)?->getSize(), 2);
    }

    public function aRangeStartingPastTheEndHasNoAnswer(): void
    {
        $file = $this->write('0123456789');

        Assert::null($this->store->streamRange($file, 10, 1));
        Assert::null($this->store->streamRange($file, 999, 1));
    }

    public function derivativesLiveBesideTheOriginal(): void
    {
        $file = $this->write('original');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');

        Assert::false($this->store->hasDerivative($file, $thumb));
        Assert::null($this->store->derivativeStream($file, $thumb));

        $object = $this->store->writeDerivative($file, $thumb, $this->factory->createStream('rendition'));

        Assert::same($object->relativePath, $file->directory() . '/thumb.webp');
        Assert::same($object->size, 9);
        Assert::same($object->mediaType, 'image/webp');
        Assert::true($this->store->hasDerivative($file, $thumb));
        Assert::same($this->store->derivativeStream($file, $thumb)?->getContents(), 'rendition');
    }

    public function aDerivativeAlsoRespectsAByteCap(): void
    {
        $file = $this->write('original');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');

        try {
            $this->store->writeDerivative($file, $thumb, $this->factory->createStream(str_repeat('x', 50)), 10);
            Assert::true(false, 'the derivative should have been refused');
        } catch (UploadTooLargeException) {
            Assert::false($this->store->hasDerivative($file, $thumb));
            Assert::same(self::filesUnder($this->root, '.part'), []);
        }
    }

    public function theInventoryListsEveryObjectAndSkipsStagingFiles(): void
    {
        $first = $this->write('a');
        $second = $this->write('b');
        file_put_contents($this->root . '/' . $first->directory() . '/leftover.abc123.part', 'junk');

        $paths = array_map(
            static fn (StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(), false),
        );

        sort($paths);
        $expected = [$first->relativePath, $second->relativePath];
        sort($expected);

        Assert::same($paths, $expected);
    }

    public function theInventoryPagesAndResumesFromACursor(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->write("body {$i}");
        }

        $first = iterator_to_array($this->store->objects(limit: 2), false);
        Assert::same(count($first), 2);

        $second = iterator_to_array(
            $this->store->objects(afterPath: $first[1]->relativePath, limit: 2),
            false,
        );

        Assert::same(count($second), 2);
        Assert::true($second[0]->relativePath !== $first[0]->relativePath);
        Assert::true($second[0]->relativePath !== $first[1]->relativePath);
    }

    public function deleteObjectRemovesOneObjectAndIsIdempotent(): void
    {
        $file = $this->write('x');
        $object = new StoredObjectId($file->relativePath);

        $this->store->deleteObject($object);
        $this->store->deleteObject($object);

        Assert::false($this->store->exists($file));
    }

    /**
     * A symlink planted inside the tree must not become a way to read outside
     * it: the relative path was already validated, but the resolved one is a
     * separate question.
     */
    public function aSymlinkPointingOutsideTheRootIsNotReadable(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'secret');
        mkdir($this->root . '/docs/escape', 0o775, true);
        symlink($outside, $this->root . '/docs/escape/original.txt');

        try {
            $file = $this->file('docs/escape/original.txt');

            Assert::false($this->store->exists($file));
            Assert::null($this->store->stream($file));
            Assert::null($this->store->size($file));
        } finally {
            unlink($outside);
        }
    }

    /**
     * Following one during an inventory walk would let garbage collection
     * delete things that are not this store's objects.
     */
    public function theInventoryDoesNotFollowSymlinks(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-dir-' . bin2hex(random_bytes(6));
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'secret');
        mkdir($this->root . '/docs', 0o775, true);
        symlink($outside, $this->root . '/docs/linked');

        try {
            Assert::same(iterator_to_array($this->store->objects(), false), []);
        } finally {
            FileHelper::removeDirectory($outside);
        }
    }

    public function anInvalidStoreNameIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid store name');

        new FileSystemStore('bad name', $this->root, $this->factory);
    }

    /**
     * The message names the fix, because the alternative first-run experience
     * is a bare permission error from deep inside a helper.
     */
    public function anUncreatableRootFailsWithAnActionableMessage(): void
    {
        Expect::exception(StoreException::class)->withMessageContaining('writable by the web server user');

        new FileSystemStore('upload', "/proc/nonexistent/deeper", $this->factory);
    }

    private function upload(string $body): Upload
    {
        return Upload::fromStream($this->factory->createStream($body), 'thing.txt', $this->factory);
    }

    private function write(string $body): File
    {
        $result = $this->store->write($this->upload($body), 'docs', new RandomPathGenerator(), 'text/plain');

        return $this->file($result->relativePath);
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f-' . bin2hex(random_bytes(4)),
            storeName: 'upload',
            groupName: 'docs',
            relativePath: $relativePath,
            originalName: 'thing.txt',
            size: 0,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }

    /**
     * @return list<string>
     */
    private static function filesUnder(string $root, string $suffix = ''): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && ($suffix === '' || str_ends_with($entry->getFilename(), $suffix))) {
                $found[] = $entry->getPathname();
            }
        }
        sort($found);

        return $found;
    }
}
