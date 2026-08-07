<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A local store whose root is also served by the web server.
 *
 * Composition rather than inheritance, because {@see FileSystemStore} is final
 * and the difference between the two really is one added capability, not a
 * changed one.
 *
 * `temporaryUrl()` always returns null and always will: a web server has no
 * signature to check, so there is no such thing as a filesystem presigned URL.
 * Saying so honestly is what lets `urlFor()` fall through to the application
 * proxy instead of handing out a permanent URL that only looks temporary.
 *
 * A permanent URL is still only *used* when the group's
 * {@see \Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy} allows it, and that
 * is off by default: everything under this root is readable by anyone who
 * guesses the path, which is exactly what the 128-bit key in the path
 * generators is there to prevent — but "unguessable" is not "access
 * controlled", and only the application knows whether that is enough.
 *
 * @api
 */
final readonly class PublicFileSystemStore implements
    DerivativeUrlProviderInterface,
    MaintenanceStoreInterface,
    RangeReadableStoreInterface,
    StoreUrlProviderInterface
{
    /** @var non-empty-string Without a trailing slash. */
    private string $baseUrl;

    /**
     * @param non-empty-string $baseUrl Public prefix the root is served under,
     *        e.g. `https://cdn.example.com/uploads`.
     */
    public function __construct(private FileSystemStore $store, string $baseUrl)
    {
        $trimmed = rtrim($baseUrl, '/');
        if ($trimmed === '') {
            throw new InvalidArgumentException('baseUrl must not be empty');
        }

        $this->baseUrl = $trimmed;
    }

    #[Override]
    public function publicUrl(File $file): string
    {
        return $this->baseUrl . '/' . $this->encodePath($file->relativePath);
    }

    /**
     * Always null: see the class docblock.
     */
    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string
    {
        return null;
    }

    #[Override]
    public function publicDerivativeUrl(File $file, DerivativeDescriptor $derivative): string
    {
        return $this->baseUrl . '/' . $this->encodePath($file->directory() . '/' . $derivative->fileName());
    }

    /**
     * Always null: see the class docblock.
     */
    #[Override]
    public function temporaryDerivativeUrl(
        File $file,
        DerivativeDescriptor $derivative,
        DateTimeImmutable $expiresAt,
    ): ?string {
        return null;
    }

    #[Override]
    public function name(): string
    {
        return $this->store->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->store->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->store->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->store->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->store->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->store->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->store->stream($file);
    }

    #[Override]
    public function streamRange(File $file, int $offset, int $length): ?StreamInterface
    {
        return $this->store->streamRange($file, $offset, $length);
    }

    #[Override]
    public function hasDerivative(File $file, DerivativeDescriptor $derivative): bool
    {
        return $this->store->hasDerivative($file, $derivative);
    }

    #[Override]
    public function derivativeStream(File $file, DerivativeDescriptor $derivative): ?StreamInterface
    {
        return $this->store->derivativeStream($file, $derivative);
    }

    #[Override]
    public function writeDerivative(
        File $file,
        DerivativeDescriptor $derivative,
        StreamInterface $contents,
        int $maxBytes = 0,
    ): DerivativeObject {
        return $this->store->writeDerivative($file, $derivative, $contents, $maxBytes);
    }

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        return $this->store->objects($afterPath, $limit);
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        $this->store->deleteObject($object);
    }

    /**
     * @return non-empty-string
     */
    private function encodePath(string $relativePath): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $relativePath)));
    }
}
