<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(BlobToken::class)]
final class BlobTokenTest
{
    public function keepsTheValueItWasGiven(): void
    {
        Assert::same((new BlobToken('abcd1234'))->value, 'abcd1234');
    }

    public function randomTokensAreThirtyTwoHexCharactersAndDistinct(): void
    {
        $first = BlobToken::random();
        $second = BlobToken::random();

        Assert::same(strlen($first->value), 32);
        Assert::same(preg_match('/^[a-f0-9]{32}\z/', $first->value), 1);
        Assert::false($first->equals($second));
    }

    public function equalityIsByValue(): void
    {
        Assert::true((new BlobToken('abcd1234'))->equals(new BlobToken('abcd1234')));
        Assert::false((new BlobToken('abcd1234'))->equals(new BlobToken('abcd1235')));
    }

    #[DataProvider('invalidTokenProvider')]
    public function rejectsAValueItCouldNotHaveIssued(string $value): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid blob token');

        new BlobToken($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTokenProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short to be unguessable' => ['abc'];
        yield 'over 64 characters' => [str_repeat('a', 65)];
        yield 'a dot, which separates token segments elsewhere' => ['abcd.1234'];
        yield 'a quote' => ["abcd'1234"];
        yield 'a trailing newline' => ["abcd1234\n"];
    }
}
