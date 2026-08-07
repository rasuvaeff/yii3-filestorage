<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobLease;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BlobLease::class)]
final class BlobLeaseTest
{
    public function carriesTheBlobTokenAndDeadline(): void
    {
        $expiresAt = new DateTimeImmutable('2026-01-01T00:05:00+00:00');
        $lease = new BlobLease(
            blob: BlobId::create('upload', 'a/b/original'),
            token: new BlobToken('abcd1234'),
            expiresAt: $expiresAt,
        );

        Assert::same($lease->blob->key(), 'upload:a/b/original');
        Assert::same($lease->token->value, 'abcd1234');
        Assert::same($lease->expiresAt, $expiresAt);
    }

    /**
     * Inclusive, like a reservation's: at the deadline the lease is stealable,
     * which is the only way a collector that died mid-delete gets recovered.
     */
    public function expiresAtItsDeadlineNotAfterIt(): void
    {
        $lease = new BlobLease(
            blob: BlobId::create('upload', 'a/b/original'),
            token: new BlobToken('abcd1234'),
            expiresAt: new DateTimeImmutable('2026-01-01T00:05:00+00:00'),
        );

        Assert::false($lease->isExpired(new DateTimeImmutable('2026-01-01T00:04:59+00:00')));
        Assert::true($lease->isExpired(new DateTimeImmutable('2026-01-01T00:05:00+00:00')));
        Assert::true($lease->isExpired(new DateTimeImmutable('2026-01-01T00:05:01+00:00')));
    }
}
