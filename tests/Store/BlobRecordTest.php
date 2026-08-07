<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobRecord;
use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(BlobRecord::class)]
final class BlobRecordTest
{
    private const string HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function exposesTheLedgerRowAsItWasRead(): void
    {
        $deleteAfter = new DateTimeImmutable('2026-01-01T01:00:00+00:00');
        $record = new BlobRecord(
            blob: BlobId::create('upload', 'a/b/original'),
            contentHash: self::HASH,
            size: 12,
            state: BlobState::PendingDelete,
            referenceCount: 0,
            reservationCount: 0,
            deleteAfter: $deleteAfter,
        );

        Assert::same($record->blob->key(), 'upload:a/b/original');
        Assert::same($record->contentHash, self::HASH);
        Assert::same($record->size, 12);
        Assert::same($record->state, BlobState::PendingDelete);
        Assert::same($record->referenceCount, 0);
        Assert::same($record->reservationCount, 0);
        Assert::same($record->deleteAfter, $deleteAfter);
        Assert::null($record->leaseExpiresAt);
    }

    /**
     * A reservation keeps a blob alive exactly as a committed reference does:
     * an in-flight writer has nothing in the file table to speak for it yet.
     */
    #[DataProvider('countProvider')]
    public function isUnreferencedOnlyWhenBothCountsAreZero(
        int $references,
        int $reservations,
        bool $expected,
    ): void {
        $record = new BlobRecord(
            blob: BlobId::create('upload', 'a/b/original'),
            contentHash: self::HASH,
            size: 0,
            state: BlobState::Active,
            referenceCount: $references,
            reservationCount: $reservations,
        );

        Assert::same($record->isUnreferenced(), $expected);
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function countProvider(): iterable
    {
        yield 'nothing holds it' => [0, 0, true];
        yield 'a committed row holds it' => [1, 0, false];
        yield 'a writer holds it' => [0, 1, false];
        yield 'both hold it' => [2, 3, false];
    }

    #[DataProvider('invalidHashProvider')]
    public function rejectsAHashThatIsNotSha256Hex(string $hash): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('Invalid SHA-256 content hash');

        new BlobRecord(
            blob: BlobId::create('upload', 'a/b/original'),
            contentHash: $hash,
            size: 0,
            state: BlobState::Active,
            referenceCount: 0,
            reservationCount: 0,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHashProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 63)];
        yield 'uppercase' => [strtoupper(self::HASH)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'a trailing newline' => [self::HASH . "\n"];
        // the `\z` anchor alone would accept this: the pattern needs both ends
        yield 'a prefix before valid hex' => ['xx' . self::HASH];
    }

    public function rejectsANegativeSize(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('size must not be negative');

        new BlobRecord(
            blob: BlobId::create('upload', 'a/b/original'),
            contentHash: self::HASH,
            size: -1,
            state: BlobState::Active,
            referenceCount: 0,
            reservationCount: 0,
        );
    }

    /**
     * A negative counter means the ledger underflowed somewhere, and the
     * snapshot refusing to represent it is what turns a silent leak into a
     * failure someone reads.
     */
    #[DataProvider('negativeCountProvider')]
    public function rejectsANegativeCounter(int $references, int $reservations): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('counters must not be negative');

        new BlobRecord(
            blob: BlobId::create('upload', 'a/b/original'),
            contentHash: self::HASH,
            size: 0,
            state: BlobState::Active,
            referenceCount: $references,
            reservationCount: $reservations,
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function negativeCountProvider(): iterable
    {
        yield 'references' => [-1, 0];
        yield 'reservations' => [0, -1];
    }
}
