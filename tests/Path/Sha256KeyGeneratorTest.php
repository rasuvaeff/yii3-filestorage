<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Path;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Sha256KeyGenerator::class)]
final class Sha256KeyGeneratorTest
{
    public function producesScopeTwoFanOutSegmentsTheHashAndAnExtensionlessObject(): void
    {
        $hash = hash('sha256', 'content');

        Assert::same(
            (new Sha256KeyGenerator())->generate('tenant-1/avatars', $hash),
            'tenant-1/avatars/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '/original',
        );
    }

    /**
     * No extension on purpose: content identity must not move when a MIME
     * database is updated and the same bytes start being called something else.
     */
    public function theSharedObjectHasNoExtension(): void
    {
        Assert::true(str_ends_with((new Sha256KeyGenerator())->generate('s', hash('sha256', 'x')), '/original'));
    }

    public function theSameContentInTheSameScopeGivesTheSameKey(): void
    {
        $generator = new Sha256KeyGenerator();
        $hash = hash('sha256', 'content');

        Assert::same($generator->generate('s', $hash), $generator->generate('s', $hash));
    }

    /**
     * Scoping is what stops one tenant learning that another already stored a
     * given file: identical bytes land on different keys.
     */
    public function theSameContentInDifferentScopesGivesDifferentKeys(): void
    {
        $generator = new Sha256KeyGenerator();
        $hash = hash('sha256', 'content');

        Assert::true($generator->generate('tenant-1', $hash) !== $generator->generate('tenant-2', $hash));
    }

    public function everyGeneratedKeyIsAValidStoredObjectId(): void
    {
        $key = (new Sha256KeyGenerator())->generate('a/b', hash('sha256', 'x'));

        Assert::same((new StoredObjectId($key))->relativePath, $key);
    }

    #[DataProvider('invalidScopeProvider')]
    public function anInvalidScopeIsRejected(string $scope): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid content-addressed scope');

        (new Sha256KeyGenerator())->generate($scope, hash('sha256', 'x'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidScopeProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../etc'];
        yield 'absolute' => ['/etc'];
        yield 'trailing slash' => ['a/'];
        yield 'double slash' => ['a//b'];
        yield 'NUL byte' => ["a\0b"];
        yield 'space' => ['a b'];
    }

    #[DataProvider('invalidDigestProvider')]
    public function anInvalidDigestIsRejected(string $digest): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid SHA-256 digest');

        (new Sha256KeyGenerator())->generate('s', $digest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDigestProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'uppercase' => [str_repeat('A', 64)];
        yield 'not hex' => [str_repeat('z', 64)];
    }
}
