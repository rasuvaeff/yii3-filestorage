<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Mime;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ExtensionMap::class)]
final class ExtensionMapTest
{
    #[DataProvider('knownTypeProvider')]
    public function mapsKnownMediaTypesThroughTheSymfonyTable(string $mediaType, string $expected): void
    {
        Assert::same((new ExtensionMap())->extensionFor($mediaType), $expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function knownTypeProvider(): iterable
    {
        yield 'png' => ['image/png', 'png'];
        yield 'jpeg' => ['image/jpeg', 'jpg'];
        yield 'pdf' => ['application/pdf', 'pdf'];
        yield 'plain text' => ['text/plain', 'txt'];
        yield 'zip' => ['application/zip', 'zip'];
        yield 'svg' => ['image/svg+xml', 'svg'];
        yield 'webm' => ['video/webm', 'webm'];
    }

    /**
     * An unknown type must not produce an empty or absent extension: the
     * stored object still needs an inert name.
     */
    public function anUnknownTypeFallsBackToBin(): void
    {
        Assert::same((new ExtensionMap())->extensionFor('application/x-not-a-real-type'), 'bin');
    }

    public function aNullTypeFallsBackToBin(): void
    {
        Assert::same((new ExtensionMap())->extensionFor(null), 'bin');
    }

    public function mediaTypesAreMatchedCaseInsensitively(): void
    {
        Assert::same((new ExtensionMap())->extensionFor('IMAGE/PNG'), 'png');
    }

    public function anOverrideWinsOverTheBuiltInTable(): void
    {
        $map = new ExtensionMap(['image/jpeg' => 'jpg']);

        Assert::same($map->extensionFor('image/jpeg'), 'jpg');
        Assert::same($map->extensionFor('image/png'), 'png', 'other types are untouched');
    }

    #[DataProvider('unsafeExtensionProvider')]
    public function anOverrideThatCouldEscapeAPathIsRejected(string $extension): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid extension');

        new ExtensionMap(['application/x-thing' => $extension]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeExtensionProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'path separator' => ['a/b'];
        yield 'traversal' => ['..'];
        yield 'a dot' => ['tar.gz'];
        yield 'uppercase' => ['PNG'];
        yield 'over ten characters' => ['abcdefghijk'];
        yield 'NUL byte' => ["png\0"];
    }
}
