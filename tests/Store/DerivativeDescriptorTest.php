<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DerivativeDescriptor::class)]
final class DerivativeDescriptorTest
{
    public function describesOneNamedPreset(): void
    {
        $descriptor = new DerivativeDescriptor('thumb', 'webp', 'image/webp');

        Assert::same($descriptor->name, 'thumb');
        Assert::same($descriptor->extension, 'webp');
        Assert::same($descriptor->mediaType, 'image/webp');
        Assert::same($descriptor->fileName(), 'thumb.webp');
    }

    /**
     * The name becomes a filename inside the file's own directory, so a preset
     * called `../../etc` has to be impossible to construct — not merely
     * rejected somewhere further down.
     */
    #[DataProvider('invalidNameProvider')]
    public function rejectsANameThatCouldEscapeTheDirectory(string $name): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid derivative name');

        new DerivativeDescriptor($name, 'webp', 'image/webp');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['..'];
        yield 'a slash' => ['a/b'];
        yield 'uppercase' => ['Thumb'];
        yield 'leading dash' => ['-thumb'];
        yield 'a dot' => ['thumb.small'];
        yield 'NUL byte' => ["thumb\0"];
        yield 'over 64 characters' => [str_repeat('a', 65)];
    }

    #[DataProvider('invalidExtensionProvider')]
    public function rejectsAnInvalidExtension(string $extension): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid derivative extension');

        new DerivativeDescriptor('thumb', $extension, 'image/webp');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidExtensionProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'a slash' => ['a/b'];
        yield 'a dot' => ['tar.gz'];
        yield 'uppercase' => ['WEBP'];
        yield 'over ten characters' => [str_repeat('a', 11)];
    }

    #[DataProvider('invalidMediaTypeProvider')]
    public function rejectsAnInvalidMediaType(string $mediaType): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid derivative media type');

        new DerivativeDescriptor('thumb', 'webp', $mediaType);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMediaTypeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no subtype' => ['image'];
        yield 'trailing slash' => ['image/'];
        yield 'leading slash' => ['/webp'];
        yield 'a space' => ['image/ webp'];
        yield 'CRLF injection' => ["image/webp\r\nX-Evil: 1"];
    }

    public function acceptsAStructuredMediaType(): void
    {
        Assert::same((new DerivativeDescriptor('t', 'svg', 'image/svg+xml'))->mediaType, 'image/svg+xml');
    }
}
