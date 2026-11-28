<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Rasuvaeff\Yii3Filestorage\Upload;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

/**
 * Review 1.0 B2 (the inventory is what the store owns) and B10 (resuming a
 * page does not re-walk everything before the cursor).
 */
#[Test]
#[Covers(FileSystemStore::class)]
#[Covers(PublicFileSystemStore::class)]
final class FileSystemStoreInventoryTest
{
    private const array TREE = [
        'a.txt',
        'a-b',
        'a/x',
        'a/y/z',
        'a0/q',
        'b/c/d',
        'blobs/ab/cd/0011.jpg',
        'blobs/ab/cd/0011-s.webp',
        'blobs/ab/ce/0022.jpg',
        'z',
    ];

    private string $base;
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/fs-inventory-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->base)) {
            FileHelper::removeDirectory($this->base);
        }
    }

    public function hiddenEntriesAreNeverListed(): void
    {
        $this->place('.htaccess', 'docs/.git/config', '.well-known/x', 'docs/key/original.jpg', 'docs/key/.part-ish');

        Assert::same($this->all($this->store()), ['docs/key/original.jpg']);
    }

    public function ownedPrefixesLimitTheInventory(): void
    {
        $this->place('banners/top.png', 'blobs/ab/cd/x.jpg', 'avatars/k/original.png', 'avatarsx/y', 'stray.txt');

        Assert::same(
            $this->all($this->store(ownedPrefixes: ['blobs', 'avatars'])),
            ['avatars/k/original.png', 'blobs/ab/cd/x.jpg'],
        );
        Assert::same($this->store(ownedPrefixes: ['blobs', 'avatars'])->ownedPrefixes(), ['avatars', 'blobs']);
    }

    public function aNestedPrefixIsItsOwnSubtree(): void
    {
        $this->place('media/blobs/a', 'media/other/b');

        Assert::same($this->all($this->store(ownedPrefixes: ['media/blobs'])), ['media/blobs/a']);
    }

    public function aSymlinkedPrefixIsNotFollowed(): void
    {
        $this->place('elsewhere/secret');
        mkdir($this->base . '/root', 0o777, recursive: true);
        symlink($this->base . '/elsewhere', $this->base . '/root/blobs');

        Assert::same($this->all($this->store(ownedPrefixes: ['blobs'])), []);
    }

    #[DataProvider('pageSizeProvider')]
    public function pagesConcatenateToTheWholeInventory(int $pageSize): void
    {
        $this->place(...self::TREE);
        $expected = self::TREE;
        sort($expected, SORT_STRING);

        Assert::same($this->paged($this->store(), $pageSize), $expected);
        Assert::same(
            $this->paged($this->store(ownedPrefixes: ['blobs', 'a', 'b']), $pageSize),
            array_values(array_filter(
                $expected,
                static fn(string $p): bool => str_starts_with($p, 'a/') || str_starts_with($p, 'b/') || str_starts_with($p, 'blobs/'),
            )),
        );
    }

    public static function pageSizeProvider(): iterable
    {
        yield 'one' => [1];
        yield 'two' => [2];
        yield 'three' => [3];
        yield 'all' => [100];
    }

    /**
     * A cursor that names a path no longer there still resumes in order.
     */
    public function aCursorBetweenObjectsResumesAfterIt(): void
    {
        $this->place(...self::TREE);

        Assert::same(
            $this->all($this->store(), after: 'a/xx'),
            ['a/y/z', 'a0/q', 'b/c/d', 'blobs/ab/cd/0011-s.webp', 'blobs/ab/cd/0011.jpg', 'blobs/ab/ce/0022.jpg', 'z'],
        );
    }

    public function aWriteOutsideTheOwnedPrefixesIsRefused(): void
    {
        $store = $this->store(ownedPrefixes: ['blobs']);

        Expect::exception(StoreException::class)->withMessageContaining('is outside the owned prefixes of store "u" (blobs)');

        $store->write($this->upload(), 'docs', new RandomPathGenerator());
    }

    public function aWriteInsideTheOwnedPrefixesWorks(): void
    {
        $result = $this->store(ownedPrefixes: ['docs'])->write($this->upload(), 'docs', new RandomPathGenerator());

        Assert::true(str_starts_with($result->relativePath, 'docs/'), $result->relativePath);
    }

    public function aContentAddressedPutOutsideTheOwnedPrefixesIsRefused(): void
    {
        $store = $this->store(ownedPrefixes: ['blobs']);

        Expect::exception(StoreException::class)->withMessageContaining('Add its first segment to ownedPrefixes');

        $store->putIfAbsent($this->upload(), new StoredObjectId('sha/ab/cd/x.jpg'));
    }

    public function aPrefixThatIsAWordPrefixOfAPathDoesNotOwnIt(): void
    {
        Expect::exception(StoreException::class);

        $this->store(ownedPrefixes: ['blob'])->putIfAbsent($this->upload(), new StoredObjectId('blobs/x.jpg'));
    }

    #[DataProvider('invalidPrefixProvider')]
    public function anInvalidPrefixIsRefused(array $prefixes, string $message): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        $this->store(ownedPrefixes: $prefixes);
    }

    public static function invalidPrefixProvider(): iterable
    {
        yield 'empty' => [[''], 'invalid owned prefix \'\''];
        yield 'leading slash' => [['/blobs'], 'invalid owned prefix'];
        yield 'trailing slash' => [['blobs/'], 'invalid owned prefix'];
        yield 'traversal' => [['../x'], 'invalid owned prefix'];
        yield 'hidden' => [['.x'], 'invalid owned prefix'];
        yield 'not a string' => [[1], 'invalid owned prefix 1'];
        yield 'nested' => [['a', 'a/b'], 'owned prefix "a/b" lies inside "a"'];
    }

    public function thePublicStoreReportsItsOwnedPrefixes(): void
    {
        $public = new PublicFileSystemStore($this->store(ownedPrefixes: ['blobs']), 'https://x.test/u');

        Assert::same($public->ownedPrefixes(), ['blobs']);
    }

    /**
     * Review 1.0 B2, probe `foreign.php`: an application's own directories
     * and `.htaccess` under a shared web root were swept as orphans.
     */
    public function theOrphanSweepLeavesForeignContentAlone(): void
    {
        $this->place('banners/top.png', 'match-cards/m1.png', '.htaccess', 'blobs/ab/cd/' . str_repeat('a', 64) . '.jpg');
        $store = $this->store(ownedPrefixes: ['blobs'], quarantine: true);
        $clock = new MovableClock(new \DateTimeImmutable('+1 day'));

        $tester = new CommandTester(new GcCommand(new StoreRegistry([$store]), new MemoryRepository(), $clock));
        $tester->execute(['--apply' => true, '--orphans' => true]);

        Assert::true(is_file($this->base . '/root/banners/top.png'));
        Assert::true(is_file($this->base . '/root/match-cards/m1.png'));
        Assert::true(is_file($this->base . '/root/.htaccess'));
        Assert::false(is_file($this->base . '/root/blobs/ab/cd/' . str_repeat('a', 64) . '.jpg'), 'the store\'s own orphan is swept');
    }

    public function withoutOwnedPrefixesTheHtaccessStillSurvives(): void
    {
        $this->place('.htaccess', 'blobs/x/original.jpg');
        $clock = new MovableClock(new \DateTimeImmutable('+1 day'));

        $tester = new CommandTester(new GcCommand(new StoreRegistry([$this->store(quarantine: true)]), new MemoryRepository(), $clock));
        $tester->execute(['--apply' => true, '--orphans' => true]);

        Assert::true(is_file($this->base . '/root/.htaccess'));
        Assert::false(is_file($this->base . '/root/blobs/x/original.jpg'));
    }

    /**
     * @param list<mixed> $ownedPrefixes
     */
    private function store(array $ownedPrefixes = [], bool $quarantine = false): FileSystemStore
    {
        /** @var list<string> $ownedPrefixes */
        return new FileSystemStore(
            'u',
            $this->base . '/root',
            $this->factory,
            quarantinePath: $quarantine ? $this->base . '/quarantine' : null,
            layout: new FlatSiblingLayout(),
            ownedPrefixes: $ownedPrefixes,
        );
    }

    private function place(string ...$paths): void
    {
        foreach ($paths as $path) {
            $absolute = $this->base . '/' . (str_starts_with($path, 'elsewhere/') ? '' : 'root/') . $path;
            if (!is_dir(\dirname($absolute))) {
                mkdir(\dirname($absolute), 0o777, recursive: true);
            }
            file_put_contents($absolute, 'x');
            touch($absolute, time() - 7200);
        }
    }

    /**
     * @return list<string>
     */
    private function all(FileSystemStore $store, ?string $after = null): array
    {
        return array_map(
            static fn(StoredObject $o): string => $o->id->relativePath,
            [...$store->objects($after, 1000)],
        );
    }

    /**
     * @param int<1, max> $pageSize
     *
     * @return list<string>
     */
    private function paged(FileSystemStore $store, int $pageSize): array
    {
        $all = [];
        $after = null;
        do {
            $page = array_map(static fn(StoredObject $o): string => $o->id->relativePath, [...$store->objects($after, $pageSize)]);
            $all = [...$all, ...$page];
            $after = $page === [] ? null : $page[\count($page) - 1];
        } while ($page !== []);

        return $all;
    }

    private function upload(): Upload
    {
        return Upload::fromStream($this->factory->createStream('x'), 'x.bin', $this->factory);
    }
}
