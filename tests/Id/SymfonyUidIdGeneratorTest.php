<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Id;

use Rasuvaeff\Yii3Filestorage\Id\SymfonyUidIdGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(SymfonyUidIdGenerator::class)]
final class SymfonyUidIdGeneratorTest
{
    /**
     * `symfony/uid` is an optional dependency, so this adapter only exists for
     * applications already standardised on it. Nothing selects it
     * automatically — the default generator has no dependency at all.
     */
    public function producesACanonicalUuidV7(): void
    {
        $id = (new SymfonyUidIdGenerator())->generate();

        Assert::same(
            preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z~', $id),
            1,
        );
    }

    public function successiveIdsDiffer(): void
    {
        $generator = new SymfonyUidIdGenerator();

        Assert::true($generator->generate() !== $generator->generate());
    }
}
