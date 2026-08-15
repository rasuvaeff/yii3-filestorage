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
        Assert::true(\in_array($hex[16], ['8', '9', 'a', 'b'], strict: true), 'variant nibble');
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

    /**
     * The version and variant nibbles are written into byte 6 and byte 8, and
     * the *rest* of those bytes stays random. Reading a neighbouring index
     * would still produce something that looks like a UUIDv7, so this checks
     * the two bytes carry independent entropy: perfect correlation between
     * them across many draws means both were built from the same source byte.
     */
    public function versionAndVariantBytesKeepTheirOwnRandomBits(): void
    {
        $versionLowBits = [];
        $variantLowBits = [];
        $neighbourLowBits = [];

        for ($i = 0; $i < 200; ++$i) {
            $bytes = hex2bin(str_replace('-', '', $this->generator->generate()));
            \assert($bytes !== false);

            $versionLowBits[] = \ord($bytes[6]) & 0x0F;
            $variantLowBits[] = \ord($bytes[8]) & 0x3F;
            // The neighbour, so "byte 6 was built from byte 7" is detectable
            // too — an off-by-one index still produces a plausible UUIDv7.
            $neighbourLowBits[] = \ord($bytes[7]);
        }

        // Four and six bits of entropy over 200 draws: anything close to one
        // distinct value means a mask ate the randomness rather than shaped it.
        Assert::true(count(array_unique($versionLowBits)) > 8, 'byte 6 keeps four random bits');
        Assert::true(count(array_unique($variantLowBits)) > 32, 'byte 8 keeps six random bits');

        foreach ([
            'byte 6 and byte 8 are not one byte read twice'
                => array_map(static fn(int $b): int => $b & 0x0F, $variantLowBits),
            'byte 6 does not come from byte 7'
                => array_map(static fn(int $b): int => $b & 0x0F, $neighbourLowBits),
        ] as $message => $other) {
            Assert::true($versionLowBits !== $other, $message);
        }

        Assert::true(
            $variantLowBits !== array_map(static fn(int $b): int => $b & 0x3F, $neighbourLowBits),
            'byte 8 does not come from byte 7',
        );
    }

    /**
     * The timestamp is the *first six* bytes. Taking a different slice of the
     * packed integer would still yield a plausible-looking id whose ordering is
     * meaningless, which is the one property v7 is chosen for.
     */
    public function theTimestampOccupiesExactlyTheFirstSixBytes(): void
    {
        $this->clock->set(new \DateTimeImmutable('@1000000'));
        $early = $this->generator->generate();

        $this->clock->set(new \DateTimeImmutable('@2000000'));
        $late = $this->generator->generate();

        // 1 000 000 s = 1 000 000 000 ms = 0x3B9ACA00, so the 48-bit
        // big-endian prefix is 00 00 3B 9A CA 00.
        Assert::same(substr($early, 0, 8), '00003b9a');
        // 2 000 000 s = 2 000 000 000 ms = 0x77359400.
        Assert::same(substr($late, 0, 8), '00007735');
        Assert::true(strcmp($early, $late) < 0);
    }
}
