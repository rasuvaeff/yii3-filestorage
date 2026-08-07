<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StoredObjectId::class)]
final class StoredObjectIdTest
{
    public function keepsAValidRelativePath(): void
    {
        Assert::same((new StoredObjectId('a/b/c/original.png'))->relativePath, 'a/b/c/original.png');
    }

    public function equalityIsByPath(): void
    {
        Assert::true((new StoredObjectId('a/b'))->equals(new StoredObjectId('a/b')));
        Assert::false((new StoredObjectId('a/b'))->equals(new StoredObjectId('a/c')));
    }

    /**
     * Paths are always generated, never taken from a request — but this type is
     * what makes that checkable, so a wrong row in a database still cannot walk
     * out of the store root.
     */
    #[DataProvider('invalidPathProvider')]
    public function rejectsAnythingThatCouldEscapeTheRoot(string $path): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid stored object path');

        new StoredObjectId($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPathProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc/passwd'];
        yield 'leading traversal' => ['../secret'];
        yield 'inner traversal' => ['a/../../etc/passwd'];
        yield 'trailing traversal' => ['a/b/..'];
        yield 'only traversal' => ['..'];
        yield 'NUL byte' => ["a\0b"];
        yield 'backslash' => ['a\\b'];
        yield 'windows traversal' => ['..\\..\\secret'];
    }

    /**
     * Dots inside a longer segment are an ordinary name, not traversal:
     * rejecting them would break real filenames.
     */
    #[DataProvider('validPathProvider')]
    public function acceptsOrdinaryNamesThatMerelyContainDots(string $path): void
    {
        Assert::same((new StoredObjectId($path))->relativePath, $path);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function validPathProvider(): iterable
    {
        yield 'trailing dots in a segment' => ['a/b../c'];
        yield 'leading dots in a segment' => ['a/..b/c'];
        yield 'a hidden file' => ['a/.hidden'];
        yield 'many dots' => ['a/.../b'];
        yield 'a single segment' => ['original.bin'];
    }
}
