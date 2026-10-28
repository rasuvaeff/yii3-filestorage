<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Test;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * The URL-capable variant of {@see InMemoryStore}, by composition — so tests
 * can model "store without URLs" accurately with the base double and this one
 * only where the URL branching itself is under test.
 *
 * The URL surface is deliberately toggleable: a test can turn public or
 * temporary URLs off (`publicUrls: false`) and exercise the fallback ladder
 * of `Storage::urlFor()` — public, then temporary, then the proxy — without
 * a real object store.
 *
 * @api
 */
final class UrlCapableInMemoryStore implements MaintenanceStoreInterface, StoreUrlProviderInterface
{
    public function __construct(
        private readonly InMemoryStore $inner,
        private readonly bool $publicUrls = true,
        private readonly bool $temporaryUrls = true,
    ) {}

    #[Override]
    public function publicUrl(File $file): ?string
    {
        return $this->publicUrls
            ? "https://public.example.test/{$file->storeName}/{$file->relativePath}"
            : null;
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string
    {
        return $this->temporaryUrls
            ? 'https://signed.example.test/' . $file->relativePath . '?expires=' . $expiresAt->getTimestamp()
            : null;
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

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        return $this->inner->objects($afterPath, $limit);
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        $this->inner->deleteObject($object);
    }
}
