<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(StoreResult::class)]
final class StoreResultTest
{
    public function exposesWhatTheStoreWrote(): void
    {
        $result = new StoreResult('a/b/original.png', 42, 'bucket/key');

        Assert::same($result->relativePath, 'a/b/original.png');
        Assert::same($result->size, 42);
        Assert::same($result->externalId, 'bucket/key');
        Assert::same($result->created, expected: true);
    }

    /**
     * An ordinary write always creates, which is what makes compensation safe:
     * only a content-addressed put can report a reuse.
     */
    public function createdDefaultsToTrue(): void
    {
        Assert::true((new StoreResult('a', 0))->created);
    }

    public function reuseIsReportedExplicitly(): void
    {
        Assert::false((new StoreResult('a', 5, created: false))->created);
    }

    public function anAbsentExternalIdIsNull(): void
    {
        Assert::null((new StoreResult('a', 0))->externalId);
    }

    public function aZeroByteObjectIsValid(): void
    {
        Assert::same((new StoreResult('a', 0))->size, 0);
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
            static fn(): StoreResult => new StoreResult('', 1),
        ];
        yield 'empty external id' => [
            'externalId must be null or non-empty',
            static fn(): StoreResult => new StoreResult('a', 1, ''),
        ];
        yield 'negative size' => [
            'size must not be negative',
            static fn(): StoreResult => new StoreResult('a', -1),
        ];
    }
}
