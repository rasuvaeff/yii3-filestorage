<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\FileSystem;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\ContentAddressableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\Layout\DirectoryLayout;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectLayoutInterface;
use Rasuvaeff\Yii3Filestorage\Store\Layout\ObjectSet;
use Rasuvaeff\Yii3Filestorage\Store\MaintenanceStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\QuarantinedObject;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoredObject;
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
 * Garbage collection deletes through a quarantine directory: objects are
 * `rename()`d to `<quarantine>/<token>/<relative path>` and purged or restored
 * once the decision is committed. The quarantine must be outside the root —
 * so it is never listed, never served when the root is a web root — and on
 * the same filesystem, or the move is a copy. Without one, garbage collection
 * cannot delete from this store, and `filestorage:check` says so.
 *
 * Content-addressed writes ({@see self::putIfAbsent()}, what deduplication in
 * `rasuvaeff/yii3-filestorage-db` uses) stage under a random `.part` name and
 * publish with `link()`, which refuses an existing target: put-if-absent,
 * atomic on one filesystem. Two writers of the same content both succeed; a
 * crash leaves a `.part` that nothing lists or reuses — under a flat layout it
 * sits in a fan-out directory shared with live blobs, so it is removed by
 * hand, never by `gc`.
 *
 * **The inventory is what the store owns, not everything under the root.**
 * `objects()` — what `gc --orphans` sweeps — never lists a hidden entry (a
 * name starting with `.`: `.htaccess`, `.well-known/`), which no generator
 * emits. Beyond that, the root belongs to the store: a directory the
 * application keeps there (`banners/`, generated cards) has no rows, so the
 * orphan sweep would quarantine and then purge it. When the root is shared —
 * typically a web root such as `public/uploads` — pass `ownedPrefixes`: the
 * inventory is then the listed top-level subtrees only, and a write outside
 * them is refused, so nothing the store creates escapes its own sweep.
 *
 * @api
 */
