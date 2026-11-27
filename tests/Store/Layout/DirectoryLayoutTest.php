<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\Layout;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
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
        Assert::same($layout->ownerOfFile($this->file($path)), $owner);
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
