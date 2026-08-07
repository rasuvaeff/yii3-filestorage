<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(BlobId::class)]
final class BlobIdTest
{
    public function combinesAStoreNameWithAValidatedPath(): void
    {
        $id = BlobId::create('upload', 'a/b/original');

        Assert::same($id->storeName, 'upload');
        Assert::same($id->relativePath(), 'a/b/original');
        Assert::same($id->key(), 'upload:a/b/original');
    }

    public function acceptsAnAlreadyValidatedObjectId(): void
    {
        $id = new BlobId('upload', new StoredObjectId('a/b'));

        Assert::same($id->relativePath(), 'a/b');
    }

    /**
     * Physical identity, not content identity. The same bytes in two stores are
     * two blobs, and a reference count that conflated them would let a delete
     * in one store remove what the other still points at.
     */
    public function theSamePathInDifferentStoresIsADifferentBlob(): void
    {
        Assert::false(BlobId::create('a', 'p')->equals(BlobId::create('b', 'p')));
        Assert::true(BlobId::create('a', 'p')->equals(BlobId::create('a', 'p')));
        Assert::false(BlobId::create('a', 'p')->equals(BlobId::create('a', 'q')));
    }

    public function keysDifferWhenBlobsDiffer(): void
    {
        Assert::true(BlobId::create('a', 'p')->key() !== BlobId::create('b', 'p')->key());
    }

    #[DataProvider('invalidStoreNameProvider')]
    public function rejectsAnInvalidStoreName(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid store name');

        BlobId::create($name, 'a/b');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidStoreNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'with a slash' => ['a/b'];
        yield 'leading dash' => ['-a'];
        yield 'a colon' => ['a:b'];
        yield 'over 64 characters' => [str_repeat('a', 65)];
    }

    public function rejectsAnInvalidPath(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid stored object path');

        BlobId::create('upload', '../escape');
    }
}
