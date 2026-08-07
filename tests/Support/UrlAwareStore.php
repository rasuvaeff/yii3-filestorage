<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store that can produce both kinds of URL, so `urlFor()`'s ordering and the
 * delivery options it passes down can be observed.
 *
 * `InMemoryStore` deliberately implements neither, which is what makes it
 * useful for the opposite case.
 *
 * @internal
 */
final class UrlAwareStore implements StoreUrlProviderInterface
{
    public ?DeliveryOptions $lastOptions = null;

    public function __construct(
        private readonly InMemoryStore $inner,
        private readonly string $baseUrl,
    ) {}

    #[Override]
    public function publicUrl(File $file): ?string
    {
        return $this->baseUrl . '/' . $file->relativePath;
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt, DeliveryOptions $options): ?string
    {
        $this->lastOptions = $options;

        return $this->baseUrl . '/temp/' . $file->relativePath;
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
}
