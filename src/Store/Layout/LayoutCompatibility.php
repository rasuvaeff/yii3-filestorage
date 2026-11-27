<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store\Layout;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\StoredObjectId;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Whether the keys a generator produces can live in a store with a given
 * layout.
 *
 * The generator and the layout are configured in two different places — the
 * key generator by whoever writes (`-db`'s deduplication, the path generator
 * of the facade), the layout in the store's constructor — and a mismatch is
 * silent until the first deletion: a flat `blobs/ab/cd/<sha>.jpg` key under
 * {@see DirectoryLayout} makes deleting one blob delete its whole fan-out
 * bucket, and gives every blob in it the same derivative path.
 *
 * The check is behavioural, so it holds for third-party layouts and
 * generators too: it generates two keys that differ only after the fan-out
 * prefix and asks the layout what deleting each would remove and where each
 * one's derivative goes. A pair passes when deleting a key removes that key
 * and its derivative, and nothing of the other.
 *
 * {@see self::tenantProblem()} is a different question about the same
 * generator: whether it keeps the scope in the key at all. A generator that
 * drops it (`FlatSha256KeyGenerator` with a prefix) gives two tenants'
 * identical bytes one key, so deduplication would share them across tenants.
 *
 * Call the `assert*()` form where a store and a generator are combined — a
 * factory, a DI definition, a command — so the mistake surfaces at boot
 * rather than as missing bytes. `filestorage:check` reports the same problems.
 *
 * @api
 */
final class LayoutCompatibility
{
    private const string PROBE_SCOPE = 'probe';
    private const string PROBE_EXTENSION = 'jpg';
    private const string PROBE_MEDIA_TYPE = 'image/jpeg';
    private const string PROBE_DIGEST = 'abcd0000000000000000000000000000000000000000000000000000000000ef';

    private function __construct() {}

    /**
     * @return non-empty-string|null Why the pair cannot share a store, or null.
     */
    public static function contentKeysProblem(
        ContentAddressedKeyGeneratorInterface $keys,
        ObjectLayoutInterface $layout,
    ): ?string {
        $source = 'Content-addressed key generator ' . $keys::class;

        try {
            $first = $keys->generate(self::PROBE_SCOPE, 'abcd' . str_repeat('0', 60), self::PROBE_EXTENSION);
            $second = $keys->generate(self::PROBE_SCOPE, 'abcd' . str_repeat('1', 60), self::PROBE_EXTENSION);
        } catch (InvalidArgumentException $e) {
            return "{$source} refused a probe digest: {$e->getMessage()}";
        }

        return self::probe($first, $second, $layout, $source);
    }

    /**
     * @throws InvalidConfigException
     */
    public static function assertContentKeys(ContentAddressedKeyGeneratorInterface $keys, StoreInterface $store): void
    {
        self::raise(self::contentKeysProblem($keys, $store->layout()), $store);
    }

    /**
     * @return non-empty-string|null Why the pair cannot share a store, or null.
     */
    public static function pathsProblem(
        PathGeneratorInterface $paths,
        ObjectLayoutInterface $layout,
        StreamFactoryInterface $streams,
    ): ?string {
        $source = 'Path generator ' . $paths::class;
        $upload = Upload::fromStream($streams->createStream(''), 'probe.' . self::PROBE_EXTENSION, $streams);

        try {
            $first = $paths->generate(self::PROBE_SCOPE, $upload, self::PROBE_MEDIA_TYPE);
            $second = $paths->generate(self::PROBE_SCOPE, $upload, self::PROBE_MEDIA_TYPE);
        } catch (InvalidArgumentException $e) {
            return "{$source} refused a probe upload: {$e->getMessage()}";
        }

        return self::probe($first, $second, $layout, $source);
    }

    /**
     * @throws InvalidConfigException
     */
    public static function assertPaths(
        PathGeneratorInterface $paths,
        StoreInterface $store,
        StreamFactoryInterface $streams,
    ): void {
        self::raise(self::pathsProblem($paths, $store->layout(), $streams), $store);
    }

