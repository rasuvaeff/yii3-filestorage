<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Test;

use DateInterval;
use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Test\FixedClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(FixedClock::class)]
final class FixedClockTest
{
    public function staysWhereItWasPutUntilItIsMoved(): void
    {
        $clock = new FixedClock('2026-08-06T12:00:00.123456+00:00');

        Assert::same($clock->now()->format(File::TIMESTAMP_FORMAT), '2026-08-06T12:00:00.123456+00:00');
        Assert::same($clock->now(), $clock->now());
    }

    public function acceptsAnInstanceAsWellAsAString(): void
    {
        $now = new DateTimeImmutable('2030-01-01T00:00:00.000000+00:00');

        Assert::same((new FixedClock($now))->now(), $now);
    }

    public function setJumpsToAnExactMoment(): void
    {
        $clock = new FixedClock();
        $target = new DateTimeImmutable('2030-06-15T08:30:00.000000+00:00');

        $clock->set($target);

        Assert::same($clock->now(), $target);
    }

    public function advanceMovesByAnInterval(): void
    {
        $clock = new FixedClock('2026-08-06T12:00:00.000000+00:00');

        $clock->advance(new DateInterval('PT90M'));

        Assert::same($clock->now()->format(File::TIMESTAMP_FORMAT), '2026-08-06T13:30:00.000000+00:00');
    }

    public function advanceSecondsMovesForwards(): void
    {
        $clock = new FixedClock('2026-08-06T12:00:00.000000+00:00');

        $clock->advanceSeconds(61);

        Assert::same($clock->now()->format(File::TIMESTAMP_FORMAT), '2026-08-06T12:01:01.000000+00:00');
    }

    /**
     * Going backwards is what an expiry test needs to check the boundary from
     * both sides without rebuilding the fixture.
     */
    public function advanceSecondsAlsoMovesBackwards(): void
    {
        $clock = new FixedClock('2026-08-06T12:00:00.000000+00:00');

        $clock->advanceSeconds(-30);

        Assert::same($clock->now()->format(File::TIMESTAMP_FORMAT), '2026-08-06T11:59:30.000000+00:00');
    }

    public function microsecondsSurviveEveryMovement(): void
    {
        $clock = new FixedClock('2026-08-06T12:00:00.654321+00:00');

        $clock->advanceSeconds(1);

        Assert::same($clock->now()->format(File::TIMESTAMP_FORMAT), '2026-08-06T12:00:01.654321+00:00');
    }
}