final readonly class FileSystemStore implements
    ContentAddressableStoreInterface,
    DerivativeAwareStoreInterface,
    MaintenanceStoreInterface,
    RangeReadableStoreInterface
{
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/';
    private const string PREFIX_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9_-]{0,63}(?:/[A-Za-z0-9][A-Za-z0-9_-]{0,63})*\z~';
    /**
     * The staging name is derived from the target, not randomised. Two writers
     * racing for one target would otherwise each stage under their own name and
     * both rename successfully — the loser's bytes silently discarded while its
     * `StoreResult` claimed success. A fixed name makes `fopen(…, 'xb')` refuse
     * the second writer instead, which is the honest outcome.
     *
     * The cost, recorded so it is a trade rather than an oversight: a crashed
     * write leaves a `.part` that blocks any later write to *that same target*
     * until `filestorage:gc` removes the directory. The built-in generators
     * never reuse a target, so this only bites a consumer whose own
     * `PathGeneratorInterface` is deterministic — and there, failing loudly on
     * a leftover from a crash is the behaviour you want anyway.
     */
    private const string PARTIAL_SUFFIX = '.part';
    private const int CHUNK = 262_144;

    /** @var non-empty-string */
    private string $name;

    /** @var non-empty-string Absolute, symlink-resolved. */
    private string $rootPath;

    /** @var non-empty-string|null Absolute, symlink-resolved. */
    private ?string $quarantinePath;

    private ObjectLayoutInterface $layout;

    /** @var list<non-empty-string> Sorted as directories, none inside another. */
    private array $ownedPrefixes;

    /**
     * @param string $rootPath Created when missing.
     * @param string|null $quarantinePath Created when missing. Outside the root,
     *        on the same filesystem. Null leaves the store without a quarantine,
     *        which garbage collection refuses to delete from.
     * @param ObjectLayoutInterface|null $layout Null is {@see DirectoryLayout},
     *        the default. Chosen once for the lifetime of the data.
     * @param list<string> $ownedPrefixes The subtrees of the root this store
     *        owns — the first segments its path and key generators produce
     *        (group names, `blobs`). Empty: the whole root. See the class
     *        docblock.
     *
     * @throws StoreException
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $name,
        string $rootPath,
        private StreamFactoryInterface $streamFactory,
        private int $directoryMode = 0o775,
        ?string $quarantinePath = null,
        ?ObjectLayoutInterface $layout = null,
        array $ownedPrefixes = [],
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
        $this->layout = $layout ?? new DirectoryLayout();
        $this->ownedPrefixes = self::validatePrefixes($name, $ownedPrefixes);
        $this->quarantinePath = $quarantinePath === null ? null : $this->resolveQuarantine($quarantinePath);
    }

    #[Override]
    public function layout(): ObjectLayoutInterface
    {
        return $this->layout;
    }

    /**
     * The subtrees the inventory is limited to; empty when the store owns
     * its whole root.
     *
     * @return list<non-empty-string>
     */
    public function ownedPrefixes(): array
    {
        return $this->ownedPrefixes;
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
        $this->assertOwned($object);
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

        $partial = $target . self::PARTIAL_SUFFIX;
        $written = $this->copy($upload->stream(), $partial, $maxBytes, $object->relativePath);

        if (!@rename($partial, $target)) {
            @unlink($partial);

            throw new StoreException(
                "Could not publish \"{$object->relativePath}\" in store \"{$this->name}\"",
            );
        }

        return new StoreResult(relativePath: $object->relativePath, size: $written);
    }

    /**
     * Publishes the upload at a content-addressed key, or reuses the bytes
     * already there after a size check — the key is the content hash, so a
     * matching length is the same content, and a different one means
     * something outside this convention wrote the key: a hard error.
     */
    #[Override]
    public function putIfAbsent(Upload $upload, StoredObjectId $object, int $maxBytes = 0): StoreResult
    {
        $this->assertOwned($object);
        $target = $this->rootPath . '/' . $object->relativePath;
        $declared = $upload->size();
        if ($maxBytes > 0 && $declared !== null && $declared > $maxBytes) {
            throw new UploadSizeException("Upload of {$declared} bytes exceeds the {$maxBytes} byte limit");
        }

        if (file_exists($target) || is_link($target)) {
            return $this->reuse($upload, $object, $maxBytes);
        }

        $directory = \dirname($target);

        try {
            FileHelper::ensureDirectory($directory, $this->directoryMode);
        } catch (\Throwable $e) {
            throw new StoreException("Could not create \"{$directory}\" in store \"{$this->name}\"", 0, $e);
        }
        $this->assertContained($directory);

        // A random staging name, unlike write(): two writers of the same
        // content must both succeed, and link() below decides which copy wins.
        $partial = $target . '.' . bin2hex(random_bytes(8)) . self::PARTIAL_SUFFIX;
        $written = $this->copy($upload->stream(), $partial, $maxBytes, $object->relativePath);

        if (@link($partial, $target)) {
            @unlink($partial);

            return new StoreResult(relativePath: $object->relativePath, size: $written);
        }

        if (file_exists($target)) {
            @unlink($partial);

            return $this->reuse($upload, $object, $maxBytes);
        }

        // No hard links on this filesystem. rename() replaces a target that
        // appeared meanwhile, which for a content-addressed key is the same
        // bytes — still atomic for a reader.
        if (!@rename($partial, $target)) {
            @unlink($partial);

            throw new StoreException("Could not publish \"{$object->relativePath}\" in store \"{$this->name}\"");
        }

        return new StoreResult(relativePath: $object->relativePath, size: $written);
    }

    #[Override]
    public function delete(File $file): void
    {
        $set = $this->layout->deletion(new StoredObjectId($file->relativePath));
        if (!$set->isTree()) {
            foreach ($this->members($set) as $member) {
                $this->deleteObject(new StoredObjectId($member));
            }

            return;
        }

        $path = $this->resolve($set->path);
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
                "Could not delete \"{$set->path}\" from store \"{$this->name}\"",
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

        $partial = $target . self::PARTIAL_SUFFIX;
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
        foreach ($this->inventory($afterPath) as $relativePath) {
            if ($afterPath !== null && strcmp($relativePath, $afterPath) <= 0) {
                continue;
            }

            $absolute = $this->rootPath . '/' . $relativePath;
            $modified = @filemtime($absolute);
            $size = @filesize($absolute);

            yield new StoredObject(
                id: new StoredObjectId($relativePath),
                lastModified: $modified === false ? null : new DateTimeImmutable('@' . $modified),
                size: $size === false ? null : max(0, $size),
            );

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

    #[Override]
    public function quarantine(ObjectSet $objects, DateTimeImmutable $now): ?QuarantinedObject
    {
        $quarantine = $this->requireQuarantine();
        $members = $this->members($objects);
        if ($members === []) {
            return null;
        }

        $token = QuarantinedObject::newToken($now);
        $moved = [];

        foreach ($members as $member) {
            $source = $this->resolve($member);
            if ($source === null || !is_file($source)) {
                continue;
            }

            $target = $quarantine . '/' . $token . '/' . $member;

            try {
                FileHelper::ensureDirectory(\dirname($target), $this->directoryMode);
            } catch (\Throwable $e) {
                throw new StoreException("Could not quarantine \"{$member}\" in store \"{$this->name}\"", 0, $e);
            }

            if (!@rename($source, $target)) {
                throw new StoreException("Could not quarantine \"{$member}\" in store \"{$this->name}\"");
            }

            $moved[] = new StoredObjectId($member);
        }

        if ($objects->isTree()) {
            $this->removeEmptyDirectories($this->rootPath . '/' . $objects->path);
        }

        if ($moved === []) {
            @rmdir($quarantine . '/' . $token);

            return null;
        }

        return new QuarantinedObject($token, $moved);
    }

    #[Override]
    public function restore(QuarantinedObject $entry): void
    {
        $base = $this->requireQuarantine() . '/' . $entry->token;

        foreach ($entry->objects as $object) {
            $held = $base . '/' . $object->relativePath;
            if (!is_file($held)) {
                continue;
            }

            $target = $this->rootPath . '/' . $object->relativePath;
            $directory = \dirname($target);

            try {
                FileHelper::ensureDirectory($directory, $this->directoryMode);
            } catch (\Throwable $e) {
                throw new StoreException(
                    "Could not restore \"{$object->relativePath}\" in store \"{$this->name}\"",
                    0,
                    $e,
                );
            }
            $this->assertContained($directory);

            // link() refuses an existing target where rename() would replace
            // it: put-if-absent. A target that exists already is a writer's
            // re-publication of the same content, and keeping it is correct.
            if (!file_exists($target) && !@link($held, $target) && !file_exists($target) && !@rename($held, $target)) {
                throw new StoreException(
                    "Could not restore \"{$object->relativePath}\" in store \"{$this->name}\"",
                );
            }

            @unlink($held);
        }

        $this->purge($entry);
    }

    #[Override]
    public function purge(QuarantinedObject $entry): void
    {
        $base = $this->requireQuarantine() . '/' . $entry->token;
        if (!is_dir($base)) {
            return;
        }

        try {
            FileHelper::removeDirectory($base);
        } catch (\Throwable $e) {
            throw new StoreException(
                "Could not purge quarantine entry \"{$entry->token}\" of store \"{$this->name}\"",
                0,
                $e,
            );
        }
    }

    #[Override]
    public function quarantined(?string $afterToken = null, int $limit = 1000): iterable
    {
        $quarantine = $this->requireQuarantine();
        $tokens = @scandir($quarantine);
        if ($tokens === false) {
            return;
        }

        $yielded = 0;
        foreach ($tokens as $token) {
            if (!QuarantinedObject::isToken($token) || ($afterToken !== null && strcmp($token, $afterToken) <= 0)) {
                continue;
            }

            $objects = [];
            foreach ($this->walk($quarantine . '/' . $token, '') as $relativePath) {
                $objects[] = new StoredObjectId($relativePath);
            }
            if ($objects === []) {
                @rmdir($quarantine . '/' . $token);

                continue;
            }

            yield new QuarantinedObject($token, $objects);

            if (++$yielded >= $limit) {
                return;
            }
        }
    }

    #[Override]
    public function quarantineProblem(): ?string
    {
        if ($this->quarantinePath === null) {
            return "Store \"{$this->name}\" has no quarantine directory, so filestorage:gc cannot delete from it. "
                . 'Pass quarantinePath: a directory outside the root and outside any web root, on the same filesystem';
        }

        $root = @stat($this->rootPath);
        $quarantine = @stat($this->quarantinePath);
        if ($root === false || $quarantine === false) {
            return "Store \"{$this->name}\": the root or the quarantine \"{$this->quarantinePath}\" cannot be read";
        }
        if ($root['dev'] !== $quarantine['dev']) {
            return "Store \"{$this->name}\": the quarantine \"{$this->quarantinePath}\" is on a different filesystem "
                . 'than the root, so moving an object into it is a copy rather than an atomic rename';
        }

        return null;
    }

    /**
     * The owned subtrees in cursor order — every path under `p/` sorts
     * between the paths of the prefixes before and after it, because no
     * prefix lies inside another.
     *
     * @return iterable<int, non-empty-string>
     */
    private function inventory(?string $after): iterable
    {
        if ($this->ownedPrefixes === []) {
            yield from $this->walk($this->rootPath, '', $after);

            return;
        }

        foreach ($this->ownedPrefixes as $prefix) {
            if ($after !== null && $this->wholeSubtreeIsBefore($prefix, $after)) {
                continue;
            }

            $absolute = $this->rootPath . '/' . $prefix;
            if (is_dir($absolute) && !is_link($absolute) && $this->resolve($prefix) === $absolute) {
                yield from $this->walk($absolute, $prefix, $after);
            }
        }
    }

    /**
     * True when every path under `$relative/` — and `$relative` itself —
     * sorts at or before the cursor, so the walk need not look inside.
     */
    private function wholeSubtreeIsBefore(string $relative, string $after): bool
    {
        return strcmp($relative . '/', $after) < 0 && !str_starts_with($after, $relative . '/');
    }

    /**
     * @throws StoreException
     */
    private function assertOwned(StoredObjectId $object): void
    {
        if ($this->ownedPrefixes === []) {
            return;
        }

        foreach ($this->ownedPrefixes as $prefix) {
            if (str_starts_with($object->relativePath, $prefix . '/')) {
                return;
            }
        }

        throw new StoreException(
            "Path \"{$object->relativePath}\" is outside the owned prefixes of store \"{$this->name}\" ("
            . implode(', ', $this->ownedPrefixes) . '): the orphan sweep would never see it. Add its first segment '
            . 'to ownedPrefixes',
        );
    }

    /**
     * @param array<array-key, mixed> $prefixes
     *
     * @return list<non-empty-string>
     *
     * @throws InvalidArgumentException
     */
    private static function validatePrefixes(string $name, array $prefixes): array
    {
        /** @var array<string, non-empty-string> $sorted */
        $sorted = [];
        foreach ($prefixes as $prefix) {
            if (!\is_string($prefix) || $prefix === '' || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
                throw new InvalidArgumentException(
                    "Store \"{$name}\": invalid owned prefix " . var_export($prefix, true),
                );
            }

            $sorted[$prefix . '/'] = $prefix;
        }

        ksort($sorted, SORT_STRING);
        $previous = null;
        foreach ($sorted as $key => $prefix) {
            if ($previous !== null && str_starts_with($key, $previous)) {
                throw new InvalidArgumentException(
                    "Store \"{$name}\": owned prefix \"{$prefix}\" lies inside \"" . rtrim($previous, '/') . '"',
                );
            }
            $previous = $key;
        }

        return array_values($sorted);
    }

    /**
     * @throws StoreException
     * @throws UploadSizeException
     */
    private function reuse(Upload $upload, StoredObjectId $object, int $maxBytes): StoreResult
    {
        $path = $this->resolve($object->relativePath);
        if ($path === null || !is_file($path)) {
            throw new StoreException(
                "Content-addressed key \"{$object->relativePath}\" in store \"{$this->name}\" is not a regular file",
            );
        }

        $existing = max(0, (int) @filesize($path));
        $actual = $upload->size() ?? $this->measure($upload);
        if ($existing !== $actual) {
            throw new StoreException(
                "Content-addressed object \"{$object->relativePath}\" holds {$existing} bytes but the upload is "
                . "{$actual}. Something outside this package wrote that key",
            );
        }

        // The cap is about what this caller may store, however the bytes got
        // there: reusing past it would bypass a group's limit through a laxer one.
        if ($maxBytes > 0 && $existing > $maxBytes) {
            throw new UploadSizeException("Upload of {$existing} bytes exceeds the {$maxBytes} byte limit");
        }

        return new StoreResult(relativePath: $object->relativePath, size: $existing, created: false);
    }

    /**
     * @return int<0, max>
     */
    private function measure(Upload $upload): int
    {
        $stream = $upload->stream();
        $counted = 0;
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK);
            if ($chunk === '') {
                break;
            }

            $counted += \strlen($chunk);
        }
        $stream->rewind();

        return $counted;
    }

    /**
     * @return non-empty-string
     */
    private function derivativePath(File $file, DerivativeDescriptor $derivative): string
    {
        return $this->layout->derivativePath($file, $derivative);
    }

    /**
     * The objects of a set that exist, by relative path.
     *
     * @return list<non-empty-string>
     */
    private function members(ObjectSet $set): array
    {
        if ($set->isTree()) {
            $absolute = $this->resolve($set->path);
            if ($absolute === null) {
                return [];
            }

            return is_dir($absolute) ? [...$this->walk($absolute, $set->path)] : [$set->path];
        }

        $directory = $set->directory();
        $absolute = $directory === null ? $this->rootPath : $this->resolve($directory);
        $entries = $absolute === null ? false : @scandir($absolute);
        if ($entries === false) {
            return [];
        }

        $members = [];
        foreach ($entries as $entry) {
            $relative = $directory === null ? $entry : $directory . '/' . $entry;
            if (
                $relative !== ''
                && $set->contains($relative)
                && !str_ends_with($entry, self::PARTIAL_SUFFIX)
                && is_file($this->rootPath . '/' . $relative)
                && !is_link($this->rootPath . '/' . $relative)
            ) {
                $members[] = $relative;
            }
        }

        return $members;
    }

    /**
     * Bottom-up removal of directories that the quarantine emptied, never
     * of one that still holds something — a staging file of a writer
     * re-publishing the same content included.
     */
    private function removeEmptyDirectories(string $absolute): void
    {
        $entries = is_dir($absolute) && !is_link($absolute) ? @scandir($absolute) : false;
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeEmptyDirectories($absolute . '/' . $entry);
            }
        }

        @rmdir($absolute);
    }

    /**
     * @return non-empty-string
     *
     * @throws StoreException
     */
    private function requireQuarantine(): string
    {
        if ($this->quarantinePath === null) {
            throw new StoreException(
                "Store \"{$this->name}\" has no quarantine directory configured, so it cannot quarantine objects",
            );
        }

        return $this->quarantinePath;
    }

    /**
     * @return non-empty-string
     *
     * @throws StoreException
     * @throws InvalidArgumentException
     */
    private function resolveQuarantine(string $quarantinePath): string
    {
        if (!is_dir($quarantinePath)) {
            try {
                FileHelper::ensureDirectory($quarantinePath, $this->directoryMode);
            } catch (\Throwable $e) {
                throw new StoreException(
                    "Store \"{$this->name}\" quarantine \"{$quarantinePath}\" does not exist and could not be created",
                    0,
                    $e,
                );
            }
        }

        $resolved = realpath($quarantinePath);
        if ($resolved === false || $resolved === '') {
            throw new StoreException("Store \"{$this->name}\" quarantine \"{$quarantinePath}\" could not be resolved");
        }

        if (
            $this->isContained($resolved)
            || $this->rootPath === $resolved
            || str_starts_with($this->rootPath, $resolved . \DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException(
                "Store \"{$this->name}\" quarantine \"{$quarantinePath}\" must be outside the root, and the root "
                . 'outside it: anything under the root is listed as an object and may be served',
            );
        }

        return $resolved;
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
     * @throws UploadSizeException
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
                    if ($source->eof()) {
                        break;
                    }

                    throw new StoreException("Could not read \"{$relativePath}\" before EOF");
                }

                $written += \strlen($chunk);
                if ($maxBytes > 0 && $written > $maxBytes) {
                    throw new UploadSizeException(
                        "Upload exceeds the {$maxBytes} byte limit for this group",
                    );
                }

                $offset = 0;
                $length = \strlen($chunk);
                while ($offset < $length) {
                    $remaining = substr($chunk, $offset);
                    $accepted = @fwrite($handle, $remaining);
                    if ($accepted === false || $accepted <= 0 || $accepted > \strlen($remaining)) {
                        throw new StoreException("Could not write \"{$relativePath}\" to store \"{$this->name}\"");
                    }

                    $offset += $accepted;
                }
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($partial);

            throw $e;
        }

        // The final flush happens here, so a full disk surfaces at fclose()
        // and nowhere earlier. Ignoring it would rename a truncated .part into
        // place and call it published.
        if (!fclose($handle)) {
            @unlink($partial);

            throw new StoreException(
                "Could not flush \"{$relativePath}\" to store \"{$this->name}\"; the partial file was removed",
            );
        }

        return $written;
    }

    /**
     * Stable, resumable, depth-first walk, ordered so that a `strcmp()` cursor
     * over full relative paths can skip forward through it safely.
     *
     * With a cursor, an entry whose whole subtree sorts before it is skipped
     * before it is even stat()ed, and only the directories on the cursor's
     * own path are listed again: resuming a page costs the depth of the tree,
     * not everything before the cursor (review 1.0 B10). Paths at or before
     * the cursor may still be yielded; the caller filters them.
     *
     * Hidden entries — a name starting with `.` — are never part of a store:
     * no generator emits one, and `.htaccess` under a web root is the
     * application's (review 1.0 B2).
     *
     * @return iterable<int, non-empty-string>
     */
    private function walk(string $absolute, string $prefix, ?string $after = null): iterable
    {
        $entries = @scandir($absolute);
        if ($entries === false) {
            return;
        }

        /** @var array<string, array{string, string, bool}> $ordered */
        $ordered = [];
        foreach ($entries as $entry) {
            if ($entry === '' || $entry[0] === '.' || str_ends_with($entry, self::PARTIAL_SUFFIX)) {
                continue;
            }

            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if ($after !== null && $this->wholeSubtreeIsBefore($relative, $after)) {
                continue;
            }

            $path = $absolute . '/' . $entry;
            if (is_link($path)) {
                // A symlink is not an object of this store, and following one
                // would let the inventory walk out of the root.
                continue;
            }

            // Sorted by the string that will actually appear in a relative
            // path, which for a directory ends in its separator. `scandir()`
            // orders by bare name, so a directory `a` precedes a file `a.txt`
            // — while `strcmp()` over full paths puts `a.txt` before `a/x.txt`,
            // because `.` is 0x2E and `/` is 0x2F. The cursor in objects()
            // compares full paths, so a walk ordered any other way makes it
            // skip `a.txt` on every page after the one holding `a/x.txt`, and
            // the object silently drops out of the inventory for good.
            $isDirectory = is_dir($path);
            $ordered[$isDirectory ? $entry . '/' : $entry] = [$entry, $path, $isDirectory];
        }

        ksort($ordered, SORT_STRING);

        foreach ($ordered as [$entry, $path, $isDirectory]) {
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            \assert($relative !== '');

            if ($isDirectory) {
                yield from $this->walk($path, $relative, $after);
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
        return $resolved === $this->rootPath
            || str_starts_with($resolved, $this->rootPath . \DIRECTORY_SEPARATOR);
    }
}
