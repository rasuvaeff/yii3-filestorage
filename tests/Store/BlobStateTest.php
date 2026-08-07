<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use Rasuvaeff\Yii3Filestorage\Store\BlobState;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(BlobState::class)]
final class BlobStateTest
{
    /**
     * The stored values are persisted in a ledger column, so renaming one is a
     * data migration rather than a refactor.
     */
    #[DataProvider('stateProvider')]
    public function keepsItsPersistedValue(BlobState $state, string $value): void
    {
        Assert::same($state->value, $value);
        Assert::same(BlobState::from($value), $state);
    }

    /**
     * @return iterable<string, array{BlobState, string}>
     */
    public static function stateProvider(): iterable
    {
        yield 'writing' => [BlobState::Writing, 'writing'];
        yield 'active' => [BlobState::Active, 'active'];
        yield 'pending delete' => [BlobState::PendingDelete, 'pending_delete'];
        yield 'deleting' => [BlobState::Deleting, 'deleting'];
    }

    /**
     * A writer may join anything except a blob a collector is already removing:
     * joining that one produces a committed row pointing at deleted bytes.
     */
    public function onlyADeletingBlobRefusesNewWriters(): void
    {
        Assert::true(BlobState::Writing->isJoinable());
        Assert::true(BlobState::Active->isJoinable());
        Assert::true(BlobState::PendingDelete->isJoinable());
        Assert::false(BlobState::Deleting->isJoinable());
    }
}
