<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Command\GcCommand;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

/**
 * The default layout pinned byte for byte, written against the 0.8.4 code
 * before the layout became a strategy, and kept unchanged since: every path
 * below is persisted data or a published URL somewhere.
 */
#[Test]
#[Covers(FileSystemStore::class)]
#[Covers(PublicFileSystemStore::class)]
#[Covers(InMemoryStore::class)]
#[Covers(GcCommand::class)]
final class DirectoryLayoutRegressionTest
{
    private const string KEY_DIRECTORY = 'docs/ab/cd/0123456789abcdef0123456789abcdef';

    private string $root;
    private Psr17Factory $factory;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/layout-regression-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root)) {
            FileHelper::removeDirectory($this->root);
        }
    }

    public function aDerivativeIsWrittenAsASiblingFileInTheKeyDirectory(): void
    {
        $store = new FileSystemStore('upload', $this->root, $this->factory);
        $file = $this->place(self::KEY_DIRECTORY . '/original.jpg');

        $object = $store->writeDerivative(
            $file,
            new DerivativeDescriptor('thumb-v1', 'webp', 'image/webp'),
            $this->factory->createStream('rendition'),
        );

        Assert::same($object->relativePath, self::KEY_DIRECTORY . '/thumb-v1.webp');
        Assert::same(file_get_contents($this->root . '/' . self::KEY_DIRECTORY . '/thumb-v1.webp'), 'rendition');
    }

    public function aPublicDerivativeUrlIsTheSiblingPath(): void
    {
        $store = new PublicFileSystemStore(
            new FileSystemStore('upload', $this->root, $this->factory),
            'https://cdn.example.test/u',
        );

        Assert::same(
            $store->publicDerivativeUrl(
                $this->file(self::KEY_DIRECTORY . '/original.jpg'),
                new DerivativeDescriptor('thumb-v1', 'webp', 'image/webp'),
            ),
            'https://cdn.example.test/u/' . self::KEY_DIRECTORY . '/thumb-v1.webp',
        );
    }

    public function deleteRemovesExactlyTheKeyDirectoryOnDisk(): void
    {
        $store = new FileSystemStore('upload', $this->root, $this->factory);
        $file = $this->place(self::KEY_DIRECTORY . '/original.jpg');
        $this->place(self::KEY_DIRECTORY . '/thumb-v1.webp');
        $this->place(self::KEY_DIRECTORY . '/nested/deeper.bin');
        $neighbour = $this->place(self::KEY_DIRECTORY . 'ff/original.jpg');
        $parentFile = $this->place('docs/ab/cd/stray.txt');

        $store->delete($file);

        Assert::false(file_exists($this->root . '/' . self::KEY_DIRECTORY));
        Assert::true(is_file($this->root . '/' . $neighbour->relativePath));
        Assert::true(is_file($this->root . '/' . $parentFile->relativePath));
        Assert::true(is_dir($this->root . '/docs/ab/cd'));
    }

    public function deleteOfARootLevelObjectRemovesOnlyThatObject(): void
    {
        $store = new FileSystemStore('upload', $this->root, $this->factory);
        $file = $this->place('lonely.bin');
        $other = $this->place('other.bin');

        $store->delete($file);

        Assert::false(is_file($this->root . '/lonely.bin'));
        Assert::true(is_file($this->root . '/' . $other->relativePath));
    }

    public function inMemoryDeleteRemovesExactlyTheKeyDirectory(): void
    {
        $store = new InMemoryStore('upload', $this->factory);
        $paths = [
            self::KEY_DIRECTORY . '/original.jpg',
            self::KEY_DIRECTORY . '/thumb-v1.webp',
            self::KEY_DIRECTORY . 'ff/original.jpg',
            'docs/ab/cd/stray.txt',
        ];
        foreach ($paths as $path) {
            $store->corrupt($path, 'x');
        }

        $store->delete($this->file(self::KEY_DIRECTORY . '/original.jpg'));

        $left = $store->paths();
        sort($left);
        Assert::same($left, ['docs/ab/cd/0123456789abcdef0123456789abcdef' . 'ff/original.jpg', 'docs/ab/cd/stray.txt']);
    }

    /**
     * The sweep matches an object to a row by the row's key directory: a
     * derivative beside a referenced original is referenced, a neighbouring
     * key directory is not, and nothing at the root can be referenced by a
     * row elsewhere.
     */
    public function theOrphanSweepMatchesObjectsToRowsByKeyDirectory(): void
    {
        $clock = new MovableClock(new DateTimeImmutable());
        $store = new FileSystemStore('upload', $this->root, $this->factory);
        $repository = new MemoryRepository();
        $repository->save($this->place(self::KEY_DIRECTORY . '/original.jpg'));
        $this->place(self::KEY_DIRECTORY . '/thumb-v1.webp');
        $this->place(self::KEY_DIRECTORY . 'ff/original.jpg');
        $this->place('lonely.bin');
        $this->age();

        $tester = new CommandTester(new GcCommand(
            stores: new StoreRegistry([$store]),
            repository: $repository,
            clock: $clock,
        ));
        $tester->execute(['--orphans' => true]);

        $display = $tester->getDisplay();
        Assert::string($display)->contains('would delete ' . self::KEY_DIRECTORY . 'ff/original.jpg');
        Assert::string($display)->contains('would delete lonely.bin');
        Assert::string($display)->notContains('would delete ' . self::KEY_DIRECTORY . '/');
    }

    private function place(string $relativePath): File
    {
        $absolute = $this->root . '/' . $relativePath;
        FileHelper::ensureDirectory(\dirname($absolute));
        file_put_contents($absolute, 'bytes of ' . $relativePath);

        return $this->file($relativePath);
    }

    /**
     * Everything on disk two hours in the past, so a minimum-age rule cannot
     * be what decides the sweep.
     */
    private function age(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                touch($entry->getPathname(), time() - 7200);
            }
        }
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f-' . bin2hex(random_bytes(4)),
            storeName: 'upload',
            groupName: 'docs',
            relativePath: $relativePath,
            originalName: 'thing.jpg',
            size: 0,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }
}
