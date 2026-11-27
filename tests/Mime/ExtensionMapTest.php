<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Mime;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Symfony\Component\Mime\MimeTypes;
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

    /**
     * Review 1.0 B5, probe p4: a public store serves its objects by path
     * whatever the delivery policy says, so the extension *is* the response
     * type there. Types a browser executes, and extensions a web server runs,
     * land on the inert fallback. Only new files are affected — existing
     * objects keep the path in `File::$relativePath`.
     */
    #[DataProvider('activeTypeProvider')]
    public function activeAndExecutableTypesGetTheInertExtension(string $mediaType): void
    {
        Assert::same((new ExtensionMap())->extensionFor($mediaType), 'bin');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function activeTypeProvider(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'html, upper case' => ['TEXT/HTML'];
        yield 'xhtml' => ['application/xhtml+xml'];
        yield 'svg' => ['image/svg+xml'];
        yield 'xml as finfo reports XHTML' => ['text/xml'];
        yield 'application/xml' => ['application/xml'];
        yield 'javascript' => ['application/javascript'];
        yield 'text/javascript' => ['text/javascript'];
        yield 'xslt' => ['application/xslt+xml'];
        yield 'rss' => ['application/rss+xml'];
        yield 'atom' => ['application/atom+xml'];
        yield 'perl script, runs as CGI' => ['text/x-perl'];
        yield 'shell script' => ['application/x-sh'];
        yield 'windows executable' => ['application/x-dosexec'];
        yield 'php source' => ['application/x-httpd-php'];
    }

    /**
     * The neutralisation also covers a caller-supplied table, and a type known
     * by any executable extension is treated as executable — a later, harmless
     * looking candidate is not taken instead.
     */
    public function aCustomTableCannotReintroduceAnExecutableExtension(): void
    {
        $map = new ExtensionMap([], new MimeTypes(['custom/vnd' => ['zzz', 'phtml'], 'custom/ok' => ['zzz']]));

        Assert::same($map->extensionFor('custom/vnd'), 'bin');
        Assert::same($map->extensionFor('custom/ok'), 'zzz');
    }

    /**
     * Overrides are the documented way back: an application that really
     * serves public SVG through `<img>` says so explicitly.
     */
    public function anOverrideCanOptAnActiveTypeBackIn(): void
    {
        Assert::same((new ExtensionMap(['image/svg+xml' => 'svg']))->extensionFor('image/svg+xml'), 'svg');
    }

    public function aNullTypeFallsBackToBin(): void
    {
        Assert::same((new ExtensionMap())->extensionFor(null), 'bin');
    }

    public function mediaTypesAreMatchedCaseInsensitively(): void
    {
        Assert::same((new ExtensionMap())->extensionFor('IMAGE/PNG'), 'png');
    }

    /**
     * A caller-supplied table replaces the built-in one entirely: an
     * application with its own media-type registry must not silently fall
     * back to the Symfony defaults for types the defaults do not know.
     */
    public function aCustomTableWinsOverTheBuiltInOne(): void
    {
        $map = new ExtensionMap([], new MimeTypes(['custom/vnd' => ['zzz']]));

        Assert::same($map->extensionFor('custom/vnd'), 'zzz');
    }

    /**
     * Overrides are keyed by lowercase media type and win outright: the
     * lookup normalises the incoming type, so `IMAGE/PNG` hits the same
     * override as `image/png` — and the override beats the table.
     */
    public function anOverrideIsHitWhateverTheCaseOfTheIncomingType(): void
    {
        $map = new ExtensionMap(['image/png' => 'img']);

        Assert::same($map->extensionFor('IMAGE/PNG'), 'img');
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
