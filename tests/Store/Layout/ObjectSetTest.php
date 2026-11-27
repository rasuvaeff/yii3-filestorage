<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\Layout;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ObjectSet::class)]
final class ObjectSetTest
{
    #[DataProvider('treeProvider')]
    public function aTreeHoldsItsRootAndEverythingUnderIt(string $path, bool $member): void
    {
        $set = ObjectSet::tree(new StoredObjectId('docs/ab/key'));

        Assert::true($set->isTree());
        Assert::null($set->base);
        Assert::same($set->path, 'docs/ab/key');
        Assert::same($set->directory(), 'docs/ab/key');
        Assert::same($set->contains($path), $member);
    }

    public static function treeProvider(): iterable
    {
        yield 'the root itself' => ['docs/ab/key', true];
        yield 'a file inside' => ['docs/ab/key/original.jpg', true];
        yield 'a nested file' => ['docs/ab/key/a/b.bin', true];
        yield 'a sibling extending the name' => ['docs/ab/keyx/original.jpg', false];
        yield 'the parent' => ['docs/ab', false];
    }

    #[DataProvider('siblingsProvider')]
    public function siblingsAreTheBaseWithAnExtensionOrADashSuffix(string $path, bool $member): void
    {
        $set = ObjectSet::siblings('blobs/ab/cd', 'abc123');

        Assert::false($set->isTree());
        Assert::same($set->base, 'abc123');
        Assert::same($set->path, 'blobs/ab/cd/abc123');
        Assert::same($set->directory(), 'blobs/ab/cd');
        Assert::same($set->contains($path), $member);
    }

    public static function siblingsProvider(): iterable
    {
        yield 'the bare base' => ['blobs/ab/cd/abc123', true];
        yield 'the original' => ['blobs/ab/cd/abc123.jpg', true];
        yield 'a derivative' => ['blobs/ab/cd/abc123-s-v1.webp', true];
        yield 'a longer base' => ['blobs/ab/cd/abc1234.jpg', false];
        yield 'another base' => ['blobs/ab/cd/abd123.jpg', false];
        yield 'a nested path' => ['blobs/ab/cd/abc123.d/x.jpg', false];
        yield 'another directory' => ['blobs/ab/ce/abc123.jpg', false];
    }

    public function siblingsAtTheRootHaveNoDirectory(): void
    {
        $set = ObjectSet::siblings(null, 'abc');

        Assert::same($set->path, 'abc');
        Assert::null($set->directory());
        Assert::true($set->contains('abc.jpg'));
        Assert::same(ObjectSet::siblings('', 'abc')->path, 'abc');
    }

    #[DataProvider('ambiguousBaseProvider')]
    public function anAmbiguousBaseIsRejected(string $base): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining(
            "Invalid sibling base \"{$base}\": only letters, digits and underscores keep it unambiguous",
        );

        ObjectSet::siblings('d', $base);
    }

    public static function ambiguousBaseProvider(): iterable
    {
        yield 'dash' => ['foo-bar'];
        yield 'dot' => ['foo.bar'];
        yield 'empty' => [''];
        yield 'trailing newline' => ["foo\n"];
    }
}
