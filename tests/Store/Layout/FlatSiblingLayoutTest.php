<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\Layout;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FlatSiblingLayout::class)]
final class FlatSiblingLayoutTest
{
    private const string SHA = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    public function aDerivativeSitsBesideTheOriginalWithADashSuffix(): void
    {
        Assert::same(
            (new FlatSiblingLayout())->derivativePath(
                $this->file('blobs/ab/12/' . self::SHA . '.jpg'),
                new DerivativeDescriptor('s-v1', 'webp', 'image/webp'),
            ),
            'blobs/ab/12/' . self::SHA . '-s-v1.webp',
        );
    }

    public function aRootLevelFileGetsARootLevelDerivative(): void
    {
        Assert::same(
            (new FlatSiblingLayout())->derivativePath(
                $this->file('abc.jpg'),
                new DerivativeDescriptor('m', 'webp', 'image/webp'),
            ),
            'abc-m.webp',
        );
    }

    #[DataProvider('ownerProvider')]
    public function theOwnerIsTheBaseBeforeTheFirstDashOrDot(string $path, string $owner): void
    {
        Assert::same((new FlatSiblingLayout())->ownerOf(new StoredObjectId($path)), $owner);
    }

    public static function ownerProvider(): iterable
    {
        yield 'original' => ['blobs/ab/cd/abc.jpg', 'blobs/ab/cd/abc'];
        yield 'derivative' => ['blobs/ab/cd/abc-s-v1.webp', 'blobs/ab/cd/abc'];
        yield 'no extension' => ['blobs/ab/cd/abc', 'blobs/ab/cd/abc'];
        yield 'root level' => ['abc.jpg', 'abc'];
        yield 'root level derivative' => ['abc-m.webp', 'abc'];
        yield 'dot first' => ['d/.hidden', 'd/.hidden'];
        yield 'dash first' => ['-x.jpg', '-x.jpg'];
    }

    public function theOwnerOfAFileIsTheOwnerOfItsPath(): void
    {
        Assert::same((new FlatSiblingLayout())->ownerOfFile($this->file('b/ab/abc.jpg')), 'b/ab/abc');
    }

    public function deletingAFileRemovesItAndItsSiblings(): void
    {
        $set = (new FlatSiblingLayout())->deletion(new StoredObjectId('blobs/ab/cd/abc.jpg'));

        Assert::false($set->isTree());
        Assert::same($set->path, 'blobs/ab/cd/abc');
        Assert::same($set->base, 'abc');
        Assert::true($set->contains('blobs/ab/cd/abc-m.webp'));
        Assert::false($set->contains('blobs/ab/cd/abcd.jpg'));
    }

    public function deletingAnObjectWithoutExtensionWorks(): void
    {
        Assert::same((new FlatSiblingLayout())->deletion(new StoredObjectId('abc'))->path, 'abc');
    }

    #[DataProvider('ambiguousNameProvider')]
    public function aNameThatDoesNotFitTheLayoutIsRefused(string $path): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining(
            "\"{$path}\" does not fit the flat sibling layout: the name must be <base> or <base>.<ext>, "
            . 'with a base of letters, digits and underscores',
        );

        (new FlatSiblingLayout())->deletion(new StoredObjectId($path));
    }

    #[DataProvider('ambiguousNameProvider')]
    public function noDerivativePathForANameThatDoesNotFit(string $path): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('does not fit the flat sibling layout');

        (new FlatSiblingLayout())->derivativePath($this->file($path), new DerivativeDescriptor('m', 'webp', 'image/webp'));
    }

    public static function ambiguousNameProvider(): iterable
    {
        yield 'a dash in the base' => ['d/foo-bar.jpg'];
        yield 'two extensions' => ['d/foo.tar.gz'];
        yield 'trailing newline' => ["d/foo.jpg\n"];
        yield 'dot first' => ['d/.jpg'];
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
