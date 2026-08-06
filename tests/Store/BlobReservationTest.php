<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\Store\BlobId;
use Rasuvaeff\Yii3Filestorage\Store\BlobReservation;
use Rasuvaeff\Yii3Filestorage\Store\BlobToken;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(BlobReservation::class)]
final class BlobReservationTest
{
    public function carriesTheBlobTokenAndDeadline(): void
    {
        $expiresAt = new DateTimeImmutable('2026-01-01T00:10:00+00:00');
        $reservation = new BlobReservation(
            blob: BlobId::create('upload', 'a/b/original'),
            token: new BlobToken('abcd1234'),
            expiresAt: $expiresAt,
        );

        Assert::same($reservation->blob->key(), 'upload:a/b/original');
        Assert::same($reservation->token->value, 'abcd1234');
        Assert::same($reservation->expiresAt, $expiresAt);
    }

    /**
     * The boundary is inclusive: a reservation whose deadline is exactly now no
     * longer protects the blob, so a collector running at that instant is not
     * blocked by a claim that has run out.
     */
    public function expiresAtItsDeadlineNotAfterIt(): void
    {
        $reservation = new BlobReservation(
            blob: BlobId::create('upload', 'a/b/original'),
            token: new BlobToken('abcd1234'),
            expiresAt: new DateTimeImmutable('2026-01-01T00:10:00+00:00'),
        );

        Assert::false($reservation->isExpired(new DateTimeImmutable('2026-01-01T00:09:59+00:00')));
        Assert::true($reservation->isExpired(new DateTimeImmutable('2026-01-01T00:10:00+00:00')));
        Assert::true($reservation->isExpired(new DateTimeImmutable('2026-01-01T00:10:01+00:00')));
    }
}
