<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DerivativeObject::class)]
final class DerivativeObjectTest
{
    public function reportsWhereTheDerivativeLandedAndWhatItIs(): void
    {
        $object = new DerivativeObject('a/b/key/thumb.webp', 512, 'image/webp');

        Assert::same($object->relativePath, 'a/b/key/thumb.webp');
        Assert::same($object->size, 512);
        Assert::same($object->mediaType, 'image/webp');
    }

    public function aZeroByteDerivativeIsValid(): void
    {
        Assert::same((new DerivativeObject('a', 0, 'image/webp'))->size, 0);
    }

    #[DataProvider('invalidProvider')]
    public function rejectsInvalidInput(string $message, callable $build): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        $build();
    }

    /**
     * @return iterable<string, array{string, callable}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'empty path' => [
            'relativePath must not be empty',
            static fn(): DerivativeObject => new DerivativeObject('', 1, 'image/webp'),
        ];
        yield 'negative size' => [
            'size must not be negative',
            static fn(): DerivativeObject => new DerivativeObject('a', -1, 'image/webp'),
        ];
        yield 'empty media type' => [
            'mediaType must not be empty',
            static fn(): DerivativeObject => new DerivativeObject('a', 1, ''),
        ];
    }
}
