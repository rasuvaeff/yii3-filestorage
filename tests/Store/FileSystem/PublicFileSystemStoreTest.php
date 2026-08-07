<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\PublicFileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Upload;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Files\FileHelper;

#[Test]
#[Covers(PublicFileSystemStore::class)]
final class PublicFileSystemStoreTest
{
    private string $root;
    private Psr17Factory $factory;
    private PublicFileSystemStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fs-public-' . bin2hex(random_bytes(8));
        $this->factory = new Psr17Factory();
        $this->store = new PublicFileSystemStore(
            new FileSystemStore('assets', $this->root, $this->factory),
            'https://cdn.example.com/uploads',
        );
    }

    #[AfterTest]
    public function tearDown(): void
    {
        if (is_dir($this->root)) {
            FileHelper::removeDirectory($this->root);
        }
    }

    public function buildsAPermanentUrlUnderTheConfiguredPrefix(): void
    {
        $file = $this->write('x');

        Assert::same(
            $this->store->publicUrl($file),
            'https://cdn.example.com/uploads/' . $file->relativePath,
        );
    }

    public function aTrailingSlashInTheBaseUrlIsNormalisedAway(): void
    {
        $store = new PublicFileSystemStore(
            new FileSystemStore('assets', $this->root, $this->factory),
            'https://cdn.example.com/uploads///',
        );
        $file = $this->write('x');

        Assert::same($store->publicUrl($file), 'https://cdn.example.com/uploads/' . $file->relativePath);
    }

    /**
     * Path segments are percent-encoded, but the separators stay separators —
     * encoding the slashes would produce a URL pointing at a single file whose
     * name happens to contain them.
     */
    public function pathSegmentsAreEncodedWithoutEatingTheSeparators(): void
    {
        $file = $this->file('docs/a b/c+d/original.txt');

        Assert::same(
            $this->store->publicUrl($file),
            'https://cdn.example.com/uploads/docs/a%20b/c%2Bd/original.txt',
        );
    }

    /**
     * Always null, and always will be: a web server has no signature to check,
     * so there is no such thing as a filesystem presigned URL. Saying so
     * honestly is what lets `urlFor()` fall through to the proxy instead of
     * handing out a permanent URL that only looks temporary.
     */
    public function thereIsNoSuchThingAsAFilesystemPresignedUrl(): void
    {
        $file = $this->write('x');
        $options = DeliveryOptions::fromFile($file, new DeliveryPolicy());

        Assert::null($this->store->temporaryUrl($file, new DateTimeImmutable('+1 hour'), $options));
        Assert::null($this->store->temporaryDerivativeUrl(
            $file,
            new DerivativeDescriptor('thumb', 'webp', 'image/webp'),
            new DateTimeImmutable('+1 hour'),
        ));
    }

    public function aDerivativeUrlPointsBesideTheOriginal(): void
    {
        $file = $this->write('x');

        Assert::same(
            $this->store->publicDerivativeUrl($file, new DerivativeDescriptor('thumb', 'webp', 'image/webp')),
            'https://cdn.example.com/uploads/' . $file->directory() . '/thumb.webp',
        );
    }

    public function anEmptyBaseUrlIsRejected(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('baseUrl must not be empty');

        new PublicFileSystemStore(new FileSystemStore('assets', $this->root, $this->factory), '/');
    }

    /**
     * Composition rather than inheritance, so every base and capability method
     * still has to reach the inner store.
     */
    public function everyDelegatedOperationReachesTheInnerStore(): void
    {
        Assert::same($this->store->name(), 'assets');

        $file = $this->write('0123456789');

        Assert::true($this->store->exists($file));
        Assert::same($this->store->size($file), 10);
        Assert::same($this->store->stream($file)?->getContents(), '0123456789');
        Assert::instanceOf($this->store->lastModified($file), DateTimeImmutable::class);
        Assert::same($this->store->streamRange($file, 2, 3)?->getContents(), '234');

        $thumb = new DerivativeDescriptor('thumb', 'webp', 'image/webp');
        Assert::false($this->store->hasDerivative($file, $thumb));
        $this->store->writeDerivative($file, $thumb, $this->factory->createStream('rendition'));
        Assert::true($this->store->hasDerivative($file, $thumb));
        Assert::same($this->store->derivativeStream($file, $thumb)?->getContents(), 'rendition');

        $paths = array_map(
            static fn(StoredObjectId $id): string => $id->relativePath,
            iterator_to_array($this->store->objects(), false),
        );
        Assert::same(count($paths), 2, 'the original and its derivative');

        $this->store->deleteObject(new StoredObjectId($file->relativePath));
        Assert::false($this->store->exists($file));

        $this->store->delete($file);
        Assert::false($this->store->hasDerivative($file, $thumb));
    }

    private function write(string $body): File
    {
        $result = $this->store->write(
            Upload::fromStream($this->factory->createStream($body), 'thing.txt', $this->factory),
            'docs',
            new RandomPathGenerator(),
            'text/plain',
        );

        return $this->file($result->relativePath);
    }

    private function file(string $relativePath): File
    {
        return File::create(
            id: 'f-' . bin2hex(random_bytes(4)),
            storeName: 'assets',
            groupName: 'docs',
            relativePath: $relativePath,
            originalName: 'thing.txt',
            size: 0,
            createdAt: new DateTimeImmutable('2026-08-06T12:00:00.000000+00:00'),
        );
    }
}
