<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\QuarantineSequence;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(QuarantinedObject::class)]
#[Covers(QuarantineSequence::class)]
final class QuarantinedObjectTest
{
    public function theMomentOfQuarantineTravelsInTheToken(): void
    {
        $entry = new QuarantinedObject('1764237600-0123456789abcdef', [new StoredObjectId('a/b.bin')]);

        Assert::same($entry->token, '1764237600-0123456789abcdef');
        Assert::same($entry->quarantinedAt->getTimestamp(), 1_764_237_600);
    }

    public function objectsAreOrderedByPath(): void
    {
        $entry = new QuarantinedObject('1764237600-0123456789abcdef', [
            new StoredObjectId('k/thumb.webp'),
            new StoredObjectId('k/original.jpg'),
        ]);

        Assert::same(
            array_map(static fn(StoredObjectId $o): string => $o->relativePath, $entry->objects),
            ['k/original.jpg', 'k/thumb.webp'],
        );
    }

    public function aNewTokenIsZeroPaddedSoTokensSortByTime(): void
    {
        $token = QuarantinedObject::newToken(new DateTimeImmutable('@999'));

        Assert::same(\strlen($token), 27);
        Assert::true(str_starts_with($token, '0000000999-'));
        Assert::true(QuarantinedObject::isToken($token));
        Assert::true(strcmp($token, QuarantinedObject::newToken(new DateTimeImmutable('@1000'))) < 0);
        Assert::same((new QuarantinedObject($token, [new StoredObjectId('x')]))->quarantinedAt->getTimestamp(), 999);
    }

    public function aTokenBeforeTheEpochIsClampedToIt(): void
    {
        Assert::true(str_starts_with(QuarantinedObject::newToken(new DateTimeImmutable('@-5')), '0000000000-'));
    }

    public function twoTokensForTheSameMomentDiffer(): void
    {
        $now = new DateTimeImmutable('@1764237600');

        Assert::true(QuarantinedObject::newToken($now) !== QuarantinedObject::newToken($now));
    }

    /**
     * Review 1.0 pass 2, N5: two entries quarantined in the same second sorted
     * by their random suffix, so a page listed them in an arbitrary order.
     */
    public function tokensForTheSameMomentSortInTheOrderTheyWereIssued(): void
    {
        $now = new DateTimeImmutable('@1764237600');
        $tokens = [];
        for ($i = 0; $i < 200; ++$i) {
            $tokens[] = QuarantinedObject::newToken($now);
        }

        $sorted = $tokens;
        sort($sorted, SORT_STRING);

        Assert::same($sorted, $tokens);
        Assert::same(\count(array_unique($tokens)), 200);
    }

    #[DataProvider('invalidTokenProvider')]
    public function anInvalidTokenIsRejected(string $token): void
    {
        Assert::false(QuarantinedObject::isToken($token));
        Expect::exception(InvalidArgumentException::class)->withMessageContaining("Invalid quarantine token \"{$token}\"");

        new QuarantinedObject($token, [new StoredObjectId('x')]);
    }

    public static function invalidTokenProvider(): iterable
    {
        yield 'too few digits' => ['999-0123456789abcdef'];
        yield 'short random part' => ['1764237600-0123456789abcde'];
        yield 'uppercase hex' => ['1764237600-0123456789ABCDEF'];
        yield 'trailing newline' => ["1764237600-0123456789abcdef\n"];
        yield 'path' => ['../1764237600-0123456789abcdef'];
    }

    public function anEmptyEntryIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('A quarantine entry holds at least one object');

        new QuarantinedObject('1764237600-0123456789abcdef', []);
    }
}
