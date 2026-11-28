<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A presigning store that remembers the delivery options it was asked to sign
 * with — what an S3 store turns into `ResponseContentDisposition`.
 *
 * @internal
 */
final class RecordingUrlStore implements StoreInterface, StoreUrlProviderInterface
{
    public ?DeliveryOptions $lastOptions = null;

    public function __construct(private readonly InMemoryStore $inner) {}

    #[Override]
    public function publicUrl(File $file): ?string
    {
        return null;
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): string
    {
        $this->lastOptions = $options;

        return 'https://signed.example.test/' . $file->relativePath;
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->inner->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->inner->layout();
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->inner->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->inner->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->inner->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }
}
