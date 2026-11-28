<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

/**
 * The quarantine half of the local store, and the flat layout on a real disk.
 */
#[Test]
#[Covers(FileSystemStore::class)]
final class FileSystemStoreQuarantineTest
{
    private string $base;
    private string $root;
    private string $quarantine;
    private Psr17Factory $factory;
    private FileSystemStore $store;
    private DateTimeImmutable $now;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/fs-quarantine-' . bin2hex(random_bytes(8));
        $this->root = $this->base . '/root';
        $this->quarantine = $this->base . '/quarantine';
        $this->factory = new Psr17Factory();
        $this->store = new FileSystemStore('upload', $this->root, $this->factory, quarantinePath: $this->quarantine);
        $this->now = new DateTimeImmutable('@1764237600');
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->base)) {
            FileHelper::removeDirectory($this->base);
        }
    }

    public function theQuarantineIsCreatedAndUsable(): void
    {
        Assert::true(is_dir($this->quarantine));
        Assert::null($this->store->quarantineProblem());
    }

    public function quarantiningAKeyDirectoryMovesEveryObjectAndHidesThem(): void
    {
        $this->place('docs/ab/key/original.jpg', 'original');
        $this->place('docs/ab/key/thumb.webp', 'thumb');
        $this->place('docs/ab/other/original.jpg', 'other');

        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/key')), $this->now);

        Assert::instanceOf($entry, QuarantinedObject::class);
        Assert::same($this->paths($entry), ['docs/ab/key/original.jpg', 'docs/ab/key/thumb.webp']);
        Assert::same($entry->quarantinedAt->getTimestamp(), $this->now->getTimestamp());
        Assert::false(is_dir($this->root . '/docs/ab/key'), 'the emptied key directory is gone');
        Assert::true(is_dir($this->root . '/docs/ab'), 'its parent stays');
        Assert::same(
            file_get_contents($this->quarantine . '/' . $entry->token . '/docs/ab/key/thumb.webp'),
            'thumb',
        );
        Assert::same($this->inventory(), ['docs/ab/other/original.jpg']);
    }

    /**
     * Review 1.0 pass 2 (maintenance), N11: the orphan sweep quarantines the
     * file itself, and the emptied key directory stayed — one empty
     * `<group>/ab/cd/<key>/` per swept orphan, for good.
     */
    public function quarantiningTheOnlyFileOfAKeyDirectoryRemovesTheDirectory(): void
    {
        $this->place('docs/ab/cd/key/original.jpg', 'orphan');
        $this->place('docs/ab/cd/other/original.jpg', 'neighbour');

        $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/cd/key/original.jpg')), $this->now);

        Assert::false(is_dir($this->root . '/docs/ab/cd/key'));
        Assert::true(is_file($this->root . '/docs/ab/cd/other/original.jpg'));
    }

    /**
     * A fan-out directory is never removed for a file: a writer creates it and
     * then opens its file inside, and removing it between the two fails the
     * upload. Only a directory one file owns goes.
     */
    public function aFanOutDirectoryIsLeftEvenWhenEmptied(): void
    {
        $flat = new FileSystemStore('upload', $this->root, $this->factory, quarantinePath: $this->quarantine, layout: new FlatSiblingLayout());
        $this->place('blobs/ab/cd/abcd.jpg', 'orphan');
        $this->place('docs/ab/loose.jpg', 'orphan');

        $flat->quarantine(ObjectSet::tree(new StoredObjectId('blobs/ab/cd/abcd.jpg')), $this->now);
        $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/loose.jpg')), $this->now);

        Assert::true(is_dir($this->root . '/blobs/ab/cd'));
        Assert::true(is_dir($this->root . '/docs/ab'));
    }

    public function aKeyDirectoryWithSomethingLeftInItStays(): void
    {
        $this->place('docs/ab/cd/key/original.jpg', 'orphan');
        $this->place('docs/ab/cd/key/original.jpg.part', 'a writer');

        $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/cd/key/original.jpg')), $this->now);

        Assert::true(is_file($this->root . '/docs/ab/cd/key/original.jpg.part'));
    }

    /**
     * Review 1.0 pass 2 (maintenance), N4: a crash mid-write leaves a `.part`
     * that neither the inventory nor the quarantine lists, so nothing ever
     * removed it — and under a public root it was served. Removed once it
     * has not been written to for the minimum age.
     */
    public function abandonedPartialsOlderThanTheCutoffAreRemoved(): void
    {
        $this->place('docs/ab/cd/key/original.jpg.part', 'stalled');
        $this->place('blobs/ab/cd/abcd.jpg.0123456789abcdef.part', 'stalled');
        $this->place('docs/ab/cd/fresh/original.jpg.part', 'writing');
        $this->place('docs/ab/cd/key/original.jpg', 'live');
        $this->place('.hidden/x.part', 'not ours');
        touch($this->root . '/docs/ab/cd/key/original.jpg.part', $this->now->getTimestamp() - 7200);
        touch($this->root . '/blobs/ab/cd/abcd.jpg.0123456789abcdef.part', $this->now->getTimestamp() - 7200);
        touch($this->root . '/.hidden/x.part', $this->now->getTimestamp() - 7200);
        touch($this->root . '/docs/ab/cd/fresh/original.jpg.part', $this->now->getTimestamp());
        $cutoff = $this->now->modify('-1 hour');

        Assert::same($this->store->sweepAbandonedPartials($cutoff, apply: false, limit: 10), [
            'blobs/ab/cd/abcd.jpg.0123456789abcdef.part',
            'docs/ab/cd/key/original.jpg.part',
        ]);
        Assert::true(is_file($this->root . '/docs/ab/cd/key/original.jpg.part'), 'a dry run removes nothing');

        Assert::same(\count($this->store->sweepAbandonedPartials($cutoff, apply: true, limit: 1)), 1);
        Assert::same($this->store->sweepAbandonedPartials($cutoff, apply: true, limit: 10), ['docs/ab/cd/key/original.jpg.part']);

        Assert::false(is_file($this->root . '/docs/ab/cd/key/original.jpg.part'));
        Assert::false(is_file($this->root . '/blobs/ab/cd/abcd.jpg.0123456789abcdef.part'));
        Assert::true(is_file($this->root . '/docs/ab/cd/fresh/original.jpg.part'));
        Assert::true(is_file($this->root . '/docs/ab/cd/key/original.jpg'));
        Assert::true(is_file($this->root . '/.hidden/x.part'));
    }

    public function abandonedPartialsOutsideTheOwnedPrefixesAreNotTouched(): void
    {
        $owned = new FileSystemStore('upload', $this->root, $this->factory, quarantinePath: $this->quarantine, ownedPrefixes: ['blobs']);
        $this->place('blobs/ab/x.jpg.part', 'stalled');
        $this->place('banners/top.png.part', 'the application\'s');
        touch($this->root . '/blobs/ab/x.jpg.part', $this->now->getTimestamp() - 7200);
        touch($this->root . '/banners/top.png.part', $this->now->getTimestamp() - 7200);

        Assert::same($owned->sweepAbandonedPartials($this->now, apply: true, limit: 10), ['blobs/ab/x.jpg.part']);
        Assert::true(is_file($this->root . '/banners/top.png.part'));
    }

    /**
     * "Oldest path first" is string order over the whole relative path, not
     * the order a directory walk meets them: `a-b/` sorts before `a/`, though
     * the directory `a` is listed first.
     */
    public function abandonedPartialsComeInPathOrderAndTheLimitTakesTheFirst(): void
    {
        $this->place('a/x.jpg.part', 'stalled');
        $this->place('a-b/y.jpg.part', 'stalled');
        touch($this->root . '/a/x.jpg.part', $this->now->getTimestamp() - 7200);
        touch($this->root . '/a-b/y.jpg.part', $this->now->getTimestamp() - 7200);

        Assert::same($this->store->sweepAbandonedPartials($this->now, apply: false, limit: 10), ['a-b/y.jpg.part', 'a/x.jpg.part']);
        Assert::same($this->store->sweepAbandonedPartials($this->now, apply: false, limit: 1), ['a-b/y.jpg.part']);
    }

    public function aPartialModifiedExactlyAtTheCutoffIsKept(): void
    {
        $this->place('docs/k/original.jpg.part', 'writing');
        touch($this->root . '/docs/k/original.jpg.part', $this->now->getTimestamp());

        Assert::same($this->store->sweepAbandonedPartials($this->now, apply: true, limit: 10), []);
        Assert::same($this->store->sweepAbandonedPartials($this->now->modify('+1 second'), apply: true, limit: 10), ['docs/k/original.jpg.part']);
        Assert::false(is_file($this->root . '/docs/k/original.jpg.part'));
    }

    public function aStagingFileKeepsItsDirectory(): void
    {
        $this->place('docs/ab/key/original.jpg', 'original');
        $this->place('docs/ab/key/original.jpg.part', 'a writer');

        $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/key')), $this->now);

        Assert::true(is_file($this->root . '/docs/ab/key/original.jpg.part'));
    }

    public function quarantiningNothingReturnsNull(): void
    {
        Assert::null($this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/none')), $this->now));
        Assert::same(array_values(array_diff(scandir($this->quarantine) ?: [], ['.', '..'])), []);
    }

    public function quarantiningASingleObjectLeavesItsNeighbours(): void
    {
        $this->place('docs/a.bin', 'a');
        $this->place('docs/b.bin', 'b');

        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/a.bin')), $this->now);

        Assert::same($this->paths($entry), ['docs/a.bin']);
        Assert::same($this->inventory(), ['docs/b.bin']);
    }

    public function restoringPutsTheBytesBackAndEndsTheEntry(): void
    {
        $this->place('docs/ab/key/original.jpg', 'original');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/key')), $this->now);
        Assert::instanceOf($entry, QuarantinedObject::class);

        $this->store->restore($entry);

        Assert::same(file_get_contents($this->root . '/docs/ab/key/original.jpg'), 'original');
        Assert::false(is_dir($this->quarantine . '/' . $entry->token));
        Assert::same(iterator_to_array($this->store->quarantined(), preserve_keys: false), []);
    }

    /**
     * Put-if-absent: a writer that re-published the key meanwhile keeps its
     * copy, and the quarantined one is dropped rather than written over it.
     */
    public function restoringKeepsAnObjectRepublishedMeanwhile(): void
    {
        $this->place('cas/ab/sha/original', 'old copy');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('cas/ab/sha/original')), $this->now);
        Assert::instanceOf($entry, QuarantinedObject::class);
        $this->place('cas/ab/sha/original', 'republished');

        $this->store->restore($entry);

        Assert::same(file_get_contents($this->root . '/cas/ab/sha/original'), 'republished');
        Assert::false(is_dir($this->quarantine . '/' . $entry->token));
    }

    public function restoringTwiceIsHarmless(): void
    {
        $this->place('docs/a.bin', 'a');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/a.bin')), $this->now);
        Assert::instanceOf($entry, QuarantinedObject::class);

        $this->store->restore($entry);
        $this->store->restore($entry);

        Assert::same(file_get_contents($this->root . '/docs/a.bin'), 'a');
    }

    public function purgingDeletesTheEntryForGood(): void
    {
        $this->place('docs/a.bin', 'a');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/a.bin')), $this->now);
        Assert::instanceOf($entry, QuarantinedObject::class);

        $this->store->purge($entry);
        $this->store->purge($entry);

        Assert::false(is_dir($this->quarantine . '/' . $entry->token));
        Assert::false(is_file($this->root . '/docs/a.bin'));
    }

    public function theQuarantineIsListedOldestFirstAndPages(): void
    {
        $tokens = [];
        foreach ([300, 100, 200] as $i => $seconds) {
            $this->place("docs/{$i}.bin", 'x');
            $entry = $this->store->quarantine(
                ObjectSet::tree(new StoredObjectId("docs/{$i}.bin")),
                new DateTimeImmutable('@' . $seconds),
            );
            Assert::instanceOf($entry, QuarantinedObject::class);
            $tokens[$seconds] = $entry->token;
        }
        mkdir($this->quarantine . '/not-a-token');
        mkdir($this->quarantine . '/0000000050-0123456789abcdef');

        $listed = array_map(
            static fn(QuarantinedObject $e): string => $e->token,
            iterator_to_array($this->store->quarantined(), preserve_keys: false),
        );
        Assert::same($listed, [$tokens[100], $tokens[200], $tokens[300]]);
        Assert::false(is_dir($this->quarantine . '/0000000050-0123456789abcdef'), 'an empty entry is cleaned up');

        $page = iterator_to_array($this->store->quarantined($tokens[100], 1), preserve_keys: false);
        Assert::same(array_map(static fn(QuarantinedObject $e): string => $e->token, $page), [$tokens[200]]);
        Assert::same($this->paths($page[0]), ['docs/2.bin']);
    }

    public function theInventoryCarriesTimestampAndSize(): void
    {
        $this->place('docs/a.bin', 'four');
        touch($this->root . '/docs/a.bin', 1_000_000_000);

        $objects = iterator_to_array($this->store->objects(), preserve_keys: false);

        Assert::same(\count($objects), 1);
        Assert::instanceOf($objects[0], StoredObject::class);
        Assert::same($objects[0]->size, 4);
        Assert::same($objects[0]->lastModified?->getTimestamp(), 1_000_000_000);
    }

    public function withoutAQuarantineNothingCanBeQuarantined(): void
    {
        $store = new FileSystemStore('bare', $this->base . '/bare', $this->factory);

        Assert::string((string) $store->quarantineProblem())->contains(
            'has no quarantine directory, so filestorage:gc cannot delete from it',
        );

        Expect::exception(StoreException::class)->withMessageContaining('has no quarantine directory configured');
        $store->quarantine(ObjectSet::tree(new StoredObjectId('a')), $this->now);
    }

    public function withoutAQuarantineNothingIsListed(): void
    {
        $store = new FileSystemStore('bare', $this->base . '/bare', $this->factory);

        Expect::exception(StoreException::class)->withMessageContaining('has no quarantine directory configured');
        iterator_to_array($store->quarantined());
    }

    public function aQuarantineInsideTheRootIsRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be outside the root, and the root outside it');

        new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->root . '/.quarantine');
    }

    public function theRootAsQuarantineIsRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be outside the root');

        new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->root);
    }

    public function aQuarantineAroundTheRootIsRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be outside the root');

        new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->base);
    }

    public function aSiblingWhoseNameExtendsTheRootIsAcceptedAsQuarantine(): void
    {
        $store = new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->root . '-quarantine');

        Assert::null($store->quarantineProblem());
    }

    public function anUncreatableQuarantineIsAStoreError(): void
    {
        file_put_contents($this->base . '/blocker', 'x');

        Expect::exception(StoreException::class)->withMessageContaining('does not exist and could not be created');

        new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->base . '/blocker/q');
    }

    public function theFlatLayoutDeletesTheOriginalAndItsSiblingsOnly(): void
    {
        $store = new FileSystemStore('flat', $this->root, $this->factory, layout: new FlatSiblingLayout());
        $file = $this->file('blobs/ab/cd/abc.jpg');
        $this->place('blobs/ab/cd/abc.jpg', 'original');
        $derivative = $store->writeDerivative(
            $file,
            new DerivativeDescriptor('s-v1', 'webp', 'image/webp'),
            $this->factory->createStream('thumb'),
        );
        $this->place('blobs/ab/cd/abcd.jpg', 'another base');
        $this->place('blobs/ab/cd/abc.jpg.part', 'a writer');

        Assert::same($derivative->relativePath, 'blobs/ab/cd/abc-s-v1.webp');
        Assert::same($store->layout()::class, FlatSiblingLayout::class);

        $store->delete($file);

        Assert::same($this->inventory($store), ['blobs/ab/cd/abcd.jpg']);
        Assert::true(is_file($this->root . '/blobs/ab/cd/abc.jpg.part'));
    }

    public function theFlatLayoutQuarantinesSiblingsTogether(): void
    {
        $store = new FileSystemStore(
            'flat',
            $this->root,
            $this->factory,
            quarantinePath: $this->quarantine,
            layout: new FlatSiblingLayout(),
        );
        $this->place('abc.jpg', 'root original');
        $this->place('abc-m.webp', 'root derivative');
        $this->place('abd.jpg', 'neighbour');

        $entry = $store->quarantine($store->layout()->deletion(new StoredObjectId('abc.jpg')), $this->now);

        Assert::same($this->paths($entry), ['abc-m.webp', 'abc.jpg']);
        Assert::same($this->inventory($store), ['abd.jpg']);
    }

    public function deletingAFlatFileInAMissingDirectoryIsANoOp(): void
    {
        $store = new FileSystemStore('flat', $this->root, $this->factory, layout: new FlatSiblingLayout());

        $store->delete($this->file('nowhere/abc.jpg'));

        Assert::same($this->inventory($store), []);
    }

    public function nestedDirectoriesTheQuarantineEmptiedAreRemovedAndNothingElse(): void
    {
        $this->place('docs/ab/key/original.jpg', 'original');
        $this->place('docs/ab/key/sub/deeper/x.bin', 'nested');
        mkdir($this->root . '/docs/ab/empty-neighbour', 0o775, recursive: true);
        mkdir($this->root . '/elsewhere', 0o775, recursive: true);

        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/ab/key')), $this->now);

        Assert::same($this->paths($entry), ['docs/ab/key/original.jpg', 'docs/ab/key/sub/deeper/x.bin']);
        Assert::false(file_exists($this->root . '/docs/ab/key'));
        Assert::true(is_dir($this->root . '/docs/ab/empty-neighbour'));
        Assert::true(is_dir($this->root . '/elsewhere'));
    }

    public function restoreSkipsAnObjectMissingFromTheEntryAndRestoresTheRest(): void
    {
        $this->place('docs/k/a.bin', 'a');
        $this->place('docs/k/b.bin', 'b');
        $entry = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/k')), $this->now);
        Assert::instanceOf($entry, QuarantinedObject::class);
        unlink($this->quarantine . '/' . $entry->token . '/docs/k/a.bin');

        $this->store->restore($entry);

        Assert::false(is_file($this->root . '/docs/k/a.bin'));
        Assert::same(file_get_contents($this->root . '/docs/k/b.bin'), 'b');
    }

    public function purgeRemovesOnlyItsOwnEntry(): void
    {
        $this->place('docs/a.bin', 'a');
        $this->place('docs/b.bin', 'b');
        $first = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/a.bin')), $this->now);
        $second = $this->store->quarantine(ObjectSet::tree(new StoredObjectId('docs/b.bin')), $this->now);
        Assert::instanceOf($first, QuarantinedObject::class);
        Assert::instanceOf($second, QuarantinedObject::class);

        $this->store->purge($first);

        Assert::same(
            array_map(static fn(QuarantinedObject $e): string => $e->token, iterator_to_array($this->store->quarantined(), preserve_keys: false)),
            [$second->token],
        );
    }

    public function aSymlinkedSiblingIsNeitherDeletedNorQuarantined(): void
    {
        $store = new FileSystemStore(
            'flat',
            $this->root,
            $this->factory,
            quarantinePath: $this->quarantine,
            layout: new FlatSiblingLayout(),
        );
        $this->place('b/abc.jpg', 'original');
        $this->place('outside.txt', 'not a member');
        if (!@symlink($this->root . '/outside.txt', $this->root . '/b/abc-link.webp')) {
            Assert::same(\PHP_OS_FAMILY, 'Windows', 'symlink() unexpectedly failed');

            return;
        }

        $entry = $store->quarantine($store->layout()->deletion(new StoredObjectId('b/abc.jpg')), $this->now);

        Assert::same($this->paths($entry), ['b/abc.jpg']);
        Assert::true(is_link($this->root . '/b/abc-link.webp'));
    }

    public function aQuarantineWhoseNameIsAPrefixOfTheRootIsNotAroundIt(): void
    {
        $store = new FileSystemStore('x', $this->root, $this->factory, quarantinePath: $this->base . '/ro');

        Assert::null($store->quarantineProblem());
    }

    public function theMissingQuarantineMessageSaysWhatToConfigure(): void
    {
        $store = new FileSystemStore('bare', $this->base . '/bare', $this->factory);

        Assert::same(
            $store->quarantineProblem(),
            'Store "bare" has no quarantine directory, so filestorage:gc cannot delete from it. '
            . 'Pass quarantinePath: a directory outside the root and outside any web root, on the same filesystem',
        );
    }

    public function anEmptyObjectIsListedWithSizeZero(): void
    {
        $this->place('docs/empty.bin', '');

        $objects = iterator_to_array($this->store->objects(), preserve_keys: false);

        Assert::same($objects[0]->size, 0);
    }

    /**
     * Review 1.0 B8: a quarantine that failed on its second member left the
     * first one moved; the row stayed, and the retention purged its original.
     * The failure here is a target path over PATH_MAX — the quarantine is
     * deeper than the root — which fails for root too, unlike permissions.
     */
    public function aFailedQuarantinePutsBackWhatItAlreadyMoved(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            // The failure is a path over PATH_MAX, and on Windows the 260-char
            // limit already refuses the quarantine directory in the
            // constructor. The mutation gate runs on Linux.
            return;
        }

        $deep = $this->base . '/' . implode('/', array_fill(0, 15, str_repeat('q', 200)));
        $store = new FileSystemStore('upload', $this->root, $this->factory, quarantinePath: $deep);
        $long = 'k/' . implode('/', array_fill(0, 8, str_repeat('b', 200))) . '/x.bin';
        $this->place('k/a.bin', 'first');
        $this->place($long, 'second');

        try {
            $store->quarantine(ObjectSet::tree(new StoredObjectId('k')), $this->now);
            Assert::fail('expected the second move to fail');
        } catch (StoreException $e) {
            Assert::string($e->getMessage())->contains('nothing was moved');
        }

        Assert::same(file_get_contents($this->root . '/k/a.bin'), 'first');
        Assert::same(file_get_contents($this->root . '/' . $long), 'second');
        Assert::same([...$store->quarantined()], []);
    }

    /**
     * A legacy row that does not fit the layout is a StoreException at the
     * store boundary — what download actions and derivative generators catch —
     * never the layout's InvalidArgumentException.
     */
    public function aRowTheLayoutRefusesIsAStoreError(): void
    {
        $this->place('docs/x.bin', 'legacy');
        $file = $this->file('docs/x.bin');
        $thumb = new DerivativeDescriptor('s', 'webp', 'image/webp');
        $public = new \Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore($this->store, 'https://x.test');
        $memory = new \Rasuvaeff\Yii3Filestorage\Test\InMemoryStore('m', $this->factory);

        foreach ([
            'delete' => fn() => $this->store->delete($file),
            'hasDerivative' => fn() => $this->store->hasDerivative($file, $thumb),
            'derivativeStream' => fn() => $this->store->derivativeStream($file, $thumb),
            'writeDerivative' => fn() => $this->store->writeDerivative($file, $thumb, $this->factory->createStream('x')),
            'publicDerivativeUrl' => static fn() => $public->publicDerivativeUrl($file, $thumb),
            'in-memory delete' => static fn() => $memory->delete($file),
        ] as $call => $run) {
            try {
                $run();
                Assert::fail("{$call} did not refuse");
            } catch (StoreException $e) {
                Assert::string($e->getMessage())->contains('does not fit the directory layout');
            }
        }

        Assert::same(file_get_contents($this->root . '/docs/x.bin'), 'legacy');
    }

    private function place(string $relativePath, string $bytes): void
    {
        $absolute = $this->root . '/' . $relativePath;
        FileHelper::ensureDirectory(\dirname($absolute));
        file_put_contents($absolute, $bytes);
    }

    /**
     * @return list<string>
     */
    private function paths(?QuarantinedObject $entry): array
    {
        Assert::instanceOf($entry, QuarantinedObject::class);

        return array_map(static fn(StoredObjectId $o): string => $o->relativePath, $entry?->objects ?? []);
    }

    /**
     * @return list<string>
     */
    private function inventory(?FileSystemStore $store = null): array
    {
        return array_map(
            static fn(StoredObject $o): string => $o->id->relativePath,
            iterator_to_array(($store ?? $this->store)->objects(), preserve_keys: false),
        );
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f',
            storeName: 'upload',
            groupName: 'g',
            relativePath: $relativePath,
            originalName: 'x',
            size: 0,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
