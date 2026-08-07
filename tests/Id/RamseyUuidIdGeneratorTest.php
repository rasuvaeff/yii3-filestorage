<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Id;

use Rasuvaeff\Yii3Filestorage\Id\RamseyUuidIdGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RamseyUuidIdGenerator::class)]
final class RamseyUuidIdGeneratorTest
{
    /**
     * `ramsey/uuid` is an optional dependency; see
     * {@see \Rasuvaeff\Yii3Filestorage\Id\SymfonyUidIdGenerator} for why there
     * is no auto-detection between the two.
     */
    public function producesACanonicalUuidV7(): void
    {
        $id = (new RamseyUuidIdGenerator())->generate();

        Assert::same(
            preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z~', $id),
            1,
        );
    }

    public function successiveIdsDiffer(): void
    {
        $generator = new RamseyUuidIdGenerator();

        Assert::true($generator->generate() !== $generator->generate());
    }
}
