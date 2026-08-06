<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Path;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Path\DatePathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Test\FixedClock;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(DatePathGenerator::class)]
final class DatePathGeneratorTest
{
    private Upload $upload;
    private FixedClock $clock;
    private DatePathGenerator $generator;

    #[BeforeTest]
    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->upload = Upload::fromStream($factory->createStream('x'), 'thing.bin', $factory);
        $this->clock = new FixedClock('2026-08-06T12:00:00.000000+00:00');
        $this->generator = new DatePathGenerator($this->clock, new Uuid7IdGenerator($this->clock));
    }

    public function producesGroupYearMonthDayKeyDirectoryAndObject(): void
    {
        $path = $this->generator->generate('reports', $this->upload, 'application/pdf');

        Assert::same(
            preg_match(
                '~^reports/2026/08/06/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/original\.pdf\z~',
                $path,
            ),
            1,
            $path,
        );
    }

    /**
     * Date segments come from the injected clock, never from an ambient one —
     * that is what makes archiving by period predictable and the test
     * deterministic.
     */
    public function theDateSegmentsFollowTheInjectedClock(): void
    {
        $this->clock->set(new \DateTimeImmutable('2030-01-09T23:59:59.000000+00:00'));

        Assert::true(str_starts_with($this->generator->generate('g', $this->upload, null), 'g/2030/01/09/'));
    }

    public function uniquenessComesFromTheIdNotTheDate(): void
    {
        $first = $this->generator->generate('g', $this->upload, null);
        $second = $this->generator->generate('g', $this->upload, null);

        Assert::true($first !== $second);
    }

    public function anUnknownMediaTypeBecomesAnInertBinObject(): void
    {
        Assert::true(str_ends_with($this->generator->generate('g', $this->upload, null), '/original.bin'));
    }

    public function everyGeneratedPathIsAValidStoredObjectId(): void
    {
        $path = $this->generator->generate('g', $this->upload, 'image/png');

        Assert::same((new StoredObjectId($path))->relativePath, $path);
    }
}
