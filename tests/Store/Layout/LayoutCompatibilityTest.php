<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\Layout;

use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Path\Sha256KeyGenerator;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\LayoutCompatibility;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Review 1.0 A1: the key generator and the layout are configured in two
 * places, and a mismatch deletes other files' bytes.
 */
#[Test]
#[Covers(LayoutCompatibility::class)]
final class LayoutCompatibilityTest
{
    #[DataProvider('soundContentPairProvider')]
    public function aSoundContentAddressedPairHasNoProblem(
        ContentAddressedKeyGeneratorInterface $keys,
        ObjectLayoutInterface $layout,
    ): void {
        Assert::null(LayoutCompatibility::contentKeysProblem($keys, $layout));
    }

    public static function soundContentPairProvider(): iterable
    {
        yield 'directory keys, directory layout' => [new Sha256KeyGenerator(), new DirectoryLayout()];
        yield 'directory keys, flat layout' => [new Sha256KeyGenerator(), new FlatSiblingLayout()];
        yield 'flat keys, flat layout' => [new FlatSha256KeyGenerator(), new FlatSiblingLayout()];
    }

    public function flatKeysUnderTheDirectoryLayoutAreAProblem(): void
    {
        $problem = LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(), new DirectoryLayout());

        Assert::string((string) $problem)->contains('cannot share a store');
        Assert::string((string) $problem)->contains('needs FlatSiblingLayout');
    }

    /**
     * The probe does not depend on the layout refusing: a third-party layout
     * that deletes a flat key's directory is caught by what it would delete.
     */
    public function aLayoutThatWouldDeleteANeighbourIsAProblem(): void
    {
        $problem = LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(), $this->bucketLayout());

        Assert::string((string) $problem)->contains('would also remove "probe/ab/cd/abcd1111');
    }

    public function aLayoutGivingTwoFilesOneDerivativeIsAProblem(): void
    {
        $layout = new class implements ObjectLayoutInterface {
            #[Override]
            public function derivativePath(File $file, DerivativeDescriptor $derivative): string
            {
                return 'shared/' . $derivative->fileName();
            }

            #[Override]
            public function ownerOf(StoredObjectId $object): string
            {
                return $object->relativePath;
            }

            #[Override]
            public function deletion(StoredObjectId $object): ObjectSet
            {
                return ObjectSet::tree($object);
            }
        };

        $problem = LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(), $layout);

        Assert::string((string) $problem)->contains('get the same derivative path "shared/probe.webp"');
    }

    public function aLayoutThatLeavesTheDerivativeBehindIsAProblem(): void
    {
        $layout = new class implements ObjectLayoutInterface {
            #[Override]
            public function derivativePath(File $file, DerivativeDescriptor $derivative): string
            {
                return 'previews/' . basename($file->relativePath) . '-' . $derivative->fileName();
            }

            #[Override]
            public function ownerOf(StoredObjectId $object): string
            {
                return $object->relativePath;
            }

            #[Override]
            public function deletion(StoredObjectId $object): ObjectSet
            {
                return ObjectSet::tree($object);
            }
        };

        $problem = LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(), $layout);

        Assert::string((string) $problem)->contains('is not deleted with it');
    }

    public function aLayoutThatDoesNotDeleteTheKeyItselfIsAProblem(): void
    {
        $layout = new class implements ObjectLayoutInterface {
            #[Override]
            public function derivativePath(File $file, DerivativeDescriptor $derivative): string
            {
                return $file->relativePath . '-' . $derivative->fileName();
            }

            #[Override]
            public function ownerOf(StoredObjectId $object): string
            {
                return $object->relativePath;
            }

            #[Override]
            public function deletion(StoredObjectId $object): ObjectSet
            {
                return ObjectSet::tree(new StoredObjectId('elsewhere/' . $object->relativePath));
            }
        };

        Assert::string((string) LayoutCompatibility::contentKeysProblem(new FlatSha256KeyGenerator(), $layout))
            ->contains('would not remove it');
    }

    public function aGeneratorIgnoringTheContentIsAProblem(): void
    {
        $keys = new class implements ContentAddressedKeyGeneratorInterface {
            #[Override]
            public function generate(string $scope, string $sha256, ?string $extension = null): string
            {
                return 'constant/original';
            }
        };

        Assert::string((string) LayoutCompatibility::contentKeysProblem($keys, new DirectoryLayout()))
            ->contains('returned "constant/original" for two different objects');
    }

    public function aGeneratorRefusingTheProbeIsAProblem(): void
    {
        $keys = new class implements ContentAddressedKeyGeneratorInterface {
            #[Override]
            public function generate(string $scope, string $sha256, ?string $extension = null): string
            {
                throw new \InvalidArgumentException('no');
            }
        };

        Assert::string((string) LayoutCompatibility::contentKeysProblem($keys, new DirectoryLayout()))
            ->contains('refused a probe digest: no');
    }

    public function theAssertionNamesTheStore(): void
    {
        Expect::exception(InvalidConfigException::class)->withMessageContaining('Store "blobs": Content-addressed key generator');

        LayoutCompatibility::assertContentKeys(
            new FlatSha256KeyGenerator(),
            new InMemoryStore('blobs', new Psr17Factory()),
        );
    }

    #[\Testo\Assert\ExpectNoAssertions]
    public function theAssertionPassesASoundPair(): void
    {
        LayoutCompatibility::assertContentKeys(
            new FlatSha256KeyGenerator(),
            new InMemoryStore('blobs', new Psr17Factory(), layout: new FlatSiblingLayout()),
        );
        LayoutCompatibility::assertPaths(
            new RandomPathGenerator(),
            new InMemoryStore('blobs', new Psr17Factory()),
            new Psr17Factory(),
        );
    }

    #[DataProvider('layoutProvider')]
    public function theRandomPathGeneratorFitsBothShippedLayouts(ObjectLayoutInterface $layout): void
    {
        Assert::null(LayoutCompatibility::pathsProblem(new RandomPathGenerator(), $layout, new Psr17Factory()));
    }

    public static function layoutProvider(): iterable
    {
        yield 'directory' => [new DirectoryLayout()];
        yield 'flat' => [new FlatSiblingLayout()];
    }

    public function aPathGeneratorEmittingBareNamesUnderTheDirectoryLayoutIsAProblem(): void
    {
        $paths = new class implements PathGeneratorInterface {
            #[Override]
            public function generate(string $groupName, Upload $upload, ?string $mediaType): string
            {
                return $groupName . '/' . bin2hex(random_bytes(8)) . '.jpg';
            }
        };

        Expect::exception(InvalidConfigException::class)->withMessageContaining('Store "memory": Path generator');

        LayoutCompatibility::assertPaths($paths, new InMemoryStore('memory', new Psr17Factory()), new Psr17Factory());
    }

    public function aPathGeneratorRefusingTheProbeIsAProblem(): void
    {
        $paths = new class implements PathGeneratorInterface {
            #[Override]
            public function generate(string $groupName, Upload $upload, ?string $mediaType): string
            {
                throw new \InvalidArgumentException('no');
            }
        };

        Assert::string((string) LayoutCompatibility::pathsProblem($paths, new DirectoryLayout(), new Psr17Factory()))
            ->contains('refused a probe upload: no');
    }

    private function bucketLayout(): ObjectLayoutInterface
    {
        return new class implements ObjectLayoutInterface {
            #[Override]
            public function derivativePath(File $file, DerivativeDescriptor $derivative): string
            {
                return $file->directory() . '/' . $derivative->fileName();
            }

            #[Override]
            public function ownerOf(StoredObjectId $object): string
            {
                return $object->directory();
            }

            #[Override]
            public function deletion(StoredObjectId $object): ObjectSet
            {
                return ObjectSet::tree(new StoredObjectId($object->directory()));
            }
        };
    }
}
