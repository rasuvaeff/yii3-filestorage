<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Stream\LimitedStream;
use Rasuvaeff\Yii3Filestorage\Upload;
use Yiisoft\Files\FileHelper;

/**
 * Objects on a local filesystem, private by default.
 *
 * Publishing is atomic: bytes go to a `.part` sibling inside the freshly
 * created key directory and are renamed into place only once the whole copy
 * succeeded, so a reader never sees a half-written file and a crash leaves an
 * orphan nobody references rather than a corrupt object somebody does.
 *
 * Staging inside the destination directory rather than in a shared temporary
 * directory is deliberate: `rename()` is only atomic within one filesystem, a
 * shared staging path is a predictable location a local attacker can aim at,
 * and the key directory here is freshly created under 128 bits of randomness,
 * so nothing can address the `.part` file before it is gone.
 *
 * Every path that is read is resolved with `realpath()` and checked to still be
 * under the root afterwards. A symlink planted inside the tree therefore cannot
 * be used to read `/etc/passwd` through a store operation, even though the
 * relative path itself was already validated.
 *
 * @api
 */
final readonly class FileSystemStore implements
    DerivativeAwareStoreInterface,
    MaintenanceStoreInterface,
    RangeReadableStoreInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';
    private const string PARTIAL_SUFFIX = '.part';
    private const int CHUNK = 262_144;

    /** @var non-empty-string */
    private string $name;

    /** @var non-empty-string Absolute, symlink-resolved. */
    private string $rootPath;

    /**
     * @param string $rootPath Created when missing.
     *
     * @throws StoreException
     */
    public function __construct(
        string $name,
        string $rootPath,
        private StreamFactoryInterface $streamFactory,
        private int $directoryMode = 0775,
    ) {
        if ($name === '' || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Invalid store name \"{$name}\"");
        }

        if (!is_dir($rootPath)) {
            try {
                FileHelper::ensureDirectory($rootPath, $directoryMode);
            } catch (\Throwable $e) {
                throw new StoreException(
                    "Store \"{$name}\" root \"{$rootPath}\" does not exist and could not be created. "
                    . 'Create it and make it writable by the web server user',
                    0,
                    $e,
                );
            }
        }

        $resolved = realpath($rootPath);
        if ($resolved === false || $resolved === '') {
            throw new StoreException("Store \"{$name}\" root \"{$rootPath}\" could not be resolved");
        }

        $this->name = $name;
        $this->rootPath = $resolved;
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
        $relativePath = $pathGenerator->generate($groupName, $upload, $mediaType);
        $object = new StoredObjectId($relativePath);
        $target = $this->rootPath . '/' . $object->relativePath;

        if (file_exists($target)) {
            // Never treat this as sharing: the caller asked for a new object,
            // and silently returning somebody else's would tie two logical
            // files to bytes only one of them owns.
            throw new StoreException(
                "Generated path \"{$object->relativePath}\" already exists in store \"{$this->name}\"",
            );
        }

        $directory = \dirname($target);
        try {
            FileHelper::ensureDirectory($directory, $this->directoryMode);
        } catch (\Throwable $e) {
            throw new StoreException("Could not create \"{$directory}\" in store \"{$this->name}\"", 0, $e);
        }
        $this->assertContained($directory);

        $partial = $target . '.' . bin2hex(random_bytes(8)) . self::PARTIAL_SUFFIX;
        $written = $this->copy($upload->stream(), $partial, $maxBytes, $object->relativePath);

        if (!@rename($partial, $target)) {
            @unlink($partial);

            throw new StoreException(
                "Could not publish \"{$object->relativePath}\" in store \"{$this->name}\"",
            );
        }

        return new StoreResult(relativePath: $object->relativePath, size: $written);
    }

    #[Override]
    public function delete(File $file): void
    {
        $path = $this->resolve($file->directory());
        if ($path === null) {
            return;
        }

        try {
            if (is_dir($path)) {
                FileHelper::removeDirectory($path);
            } else {
                FileHelper::unlink($path);
            }
        } catch (\Throwable $e) {
            throw new StoreException(
                "Could not delete \"{$file->directory()}\" from store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->resolve($file->relativePath) !== null;
    }

    #[Override]
    public function size(File $file): ?int
    {
        $path = $this->resolve($file->relativePath);
        if ($path === null) {
            return null;
        }

        $size = @filesize($path);

        return $size === false ? null : max(0, $size);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        $path = $this->resolve($file->relativePath);
        if ($path === null) {
            return null;
        }

        $timestamp = @filemtime($path);

        return $timestamp === false ? null : new DateTimeImmutable('@' . $timestamp);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        $path = $this->resolve($file->relativePath);

        return $path === null ? null : $this->streamFactory->createStreamFromFile($path, 'rb');
    }

    #[Override]
    public function streamRange(File $file, int $offset, int $length): ?StreamInterface
    {
        $path = $this->resolve($file->relativePath);
        if ($path === null) {
            return null;
        }

        $size = @filesize($path);
        if ($size === false || $offset >= $size) {
            return null;
        }

        return new LimitedStream(
            stream: $this->streamFactory->createStreamFromFile($path, 'rb'),
            offset: $offset,
            length: max(0, min($length, $size - $offset)),
        );
    }

    #[Override]
    public function hasDerivative(File $file, DerivativeDescriptor $derivative): bool
    {
        return $this->resolve($this->derivativePath($file, $derivative)) !== null;
    }

    #[Override]
    public function derivativeStream(File $file, DerivativeDescriptor $derivative): ?StreamInterface
    {
        $path = $this->resolve($this->derivativePath($file, $derivative));

        return $path === null ? null : $this->streamFactory->createStreamFromFile($path, 'rb');
    }

    #[Override]
    public function writeDerivative(
        File $file,
        DerivativeDescriptor $derivative,
        StreamInterface $contents,
        int $maxBytes = 0,
    ): DerivativeObject {
        $relativePath = $this->derivativePath($file, $derivative);
        $target = $this->rootPath . '/' . (new StoredObjectId($relativePath))->relativePath;

        $directory = \dirname($target);
        try {
            FileHelper::ensureDirectory($directory, $this->directoryMode);
        } catch (\Throwable $e) {
            throw new StoreException("Could not create \"{$directory}\" in store \"{$this->name}\"", 0, $e);
        }
        $this->assertContained($directory);

        $partial = $target . '.' . bin2hex(random_bytes(8)) . self::PARTIAL_SUFFIX;
        $written = $this->copy($contents, $partial, $maxBytes, $relativePath);

        if (!@rename($partial, $target)) {
            @unlink($partial);

            throw new StoreException("Could not publish derivative \"{$relativePath}\" in store \"{$this->name}\"");
        }

        return new DerivativeObject(
            relativePath: $relativePath,
            size: $written,
            mediaType: $derivative->mediaType,
        );
    }

    #[Override]
    public function objects(?string $afterPath = null, int $limit = 1000): iterable
    {
        $yielded = 0;
        foreach ($this->walk($this->rootPath, '') as $relativePath) {
            if ($afterPath !== null && strcmp($relativePath, $afterPath) <= 0) {
                continue;
            }

            yield new StoredObjectId($relativePath);

            if (++$yielded >= $limit) {
                return;
            }
        }
    }

    #[Override]
    public function deleteObject(StoredObjectId $object): void
    {
        $path = $this->resolve($object->relativePath);
        if ($path === null) {
            // Already gone. A retried garbage collection has to converge, not fail.
            return;
        }

        try {
            FileHelper::unlink($path);
        } catch (\Throwable $e) {
            throw new StoreException(
                "Could not delete \"{$object->relativePath}\" from store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    /**
     * @return non-empty-string
     */
    private function derivativePath(File $file, DerivativeDescriptor $derivative): string
    {
        return $file->directory() . '/' . $derivative->fileName();
    }

    /**
     * Copies with the byte cap enforced *during* the copy, removing the partial
     * output when it is crossed.
     *
     * @param non-empty-string $relativePath For the message only.
     *
     * @return int<0, max>
     *
     * @throws StoreException
     * @throws UploadTooLargeException
     */
    private function copy(StreamInterface $source, string $partial, int $maxBytes, string $relativePath): int
    {
        $handle = @fopen($partial, 'xb');
        if ($handle === false) {
            throw new StoreException("Could not open a staging file for \"{$relativePath}\"");
        }

        $written = 0;
        try {
            while (!$source->eof()) {
                $chunk = $source->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }

                $written += \strlen($chunk);
                if ($maxBytes > 0 && $written > $maxBytes) {
                    throw new UploadTooLargeException(
                        "Upload exceeds the {$maxBytes} byte limit for this group",
                    );
                }

                if (@fwrite($handle, $chunk) === false) {
                    throw new StoreException("Could not write \"{$relativePath}\" to store \"{$this->name}\"");
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($partial);

            throw $e;
        }

        fclose($handle);

        return $written;
    }

    /**
     * Stable, resumable, depth-first walk. `scandir()` sorts each level, so the
     * sequence is ordered and a cursor can skip forward through it.
     *
     * @return iterable<int, non-empty-string>
     */
    private function walk(string $absolute, string $prefix): iterable
    {
        $entries = @scandir($absolute);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_ends_with($entry, self::PARTIAL_SUFFIX)) {
                continue;
            }

            $path = $absolute . '/' . $entry;
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            \assert($relative !== '');

            if (is_link($path)) {
                // A symlink is not an object of this store, and following one
                // would let the inventory walk out of the root.
                continue;
            }

            if (is_dir($path)) {
                yield from $this->walk($path, $relative);
            } else {
                yield $relative;
            }
        }
    }

    /**
     * @param non-empty-string $relativePath
     *
     * @return non-empty-string|null Absolute path, or null when missing or outside the root.
     */
    private function resolve(string $relativePath): ?string
    {
        $candidate = $this->rootPath . '/' . (new StoredObjectId($relativePath))->relativePath;
        $resolved = realpath($candidate);

        if ($resolved === false || $resolved === '' || !$this->isContained($resolved)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @throws StoreException
     */
    private function assertContained(string $absolute): void
    {
        $resolved = realpath($absolute);
        if ($resolved === false || !$this->isContained($resolved)) {
            throw new StoreException(
                "Resolved path \"{$absolute}\" escapes the root of store \"{$this->name}\"",
            );
        }
    }

    private function isContained(string $resolved): bool
    {
        return $resolved === $this->rootPath || str_starts_with($resolved, $this->rootPath . '/');
    }
}
