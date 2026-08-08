<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * The smallest honest decorator: it counts `add()` and delegates everything.
 * The README tells applications to write one of these and bind it to
 * `StorageInterface`, so the wiring test needs a real one — an identity factory
 * would resolve the same object under both ids and prove nothing about the
 * arrangement that makes decoration possible.
 *
 * Its constructor takes the interface, as a decorator's should. Only the
 * container names the concrete `Storage` id.
 *
 * @internal
 */
final class CountingStorage implements StorageInterface
{
    public int $added = 0;

    public function __construct(public readonly StorageInterface $inner) {}

    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        ++$this->added;

        return $this->inner->add($upload, $groupName, $storeName, $description, $metadata);
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->inner->find($id);
    }

    #[Override]
    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        return $this->inner->url($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->inner->temporaryUrl($file, $expiresAt);
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->inner->urlFor($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        return $this->inner->content($file);
    }
}
