<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StoredObject::class)]
#[Covers(StoredObjectId::class)]
final class StoredObjectTest
{
    public function exposesItsIdTimestampAndSize(): void
    {
        $at = new DateTimeImmutable('2026-11-27T10:00:00+00:00');
        $object = new StoredObject(new StoredObjectId('docs/ab/key/original.pdf'), $at, 12);

        Assert::same($object->id->relativePath, 'docs/ab/key/original.pdf');
        Assert::same($object->lastModified, $at);
        Assert::same($object->size, 12);
    }

    public function timestampAndSizeAreOptional(): void
    {
        $object = new StoredObject(new StoredObjectId('a.bin'));

        Assert::null($object->lastModified);
        Assert::null($object->size);
    }

    public function anEmptyObjectHasSizeZero(): void
    {
        Assert::same((new StoredObject(new StoredObjectId('a.bin'), size: 0))->size, 0);
    }

    public function aNegativeSizeIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Object size must not be negative');

        new StoredObject(new StoredObjectId('a.bin'), size: -1);
    }

    public function theDirectoryOfAnIdMatchesTheDirectoryOfAFile(): void
    {
        Assert::same((new StoredObjectId('docs/ab/key/original.pdf'))->directory(), 'docs/ab/key');
        Assert::same((new StoredObjectId('docs/x'))->directory(), 'docs');
        Assert::same((new StoredObjectId('lonely.bin'))->directory(), 'lonely.bin');
    }
}
