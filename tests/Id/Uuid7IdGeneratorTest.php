<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Id;

use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Tests\Support\MovableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(Uuid7IdGenerator::class)]
final class Uuid7IdGeneratorTest
{
    private MovableClock $clock;
    private Uuid7IdGenerator $generator;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->clock = new MovableClock('2026-08-06T12:00:00.000000+00:00');
        $this->generator = new Uuid7IdGenerator($this->clock);
    }

    public function producesACanonicalUuidString(): void
    {
        Assert::same(
            preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~', $this->generator->generate()),
            1,
        );
    }

    /**
     * RFC 9562 pins version 7 in the high nibble of byte 6 and variant `10` in
     * the top bits of byte 8. Asserting the exact layout is what catches a
     * neighbouring byte index — which would still look like a UUID.
     */
    public function layoutFollowsRfc9562(): void
    {
        $hex = str_replace('-', '', $this->generator->generate());

        Assert::same($hex[12], '7', 'version nibble');
        Assert::true(\in_array($hex[16], ['8', '9', 'a', 'b'], true), 'variant nibble');
    }

    /**
     * The leading timestamp is the reason for choosing v7: ids sort by creation
     * time, so inserts cluster at the right edge of an index.
     */
    public function theTimestampPrefixComesFromTheInjectedClock(): void
    {
        $milliseconds = (int) $this->clock->now()->format('Uv');
        $hex = str_replace('-', '', $this->generator->generate());

        Assert::same(substr($hex, 0, 12), bin2hex(substr(pack('J', $milliseconds), 2, 6)));
    }

    public function laterIdsSortAfterEarlierOnes(): void
    {
        $first = $this->generator->generate();
        $this->clock->advanceSeconds(1);
        $second = $this->generator->generate();

        Assert::true(strcmp($first, $second) < 0);
    }

    /**
     * Within one millisecond ordering is undefined by design, but the ids must
     * still differ — 74 bits of randomness, not a counter.
     */
    public function idsWithinTheSameMillisecondStillDiffer(): void
    {
        Assert::true($this->generator->generate() !== $this->generator->generate());
    }
}