    /**
     * Whether the generator keeps the scope in the key: it generates one
     * digest under two scopes and compares the keys.
     *
     * Only the caller knows whether sharing across scopes is intended — `-db`
     * shares on purpose under its global deduplication scope — so this is a
     * probe, not a verdict. Pass the scope strings the caller really uses
     * (`-db`: `DedupScope::keyFor()` for two tenants); the defaults suit a
     * caller that has none.
     *
     * @param non-empty-string $firstScope
     * @param non-empty-string $secondScope Must differ from `$firstScope`.
     *
     * @throws InvalidArgumentException When the two scopes are equal.
     *
     * @return non-empty-string|null Why the generator does not keep the two
     *         scopes apart, or null when it does.
     */
    public static function tenantProblem(
        ContentAddressedKeyGeneratorInterface $keys,
        string $firstScope = 'tenant-a',
        string $secondScope = 'tenant-b',
    ): ?string {
        if ($firstScope === $secondScope) {
            throw new InvalidArgumentException('The two probe scopes must differ');
        }

        $source = 'Content-addressed key generator ' . $keys::class;

        try {
            $first = $keys->generate($firstScope, self::PROBE_DIGEST, self::PROBE_EXTENSION);
            $second = $keys->generate($secondScope, self::PROBE_DIGEST, self::PROBE_EXTENSION);
        } catch (InvalidArgumentException $e) {
            return "{$source} refused a probe scope: {$e->getMessage()}";
        }

        if ($first !== $second) {
            return null;
        }

        return "{$source} ignores the tenant: identical bytes under the scopes \"{$firstScope}\" and "
            . "\"{$secondScope}\" both get \"{$first}\", so deduplication would share them across tenants";
    }

    /**
     * @param non-empty-string $firstScope
     * @param non-empty-string $secondScope
     *
     * @throws InvalidConfigException When the generator does not keep the scopes apart.
     * @throws InvalidArgumentException When the two scopes are equal.
     */
    public static function assertTenantKept(
        ContentAddressedKeyGeneratorInterface $keys,
        string $firstScope = 'tenant-a',
        string $secondScope = 'tenant-b',
    ): void {
        $problem = self::tenantProblem($keys, $firstScope, $secondScope);
        if ($problem !== null) {
            throw new InvalidConfigException($problem);
        }
    }

    /**
     * @return non-empty-string|null
     */
    private static function probe(string $first, string $second, ObjectLayoutInterface $layout, string $source): ?string
    {
        $pair = "{$source} and layout " . $layout::class . ' cannot share a store';
        if ($first === $second) {
            return "{$pair}: the generator returned \"{$first}\" for two different objects";
        }

        try {
            $a = new StoredObjectId($first);
            $b = new StoredObjectId($second);
            $deleteA = $layout->deletion($a);
            $deleteB = $layout->deletion($b);
            $derivative = new DerivativeDescriptor('probe', 'webp', 'image/webp');
            $derivativeA = $layout->derivativePath(self::file($a), $derivative);
            $derivativeB = $layout->derivativePath(self::file($b), $derivative);
        } catch (InvalidArgumentException $e) {
            return "{$pair}: {$e->getMessage()}";
        }

        if (!$deleteA->contains($first)) {
            return "{$pair}: deleting \"{$first}\" would not remove it";
        }
        if ($deleteA->contains($second) || $deleteB->contains($first)) {
            return "{$pair}: deleting \"{$first}\" would also remove \"{$second}\", another file's bytes";
        }
        if ($derivativeA === $derivativeB) {
            return "{$pair}: \"{$first}\" and \"{$second}\" get the same derivative path \"{$derivativeA}\", so one "
                . "file's preview would be served for the other";
        }
        if ($derivativeA === $first || !$deleteA->contains($derivativeA) || $deleteB->contains($derivativeA)) {
            return "{$pair}: the derivative \"{$derivativeA}\" of \"{$first}\" is not deleted with it, or is deleted "
                . 'with another file';
        }

        return null;
    }

    private static function file(StoredObjectId $object): File
    {
        return File::create(
            id: self::PROBE_SCOPE,
            storeName: self::PROBE_SCOPE,
            groupName: self::PROBE_SCOPE,
            relativePath: $object->relativePath,
            originalName: self::PROBE_SCOPE,
            size: 0,
            createdAt: new DateTimeImmutable('@0'),
        );
    }

    /**
     * @throws InvalidConfigException
     */
    private static function raise(?string $problem, StoreInterface $store): void
    {
        if ($problem !== null) {
            throw new InvalidConfigException("Store \"{$store->name()}\": {$problem}");
        }
    }
}
