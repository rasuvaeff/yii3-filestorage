<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Path;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3Filestorage\Mime\ExtensionMap;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RandomPathGenerator::class)]
final class RandomPathGeneratorTest
{
    private Psr17Factory $factory;
    private Upload $upload;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->upload = Upload::fromStream($this->factory->createStream('x'), 'thing.bin', $this->factory);
    }

    /**
     * The layout is frozen: a directory per file, so a derivative can be a
     * sibling and `delete()` can be one directory operation.
     */
    public function producesGroupTwoFanOutSegmentsAKeyDirectoryAndAnObject(): void
    {
        $path = (new RandomPathGenerator())->generate('avatars', $this->upload, 'image/png');

        Assert::same(
            preg_match('~^avatars/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{32}/original\.png\z~', $path),
            1,
            $path,
        );
    }

    public function theExtensionComesFromTheDetectedMediaType(): void
    {
        $generator = new RandomPathGenerator();

        Assert::true(str_ends_with($generator->generate('g', $this->upload, 'application/pdf'), '/original.pdf'));
        Assert::true(str_ends_with($generator->generate('g', $this->upload, 'text/plain'), '/original.txt'));
    }

    public function anUnknownMediaTypeBecomesAnInertBinObject(): void
    {
        Assert::true(
            str_ends_with((new RandomPathGenerator())->generate('g', $this->upload, null), '/original.bin'),
        );
    }

    public function extensionOverridesAreHonoured(): void
    {
        $generator = new RandomPathGenerator(new ExtensionMap(['image/jpeg' => 'jpeg']));

        Assert::true(str_ends_with($generator->generate('g', $this->upload, 'image/jpeg'), '/original.jpeg'));
    }

    public function everyGeneratedPathIsAValidStoredObjectId(): void
    {
        $path = (new RandomPathGenerator())->generate('avatars', $this->upload, 'image/png');

        Assert::same((new StoredObjectId($path))->relativePath, $path);
    }

    /**
     * Collision resistance is the whole contract: the base store treats an
     * existing path as an error, so a repeat would surface as a failed upload.
     * 128 bits makes that unreachable; this checks the generator actually uses
     * them rather than, say, a per-request seed.
     */
    #[Property(runs: 300)]
    public function distinctCallsProduceDistinctPaths(string $group): void
    {
        $generator = new RandomPathGenerator();

        $first = $generator->generate($group, $this->upload, 'image/png');
        $second = $generator->generate($group, $this->upload, 'image/png');

        Assert::true($first !== $second);
        Assert::true(str_starts_with($first, $group . '/'));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function distinctCallsProduceDistinctPathsGenerators(): array
    {
        return ['group' => Gen::stringFrom('abcdefghijklmnopqrstuvwxyz0123456789_-', 1, 20)];
    }
}
