<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A clock that moves when a test moves it.
 *
 * `Yiisoft\Test\Support\Clock\StaticClock` covers everything that only needs
 * time pinned, and it is what this package's tests use by default. This exists
 * for the handful of cases where time has to advance *within* one test — token
 * expiry at the boundary, key rotation, id ordering — which a static clock
 * cannot express without rebuilding the object under test.
 *
 * Test-only, deliberately: consumers who need a movable clock have their own,
 * and this package should not ship a second one next to `yiisoft/test-support`.
 *
 * @internal
 */
final class MovableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = '2026-01-01T00:00:00.000000+00:00')
    {
        $this->now = \is_string($now) ? new DateTimeImmutable($now) : $now;
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function advance(DateInterval $interval): void
    {
        $this->now = $this->now->add($interval);
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '-') . abs($seconds) . ' seconds');
    }
}
