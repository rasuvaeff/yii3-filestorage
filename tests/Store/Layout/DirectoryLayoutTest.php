<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\Layout;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DirectoryLayout::class)]
final class DirectoryLayoutTest
{
    public function aDerivativeIsASiblingInTheKeyDirectory(): void
    {
        Assert::same(
            (new DirectoryLayout())->derivativePath(
                $this->file('docs/ab/key/original.jpg'),
                new DerivativeDescriptor('thumb-v1', 'webp', 'image/webp'),
            ),
            'docs/ab/key/thumb-v1.webp',
        );
    }

    #[DataProvider('ownerProvider')]
    public function theOwnerIsTheKeyDirectory(string $path, string $owner): void
    {
        $layout = new DirectoryLayout();

        Assert::same($layout->ownerOf(new StoredObjectId($path)), $owner);
    }

    public static function ownerProvider(): iterable
    {
        yield 'original' => ['docs/ab/key/original.jpg', 'docs/ab/key'];
        yield 'derivative' => ['docs/ab/key/thumb.webp', 'docs/ab/key'];
        yield 'one level' => ['docs/x.bin', 'docs'];
        yield 'root level answers itself' => ['lonely.bin', 'lonely.bin'];
    }

    public function deletingAFileRemovesItsKeyDirectory(): void
    {
        $set = (new DirectoryLayout())->deletion(new StoredObjectId('docs/ab/key/original.jpg'));

        Assert::true($set->isTree());
        Assert::same($set->path, 'docs/ab/key');
    }

    public function deletingARootLevelObjectRemovesOnlyIt(): void
    {
        $set = (new DirectoryLayout())->deletion(new StoredObjectId('lonely.bin'));

        Assert::same($set->path, 'lonely.bin');
        Assert::false($set->contains('lonely.bin.x'));
    }

    /**
     * Review 1.0 A1: a flat key's directory is a fan-out bucket shared with
     * other blobs; deleting "its key directory" deleted them all.
     */
    #[DataProvider('foreignShapeProvider')]
    public function aPathThatDoesNotOwnItsDirectoryIsRefusedForDeletion(string $path): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('does not fit the directory layout');

        (new DirectoryLayout())->deletion(new StoredObjectId($path));
    }

    #[DataProvider('foreignShapeProvider')]
    public function aPathThatDoesNotOwnItsDirectoryGetsNoDerivativePath(string $path): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('needs FlatSiblingLayout');

        (new DirectoryLayout())->derivativePath($this->file($path), new DerivativeDescriptor('s', 'webp', 'image/webp'));
    }

    public static function foreignShapeProvider(): iterable
    {
        yield 'flat content-addressed key' => ['sha/ab/cd/' . str_repeat('a', 64) . '.jpg'];
        yield 'flat key without extension' => ['sha/ab/cd/' . str_repeat('a', 64)];
        yield 'bare file in a group' => ['docs/x.bin'];
        yield 'a derivative' => ['docs/ab/key/thumb.webp'];
        yield 'original as a prefix only' => ['docs/ab/key/originals.jpg'];
    }

    #[DataProvider('ownShapeProvider')]
    public function anOriginalOwnsItsDirectory(string $path, string $directory): void
    {
        $layout = new DirectoryLayout();

        Assert::same($layout->deletion(new StoredObjectId($path))->path, $directory);
        Assert::same(
            $layout->derivativePath($this->file($path), new DerivativeDescriptor('s', 'webp', 'image/webp')),
            $directory . '/s.webp',
        );
    }

    public static function ownShapeProvider(): iterable
    {
        yield 'with an extension' => ['docs/ab/key/original.jpg', 'docs/ab/key'];
        yield 'without one' => ['sha/ab/cd/' . str_repeat('a', 64) . '/original', 'sha/ab/cd/' . str_repeat('a', 64)];
        yield 'a dotted extension' => ['docs/key/original.tar.gz', 'docs/key'];
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f',
            storeName: 's',
            groupName: 'g',
            relativePath: $relativePath,
            originalName: 'x',
            size: 0,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
