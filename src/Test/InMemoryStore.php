<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Test;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Objects in an array, so a consumer can test an upload flow without a disk.
 *
 * Only the base contract plus maintenance. URL support, range reads and
 * content-addressed writes are deliberately *not* implemented here: a fake
 * that implements every capability makes it impossible to test what an
 * application does when a store cannot presign, which is the branch most likely
 * to be wrong. Model "unsupported" by using this store; model "supported" by
 * wrapping it in a double of your own.
 *
 * @api
 */
final class InMemoryStore implements MaintenanceStoreInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';

    /** @var array<non-empty-string, string> Relative path => bytes. */
    private array $objects = [];

    /** @var array<non-empty-string, DateTimeImmutable> */
    private array $timestamps = [];

    /** @var int<0, max> */
    private int $writes = 0;

    /** @var non-empty-string */
    private readonly string $name;

    /**
     * @param ClockInterface|null $clock Pin object timestamps. Any PSR-20 clock
     *        works — `Yiisoft\Test\Support\Clock\StaticClock` is the obvious
     *        one in a Yii application. Without it the system clock is used.
     */
    public function __construct(
        string $name,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid store name \"{$name}\"");
        }

        $this->name = $name;
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        $object = new StoredObjectId($pathGenerator->generate($groupName, $upload, $mediaType));
        if (isset($this->objects[$object->relativePath])) {
            throw new StoreException(
                "Generated path \"{$object->relativePath}\" already exists in store \"{$this->name}\"",
            );
        }

        $stream = $upload->stream();
        $bytes = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                throw new StoreException("Could not read \"{$object->relativePath}\" before EOF");
            }

            $bytes .= $chunk;
            if ($maxBytes > 0 && \strlen($bytes) > $maxBytes) {
                // Nothing is stored, exactly as a real store removes its
                // partial output rather than leaving it behind.
                throw new UploadTooLargeException(
                    "Upload exceeds the {$maxBytes} byte limit for this group",
                );
            }
        }

        $this->objects[$object->relativePath] = $bytes;
        $this->timestamps[$object->relativePath] = $this->clock?->now() ?? new DateTimeImmutable();
        ++$this->writes;

        return new StoreResult(relativePath: $object->relativePath, size: \strlen($bytes));
    }

    #[Override]
    public function delete(File $file): void
    {
        $prefix = $file->directory() . '/';
        foreach (array_keys($this->objects) as $path) {
            if ($path === $file->directory() || str_starts_with($path, $prefix)) {
                unset($this->objects[$path], $this->timestamps[$path]);
            }
        }
    }

    #[Override]
    public function exists(File $file): bool
    {
        return isset($this->objects[$file->relativePath]);
    }

    #[Override]
    public function size(File $file): ?int
    {
        $bytes = $this->objects[$file->relativePath] ?? null;

        return $bytes === null ? null : \strlen($bytes);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->timestamps[$file->relativePath] ?? null;
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        $bytes = $this->objects[$file->relativePath] ?? null;

        return $bytes === null ? null : $this->streamFactory->createStream($bytes);
    }

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        $paths = array_keys($this->objects);
        sort($paths);

        $yielded = 0;
        foreach ($paths as $path) {
            if ($afterPath !== null && strcmp($path, $afterPath) <= 0) {
                continue;
            }

            yield new StoredObjectId($path);

            if (++$yielded >= $limit) {
                return;
            }
        }
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        unset($this->objects[$object->relativePath], $this->timestamps[$object->relativePath]);
    }

    /**
     * @param non-empty-string $relativePath
     */
    public function bytesAt(string $relativePath): ?string
    {
        return $this->objects[$relativePath] ?? null;
    }

    /**
     * @return list<non-empty-string>
     */
    public function paths(): array
    {
        return array_keys($this->objects);
    }

    /**
     * How many times {@see self::write()} completed — the assertion that catches
     * a facade writing twice, or writing before it rejected an upload.
     *
     * @return int<0, max>
     */
    public function writeCount(): int
    {
        return $this->writes;
    }

    /**
     * Simulates an object disappearing behind the package's back, which is what
     * `verify` and the `content()` cap exist to cope with.
     *
     * @param non-empty-string $relativePath
     */
    public function corrupt(string $relativePath, string $bytes): void
    {
        $this->objects[$relativePath] = $bytes;
    }

    public function clear(): void
    {
        $this->objects = [];
        $this->timestamps = [];
        $this->writes = 0;
    }

    private const int CHUNK = 262_144;
}
