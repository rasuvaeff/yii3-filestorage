<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\ContentTooLargeException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Id\IdGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Throwable;

/**
 * The non-sharing implementation: every successful `add()` owns one unique object.
 *
 * That is what makes the failure handling here simple enough to be correct. A
 * failed metadata save may delete the object it just wrote, because no other
 * row can possibly point at it. Content-addressed sharing has a different
 * lifecycle — references, reservations, leased collection — and lives in
 * `rasuvaeff/yii3-filestorage-db` rather than being switched on by a flag here.
 *
 * @api
 */
final readonly class Storage implements StorageInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';
    private const int CHUNK = 262_144;

    /** @var non-empty-string */
    private string $defaultGroup;

    /**
     * @param int $maxInlineBytes Cap for {@see self::content()}.
     * @param int $integrityHashMaxBytes Opt-in: 0 leaves `contentHash` null.
     *        A hash costs a second full read of every upload, so it is not
     *        charged to consumers that do not need one.
     * @param ProxyUrlGeneratorInterface|null $proxyUrls Supplied by
     *        `rasuvaeff/yii3-filestorage-web`; null when no proxy route exists.
     */
    public function __construct(
        private StoreRegistry $stores,
        private RepositoryInterface $repository,
        private PathGeneratorInterface $pathGenerator,
        private MimeTypeDetectorInterface $mimeTypeDetector,
        private IdGeneratorInterface $idGenerator,
        private PolicyRegistry $policies,
        private DeliveryPolicyRegistry $deliveryPolicies,
        private ClockInterface $clock,
        private DateInterval $defaultUrlTtl,
        string $defaultGroup = 'common',
        private int $maxInlineBytes = 8_388_608,
        private int $integrityHashMaxBytes = 0,
        private ?ProxyUrlGeneratorInterface $proxyUrls = null,
    ) {
        if ($defaultGroup === '' || preg_match(self::NAME_PATTERN, $defaultGroup) !== 1) {
            throw new InvalidArgumentException("Invalid default group \"{$defaultGroup}\"");
        }

        $this->defaultGroup = $defaultGroup;
    }

    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        $groupName ??= $this->defaultGroup;
        if (preg_match(self::NAME_PATTERN, $groupName) !== 1) {
            throw new InvalidArgumentException("Invalid group name \"{$groupName}\"");
        }

        $store = $this->stores->get($storeName);
        $policy = $this->policies->for($groupName);
        $mimeType = $this->mimeTypeDetector->detect($upload);

        // Everything knowable without touching the store is checked first, so a
        // rejected upload writes nothing at all.
        $policy->assertAcceptable($upload, $mimeType);

        $contentHash = $this->hashWithinLimit($upload);

        $result = $store->write(
            upload: $upload,
            groupName: $groupName,
            pathGenerator: $this->pathGenerator,
            mediaType: $mimeType,
            maxBytes: $policy->maxBytes,
        );

        $now = $this->clock->now();
        $file = File::create(
            id: $this->idGenerator->generate(),
            storeName: $store->name(),
            groupName: $groupName,
            relativePath: $result->relativePath,
            originalName: $upload->originalName,
            size: $result->size,
            createdAt: $now,
            externalId: $result->externalId,
            mimeType: $mimeType,
            description: $description,
            contentHash: $contentHash,
            metadata: $metadata,
        );

        try {
            $this->repository->save($file);
        } catch (Throwable $e) {
            // This call owns the object it just wrote, so removing it cannot
            // break anybody else. A failed removal leaves an orphan for
            // `filestorage:gc`, which is recoverable; a row pointing at bytes
            // that were never persisted is not.
            $this->bestEffortDelete($store, $file);

            throw new AddException(
                "Failed to persist metadata for \"{$file->relativePath}\" in store \"{$file->storeName}\"",
                0,
                $e,
            );
        }

        return $file;
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->repository->find($id);
    }

    #[Override]
    public function remove(string $id): bool
    {
        $file = $this->repository->find($id);
        if (!$file instanceof \Rasuvaeff\Yii3Filestorage\File) {
            return false;
        }

        // Metadata goes first: the recoverable failure is an unreferenced
        // object, not a row promising bytes that are already gone.
        if (!$this->repository->delete($id)) {
            return false;
        }

        try {
            $this->stores->get($file->storeName)->delete($file);
        } catch (Throwable $e) {
            throw new RemoveException(
                "Removed metadata for \"{$id}\" but could not delete \"{$file->relativePath}\" "
                . "from store \"{$file->storeName}\"; it is now an orphan for filestorage:gc",
                0,
                $e,
            );
        }

        return true;
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->stores->get($file->storeName)->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        $store = $this->stores->get($file->storeName);

        return $store instanceof StoreUrlProviderInterface ? $store->publicUrl($file) : null;
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        $store = $this->stores->get($file->storeName);

        return $store instanceof StoreUrlProviderInterface
            ? $store->temporaryUrl(
                file: $file,
                expiresAt: $expiresAt,
                options: DeliveryOptions::fromFile(
                    file: $file,
                    policy: $this->deliveryPolicies->for($file->groupName),
                ),
            )
            : null;
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        $expiresAt ??= $this->clock->now()->add($this->defaultUrlTtl);

        if ($this->deliveryPolicies->for($file->groupName)->allowDirectPublicUrl) {
            $public = $this->url($file);
            if ($public !== null) {
                return $public;
            }
        }

        return $this->temporaryUrl($file, $expiresAt) ?? $this->proxyUrls?->url($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->stores->get($file->storeName)->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        $stream = $this->stream($file);
        if (!$stream instanceof \Psr\Http\Message\StreamInterface) {
            return null;
        }

        // The persisted size is a hint, not the boundary: an object can be
        // corrupted or replaced outside this package, so the cap is enforced
        // against what the stream actually produces. getContents() is never
        // called — it has no upper bound by definition.
        $contents = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                throw new StoreException("Could not read file \"{$file->id}\" before EOF");
            }

            $contents .= $chunk;
            if (\strlen($contents) > $this->maxInlineBytes) {
                throw new ContentTooLargeException(
                    "\"{$file->id}\" exceeds the {$this->maxInlineBytes} byte inline cap; use stream() instead",
                );
            }
        }

        return $contents;
    }

    /**
     * @return non-empty-string|null Null when hashing is off or the stream
     *         crossed the limit, in which case the file is simply stored unhashed.
     */
    private function hashWithinLimit(Upload $upload): ?string
    {
        if ($this->integrityHashMaxBytes <= 0) {
            return null;
        }

        $stream = $upload->stream();
        $context = hash_init('sha256');
        $read = 0;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }

                throw new StoreException('Could not hash upload: the stream returned no bytes before EOF');
            }

            $read += \strlen($chunk);
            if ($read > $this->integrityHashMaxBytes) {
                $stream->rewind();

                return null;
            }

            hash_update($context, $chunk);
        }

        $stream->rewind();

        return hash_final($context);
    }

    private function bestEffortDelete(StoreInterface $store, File $file): void
    {
        try {
            $store->delete($file);
        } catch (Throwable) {
            // Nothing useful to do: the caller is already being handed the
            // original failure, and `filestorage:gc` reclaims what is left.
        }
    }
}
