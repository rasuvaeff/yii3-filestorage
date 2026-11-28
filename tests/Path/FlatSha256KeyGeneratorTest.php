<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Path;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(FlatSha256KeyGenerator::class)]
final class FlatSha256KeyGeneratorTest
{
    public function theKeyIsFlatWithTheExtension(): void
    {
        $hash = hash('sha256', 'x');

        Assert::same(
            (new FlatSha256KeyGenerator())->generate('blobs', $hash, 'jpg'),
            'blobs/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '.jpg',
        );
    }

    public function withoutAnExtensionTheKeyIsTheHash(): void
    {
        $hash = hash('sha256', 'x');

        Assert::same(
            (new FlatSha256KeyGenerator())->generate('t-1/blobs', $hash),
            't-1/blobs/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash,
        );
    }

    #[DataProvider('invalidProvider')]
    public function invalidInputIsRejected(string $scope, string $sha256, ?string $extension, string $message): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining($message);

        (new FlatSha256KeyGenerator())->generate($scope, $sha256, $extension);
    }

    public static function invalidProvider(): iterable
    {
        $hash = hash('sha256', 'x');

        yield 'traversal scope' => ['../x', $hash, null, 'Invalid content-addressed scope "../x"'];
        yield 'scope with newline' => ["blobs\n", $hash, null, 'Invalid content-addressed scope'];
        yield 'uppercase digest' => ['blobs', strtoupper($hash), null, 'Invalid SHA-256 digest'];
        yield 'short digest' => ['blobs', substr($hash, 1), null, 'Invalid SHA-256 digest'];
        yield 'dotted extension' => ['blobs', $hash, 'tar.gz', 'Invalid extension "tar.gz"'];
        yield 'uppercase extension' => ['blobs', $hash, 'JPG', 'Invalid extension "JPG"'];
        yield 'empty extension' => ['blobs', $hash, '', 'Invalid extension ""'];
        yield 'long extension' => ['blobs', $hash, str_repeat('a', 17), 'Invalid extension'];
        yield 'extension with newline' => ['blobs', $hash, "jpg\n", 'Invalid extension'];
    }

    public function aSixteenCharacterExtensionIsAccepted(): void
    {
        $hash = hash('sha256', 'x');

        Assert::true(str_ends_with(
            (new FlatSha256KeyGenerator())->generate('blobs', $hash, str_repeat('a', 16)),
            '.' . str_repeat('a', 16),
        ));
    }

    public function aPrefixReplacesTheScope(): void
    {
        $sha = str_repeat('ab', 32);

        Assert::same(
            (new FlatSha256KeyGenerator(prefix: 'blobs'))->generate('sha/tenant/group', $sha, 'jpg'),
            'blobs/ab/ab/' . $sha . '.jpg',
        );
        Assert::same(
            (new FlatSha256KeyGenerator(prefix: 'media/blobs'))->generate('sha', $sha),
            'media/blobs/ab/ab/' . $sha,
        );
    }

    public function withoutAPrefixTheScopeLeads(): void
    {
        $sha = str_repeat('ab', 32);

        Assert::same((new FlatSha256KeyGenerator())->generate('sha', $sha, 'jpg'), 'sha/ab/ab/' . $sha . '.jpg');
    }

    #[DataProvider('invalidPrefixProvider')]
    public function anInvalidPrefixIsRejected(string $prefix): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid key prefix');

        new FlatSha256KeyGenerator(prefix: $prefix);
    }

    public static function invalidPrefixProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'leading slash' => ['/blobs'];
        yield 'trailing slash' => ['blobs/'];
        yield 'traversal' => ['../blobs'];
        yield 'empty segment' => ['a//b'];
        yield 'dot first' => ['.blobs'];
        yield 'newline' => ["blobs\n"];
    }
}
