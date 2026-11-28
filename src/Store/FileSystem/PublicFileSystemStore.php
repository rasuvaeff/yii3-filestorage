<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\AbandonedPartialsInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\PubliclyReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
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
    AbandonedPartialsInterface,
    ContentAddressableStoreInterface,
    DerivativeUrlProviderInterface,
    MaintenanceStoreInterface,
    PubliclyReadableStoreInterface,
    RangeReadableStoreInterface,
    StoreUrlProviderInterface
{
    /** @var non-empty-string Without a trailing slash. */
    private string $baseUrl;

    /** @var non-empty-string|null Absolute, symlink-resolved. */
    private ?string $webRoot;

    /**
     * @param non-empty-string $baseUrl Public prefix the root is served under,
     *        e.g. `https://cdn.example.com/uploads`.
     * @param string|null $webRoot The directory the web server serves — for
     *        a root of `public/uploads` usually `public`. Given, a quarantine
     *        under it is refused: a deleted public file would stay
     *        downloadable for the whole retention. The inner store only
     *        knows its own root, so without this nothing checks it.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private FileSystemStore $store, string $baseUrl, ?string $webRoot = null)
    {
        $trimmed = rtrim($baseUrl, '/');
        if ($trimmed === '') {
            throw new InvalidArgumentException('baseUrl must not be empty');
        }

        $this->baseUrl = $trimmed;
        $this->webRoot = $webRoot === null ? null : $this->resolveWebRoot($webRoot);
    }

    /**
     * @return non-empty-string|null
     */
    public function webRoot(): ?string
    {
        return $this->webRoot;
    }

    /**
     * Always: the web server serves the root.
     */
    #[Override]
    public function isPubliclyReadable(): bool
    {
        return true;
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
        try {
            $path = $this->store->layout()->derivativePath($file, $derivative);
        } catch (InvalidArgumentException $e) {
            throw new StoreException($e->getMessage(), 0, $e);
        }

        return $this->baseUrl . '/' . $this->encodePath($path);
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
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult
    {
        return $this->store->putIfAbsent($upload, $object, $maxBytes);
    }

    /**
     * @return list<non-empty-string>
     */
    public function ownedPrefixes(): array
    {
        return $this->store->ownedPrefixes();
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->store->layout();
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
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject
    {
        return $this->store->quarantine($objects, $now);
    }

    #[Override]
    public function restore(QuarantinedObject $entry): void
    {
        $this->store->restore($entry);
    }

    #[Override]
    public function purge(QuarantinedObject $entry): void
    {
        $this->store->purge($entry);
    }

    #[Override]
    public function quarantined(?string $afterToken = null, int $limit = 1000): iterable
    {
        return $this->store->quarantined($afterToken, $limit);
    }

    /**
     * The inner store refuses a quarantine under its root; the constructor
     * refuses one under `webRoot`. Where the web server's document root is
     * wider than the store root and no `webRoot` was given, nothing here can
     * tell — `filestorage:check` says so.
     */
    #[Override]
    public function quarantineProblem(): ?string
    {
        return $this->store->quarantineProblem();
    }

    #[Override]
    public function sweepAbandonedPartials(DateTimeImmutable $modifiedBefore, bool $apply, int $limit): array
    {
        return $this->store->sweepAbandonedPartials($modifiedBefore, $apply, $limit);
    }

    /**
     * @return non-empty-string
     *
     * @throws InvalidArgumentException
     */
    private function resolveWebRoot(string $webRoot): string
    {
        $resolved = realpath($webRoot);
        if ($resolved === false || $resolved === '' || !is_dir($resolved)) {
            throw new InvalidArgumentException("webRoot \"{$webRoot}\" is not an existing directory");
        }

        $quarantine = $this->store->quarantinePath();
        if ($quarantine !== null && ($quarantine === $resolved || str_starts_with($quarantine, $resolved . \DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException(
                "Store \"{$this->store->name()}\" quarantine \"{$quarantine}\" is inside the web root \"{$resolved}\": "
                . 'a deleted public file would stay downloadable for the whole retention. Move it outside',
            );
        }

        return $resolved;
    }

    /**
     * @param non-empty-string $relativePath Every path reaching here is
     *        validated non-empty: `File::requireRelativePath()` for uploads,
     *        the layout's derivative path for derivatives.
     */
    private function encodePath(string $relativePath): string
    {
        // No `non-empty-string` return claim: rawurlencode('') is '', so
        // psalm cannot prove implode() non-empty here, and both call sites
        // prepend a non-empty base URL anyway.
        return implode('/', array_map(rawurlencode(...), explode('/', $relativePath)));
    }
}
