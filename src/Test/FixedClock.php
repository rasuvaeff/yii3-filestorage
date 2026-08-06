<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Test;

use DateInterval;
use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A clock that only moves when a test moves it.
 *
 * Not `readonly`, unlike almost everything else in this package: the whole
 * point is to advance it, and expiry, ordering and TTL tests are unreliable
 * against a real clock.
 *
 * Shipped in `src/` rather than `tests/` on purpose — `.gitattributes`
 * export-ignores `tests/`, so a consumer installing this package would not
 * receive it.
 *
 * @api
 */
final class FixedClock implements ClockInterface
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
