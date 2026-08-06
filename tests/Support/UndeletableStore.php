<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store that accepts writes and refuses deletes — the orphan-producing
 * failure {@see \Rasuvaeff\Yii3Filestorage\Storage::remove()} has to report.
 *
 * @internal
 */
final readonly class UndeletableStore implements StoreInterface
{
    public function __construct(private InMemoryStore $inner) {}

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
        throw new StoreException('the object store is unreachable');
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
