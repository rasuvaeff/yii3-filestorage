<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Tests\Support\ForwardOnlyStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

use function Rasuvaeff\Understudy\when;

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

        Assert::same($this->filesUnder($this->root, '.part'), []);
    }

    /**
     * A generated path that already exists is an error, never sharing: the
     * caller asked for a new object, and returning somebody else's would tie
     * two logical files to bytes only one of them owns.
     */
    public function aPathCollisionIsAnErrorRatherThanSilentSharing(): void
    {
        $fixed = $this->fixedPathGenerator('docs/fixed/original.txt');

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
            Assert::true(actual: false, message: 'the write should have been refused');
        } catch (UploadTooLargeException $e) {
            Assert::true(str_contains($e->getMessage(), '10 byte limit'));
            Assert::same($this->filesUnder($this->root), [], 'nothing at all was left behind');
        }
    }

    /**
     * A temporary empty read is not EOF. Publishing the prefix in that state
     * would turn a stalled source into a successful but truncated object.
     */
    public function anEmptyReadBeforeEofRemovesThePartialOutput(): void
    {
        $stream = new class ('remaining bytes') extends ForwardOnlyStream {
            private bool $stalled = true;

            #[Override]
            public function isSeekable(): bool
            {
                return true;
            }

            #[Override]
            public function rewind(): void {}

            #[Override]
            public function read(int $length): string
            {
                if ($this->stalled) {
                    $this->stalled = false;

                    return '';
                }

                return parent::read($length);
            }
        };

        $upload = Upload::fromStream($stream, 'thing.txt', $this->factory);

        try {
            $this->store->write(
                $upload,
                'docs',
                new RandomPathGenerator(),
                'text/plain',
            );
            Assert::true(actual: false, message: 'the stalled write should have been refused');
        } catch (StoreException $e) {
            Assert::true(str_contains($e->getMessage(), 'before EOF'));
            Assert::same($this->filesUnder($this->root), [], 'the partial output was removed');
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
            Assert::true(actual: false, message: 'the derivative should have been refused');
        } catch (UploadTooLargeException) {
            Assert::false($this->store->hasDerivative($file, $thumb));
            Assert::same($this->filesUnder($this->root, '.part'), []);
        }
    }

    public function theInventoryListsEveryObjectAndSkipsStagingFiles(): void
    {
        $first = $this->write('a');
        $second = $this->write('b');
        file_put_contents($this->root . '/' . $first->directory() . '/leftover.abc123.part', 'junk');

        $paths = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(), preserve_keys: false),
        );

        sort($paths);
        $expected = [$first->relativePath, $second->relativePath];
        sort($expected);

        Assert::same($paths, $expected);
    }

    /**
     * The walk has to be ordered the way the cursor compares, and those are not
     * the same thing by default. `scandir()` sorts by bare name, so a directory
     * `a` precedes a file `a.txt`; `strcmp()` over full relative paths puts
     * `a.txt` before `a/x.txt`, because `.` is 0x2E and `/` is 0x2F. With the
     * walk in scandir order, paging past `a/x.txt` skips `a.txt` on every later
     * page and the object drops out of the inventory permanently — invisible to
     * verify, uncollectable by gc.
     *
     * Written directly rather than through write(): the generated layout never
     * puts a file beside a directory sharing its prefix, so only a hand-built
     * tree can catch an ordering the contract nonetheless promises.
     */
    public function theInventoryDoesNotSkipAFileBesideADirectoryThatSharesItsPrefix(): void
    {
        mkdir($this->root . '/a', 0o775, recursive: true);
        file_put_contents($this->root . '/a/x.txt', 'inside the directory');
        file_put_contents($this->root . '/a.txt', 'beside it');

        $all = [];
        $after = null;
        // One at a time, which is what a paging caller does and what a
        // single-pass listing would never exercise.
        while (true) {
            $page = iterator_to_array($this->store->objects(afterPath: $after, limit: 1), preserve_keys: false);
            if ($page === []) {
                break;
            }

            $all[] = $page[0]->relativePath;
            $after = $page[0]->relativePath;
        }

        sort($all);

        Assert::same($all, ['a.txt', 'a/x.txt']);
    }

    /**
     * And the order itself, which is the property the cursor depends on: each
     * path must be strictly greater than the one before it under strcmp.
     */
    public function theInventoryIsOrderedTheWayTheCursorCompares(): void
    {
        mkdir($this->root . '/a', 0o775, recursive: true);
        file_put_contents($this->root . '/a/x.txt', 'x');
        file_put_contents($this->root . '/a.txt', 'y');
        file_put_contents($this->root . '/a-b.txt', 'z');

        $paths = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(), preserve_keys: false),
        );

        for ($i = 1, $n = count($paths); $i < $n; ++$i) {
            Assert::true(
                strcmp($paths[$i], $paths[$i - 1]) > 0,
                "\"{$paths[$i]}\" must sort after \"{$paths[$i - 1]}\"",
            );
        }
    }

    public function theInventoryPagesAndResumesFromACursor(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->write("body {$i}");
        }

        $first = iterator_to_array($this->store->objects(limit: 2), preserve_keys: false);
        Assert::same(count($first), 2);

        $second = iterator_to_array(
            $this->store->objects(afterPath: $first[1]->relativePath, limit: 2),
            preserve_keys: false,
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
     * The containment check is on the *write* path too, not only on reads. A
     * group directory replaced by a symlink would otherwise let an upload be
     * written outside the store root — and unlike a read, that one leaves data
     * behind.
     */
    public function writingThroughASymlinkedDirectoryIsRefused(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-write-' . bin2hex(random_bytes(6));
        mkdir($outside);
        if (!$this->createSymlink($outside, $this->root . '/docs')) {
            FileHelper::removeDirectory($outside);

            return;
        }

        $fixed = $this->fixedPathGenerator('docs/key/original.txt');

        try {
            $this->store->write($this->upload('escaping'), 'docs', $fixed, 'text/plain');
            Assert::true(actual: false, message: 'the write should have been refused');
        } catch (StoreException $e) {
            Assert::true(str_contains($e->getMessage(), 'escapes the root of store "upload"'));
            Assert::same(self::filesUnder($outside), [], 'and nothing was written outside');
        } finally {
            FileHelper::removeDirectory($outside);
        }
    }

    /**
     * The other shape of the same attack: the *leaf* key directory is the
     * symlink, so `ensureDirectory()` finds it already there and creates
     * nothing. The guard has to resolve what is on disk, not just what it made.
     */
    public function writingIntoASymlinkedKeyDirectoryIsRefused(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-leaf-' . bin2hex(random_bytes(6));
        mkdir($outside);
        mkdir($this->root . '/docs/xx/yy', 0o775, recursive: true);
        if (!$this->createSymlink($outside, $this->root . '/docs/xx/yy/key')) {
            FileHelper::removeDirectory($outside);

            return;
        }

        $fixed = $this->fixedPathGenerator('docs/xx/yy/key/original.txt');

        try {
            $this->store->write($this->upload('escaping'), 'docs', $fixed, 'text/plain');
            Assert::true(actual: false, message: 'the write should have been refused');
        } catch (StoreException $e) {
            Assert::true(str_contains($e->getMessage(), 'escapes the root of store "upload"'));
            Assert::same(self::filesUnder($outside), []);
        } finally {
            FileHelper::removeDirectory($outside);
        }
    }

    /**
     * Two writers racing for one target: the second must be refused rather than
     * quietly overwriting the first after both staged under different names.
     */
    /**
     * Derivatives stage the same way originals do: a stale `.part` beside the
     * derivative target is a concurrent writer, and the second write must
     * refuse rather than race it.
     */
    public function aLeftoverStagingFileRefusesASecondDerivativeWrite(): void
    {
        $file = $this->write('original');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');
        $object = $this->store->writeDerivative($file, $thumb, $this->factory->createStream('one'));

        file_put_contents($this->root . '/' . $object->relativePath . '.part', 'a write in flight');

        Expect::exception(StoreException::class)->withMessageContaining('staging file');
        $this->store->writeDerivative($file, $thumb, $this->factory->createStream('two'));
    }

    public function aLeftoverStagingFileRefusesASecondWriteToTheSameTarget(): void
    {
        $fixed = $this->fixedPathGenerator('docs/key/original.txt');

        mkdir($this->root . '/docs/key', 0o775, recursive: true);
        file_put_contents($this->root . '/docs/key/original.txt.part', 'a write in flight');

        Expect::exception(StoreException::class)->withMessageContaining('staging file');

        $this->store->write($this->upload('second writer'), 'docs', $fixed, 'text/plain');
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
        mkdir($this->root . '/docs/escape', 0o775, recursive: true);
        if (!$this->createSymlink($outside, $this->root . '/docs/escape/original.txt')) {
            unlink($outside);

            return;
        }

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
        mkdir($this->root . '/docs', 0o775, recursive: true);
        if (!$this->createSymlink($outside, $this->root . '/docs/linked')) {
            FileHelper::removeDirectory($outside);

            return;
        }

        try {
            Assert::same(iterator_to_array($this->store->objects(), preserve_keys: false), []);
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
     * A path component the generator produced is occupied by a file, so the
     * key directory cannot be created. The failure names the directory, is a
     * store error, and — being reported as one — carries no other code.
     */
    public function aKeyDirectoryBlockedByAFileIsAStoreErrorWithNoCode(): void
    {
        mkdir($this->root . '/docs/xx/yy', 0o775, recursive: true);
        file_put_contents($this->root . '/docs/xx/yy/key', 'not a directory');
        $fixed = $this->fixedPathGenerator('docs/xx/yy/key/original.txt');

        try {
            $this->store->write($this->upload('x'), 'docs', $fixed, 'text/plain');
            Assert::true(actual: false, message: 'the write should have been refused');
        } catch (StoreException $e) {
            // The directory in the message carries the platform's own
            // separators (the root is a realpath answer); the claim is about
            // which directory, not which slashes.
            Assert::same(str_replace('\\', '/', $e->getMessage()), 'Could not create "' . str_replace('\\', '/', $this->root) . '/docs/xx/yy/key" in store "upload"');
            Assert::same($e->getCode(), 0);
        }
    }

    /**
     * The derivative write goes through the same ensureDirectory step, so the
     * same blocked path fails the same way — with the directory named and no
     * code on the exception.
     */
    public function aDerivativeDirectoryBlockedByAFileIsAStoreErrorWithNoCode(): void
    {
        mkdir($this->root . '/docs/xx/yy', 0o775, recursive: true);
        file_put_contents($this->root . '/docs/xx/yy/key', 'not a directory');
        $file = $this->file('docs/xx/yy/key/original.txt');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');

        try {
            $this->store->writeDerivative($file, $thumb, $this->factory->createStream('rendition'));
            Assert::true(actual: false, message: 'the derivative should have been refused');
        } catch (StoreException $e) {
            Assert::same(str_replace('\\', '/', $e->getMessage()), 'Could not create "' . str_replace('\\', '/', $this->root) . '/docs/xx/yy/key" in store "upload"');
            Assert::same($e->getCode(), 0);
        }
    }

    /**
     * The derivative target occupied by a directory: rename cannot replace it,
     * so the staging file is removed and the failure names the derivative.
     * `write()` refuses an existing target up front; the derivative path has
     * no such guard, which is exactly why this branch exists.
     */
    public function aDerivativeTargetOccupiedByADirectoryIsAPublishFailure(): void
    {
        $file = $this->write('original');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');
        mkdir($this->root . '/' . $file->directory() . '/thumb.webp', 0o775, recursive: true);

        try {
            $this->store->writeDerivative($file, $thumb, $this->factory->createStream('rendition'));
            Assert::true(actual: false, message: 'the derivative should have been refused');
        } catch (StoreException $e) {
            Assert::same(
                $e->getMessage(),
                'Could not publish derivative "' . $file->directory() . '/thumb.webp" in store "upload"',
            );
            Assert::same($this->filesUnder($this->root, '.part'), [], 'the staging file was removed');
        }
    }

    /**
     * Deleting a *file* path rather than a key directory: the cleanup half of
     * a broken write can leave a plain file where a directory was expected,
     * and delete() removes it through the unlink branch, not removeDirectory.
     */
    public function deleteRemovesALeafObjectPathToo(): void
    {
        mkdir($this->root . '/docs/xx/yy', 0o775, recursive: true);
        file_put_contents($this->root . '/docs/xx/yy/key', 'a plain file, not a directory');
        $file = $this->file('docs/xx/yy/key/original.txt');

        $this->store->delete($file);

        Assert::false(file_exists($this->root . '/docs/xx/yy/key'));
        Assert::false($this->store->exists($file));
    }

    /**
     * The containment check is on the derivative write path too: a key
     * directory replaced by a symlink would let a rendition be written outside
     * the store root.
     */
    public function writingADerivativeThroughASymlinkedKeyDirectoryIsRefused(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-deriv-' . bin2hex(random_bytes(6));
        mkdir($outside);
        mkdir($this->root . '/docs/xx/yy', 0o775, recursive: true);
        if (!$this->createSymlink($outside, $this->root . '/docs/xx/yy/key')) {
            FileHelper::removeDirectory($outside);

            return;
        }

        $file = $this->file('docs/xx/yy/key/original.txt');
        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');

        try {
            $this->store->writeDerivative($file, $thumb, $this->factory->createStream('rendition'));
            Assert::true(actual: false, message: 'the derivative should have been refused');
        } catch (StoreException $e) {
            Assert::string($e->getMessage())->contains('escapes the root of store "upload"');
            Assert::same(self::filesUnder($outside), [], 'and nothing was written outside');
        } finally {
            FileHelper::removeDirectory($outside);
        }
    }

    /**
     * Each derivative stages under its own sibling name: a second rendition
     * must not collide with the first one's staging file. Staging at a fixed
     * name outside the target directory would make the second write fail.
     */
    public function twoDerivativesCanStageAtTheSameTime(): void
    {
        $file = $this->write('original');

        $thumb = $this->store->writeDerivative(
            $file,
            new DerivativeDescriptor('thumb', 'webp', 'image/webp'),
            $this->factory->createStream('rendition'),
        );
        $preview = $this->store->writeDerivative(
            $file,
            new DerivativeDescriptor('preview', 'webp', 'image/webp'),
            $this->factory->createStream('bigger rendition'),
        );

        Assert::same($thumb->relativePath, $file->directory() . '/thumb.webp');
        Assert::same($preview->relativePath, $file->directory() . '/preview.webp');
        Assert::same(
            $this->store->derivativeStream($file, new DerivativeDescriptor('preview', 'webp', 'image/webp'))
                ?->getContents(),
            'bigger rendition',
        );
    }

    /**
     * The reported size is the whole body, not the last chunk: a body several
     * read-chunks long accumulates across the copy loop.
     */
    public function aMultiChunkWriteReportsTheWholeSize(): void
    {
        $body = str_repeat('a', 262_144) . 'tail';

        $result = $this->store->write($this->upload($body), 'docs', new RandomPathGenerator(), 'text/plain');

        Assert::same($result->size, 262_148);
        Assert::same(file_get_contents($this->root . '/' . $result->relativePath), $body);
    }

    /**
     * A symlink that sorts *before* real entries must not end the inventory
     * walk: skipping it is per-entry, and breaking there would hide every
     * object behind it from verify and gc.
     */
    public function theInventorySkipsASymlinkWithoutEndingTheWalk(): void
    {
        $outside = sys_get_temp_dir() . '/fs-outside-walk-' . bin2hex(random_bytes(6));
        file_put_contents($outside, 'not an object');
        mkdir($this->root . '/b', 0o775, recursive: true);
        file_put_contents($this->root . '/b/x.txt', 'a real object');
        if (!$this->createSymlink($outside, $this->root . '/a-link')) {
            unlink($outside);

            return;
        }

        try {
            $paths = array_map(
                static fn(StoredObjectId $id): string => $id->relativePath,
                iterator_to_array($this->store->objects(), preserve_keys: false),
            );

            Assert::same($paths, ['b/x.txt'], 'the walk continued past the link');
        } finally {
            unlink($outside);
        }
    }

    /**
     * Containment is prefix-plus-separator, not prefix: a sibling directory
     * whose name merely *extends* the root's name is a different tree, and a
     * symlink into it must stay unreadable through the store.
     */
    public function aSiblingDirectoryWhoseNameExtendsTheRootIsOutside(): void
    {
        $sibling = $this->root . 'x';
        mkdir($sibling, 0o775, recursive: true);
        file_put_contents($sibling . '/secret.txt', 'not yours');
        mkdir($this->root . '/docs', 0o775, recursive: true);
        $linked = false;
        if (@symlink($sibling . '/secret.txt', $this->root . '/docs/escape')) {
            $linked = true;
        }

        try {
            if (!$linked) {
                Assert::same(\PHP_OS_FAMILY, 'Windows', 'symlink() unexpectedly failed');

                return;
            }

            $file = $this->file('docs/escape');

            Assert::false($this->store->exists($file));
            Assert::null($this->store->stream($file));
        } finally {
            FileHelper::removeDirectory($sibling);
        }
    }

    /**
     * A negative range length is a caller bug: the window clamps to empty
     * rather than growing a byte or throwing.
     */
    public function aNegativeRangeLengthYieldsAnEmptyWindow(): void
    {
        $file = $this->write('0123456789');

        $stream = $this->store->streamRange($file, 0, -1);

        Assert::instanceOf($stream, \Psr\Http\Message\StreamInterface::class);
        Assert::same($stream?->getContents(), '');
    }

    /**
     * The message names the fix, because the alternative first-run experience
     * is a bare permission error from deep inside a helper.
     */
    public function anUncreatableRootFailsWithAnActionableMessage(): void
    {
        $blockingPath = $this->root . '/not-a-directory';
        file_put_contents($blockingPath, 'x');

        try {
            new FileSystemStore('upload', $blockingPath . '/deeper', $this->factory);
            Assert::true(actual: false, message: 'the root should have been refused');
        } catch (StoreException $e) {
            Assert::string($e->getMessage())->contains(
                'does not exist and could not be created. Create it and make it writable',
            );
            Assert::same($e->getCode(), 0);
        }
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

    private function createSymlink(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        Assert::same(
            \PHP_OS_FAMILY,
            'Windows',
            'symlink() unexpectedly failed on a platform that normally permits it',
        );

        return false;
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
    private function filesUnder(string $root, string $suffix = ''): array
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
