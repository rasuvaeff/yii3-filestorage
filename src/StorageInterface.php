<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage;

use DateTimeImmutable;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\AddException;
use Rasuvaeff\Yii3Filestorage\Exception\ContentTooLargeException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\RemoveException;

/**
 * The one entry point application code needs.
 *
 * `size()` and `mimeType()` are deliberately absent: they are properties of
 * {@see File}, and a facade method that sometimes reads a cached value and
 * sometimes hits the store is the kind of API that produces bugs nobody can
 * reproduce. {@see self::exists()} answers the "are the bytes still there"
 * question explicitly.
 *
 * There is no `addMany()` either. Atomic batch writes are impossible over a
 * filesystem or an object store, and a method with that name promises
 * all-or-nothing and cannot deliver it. Per-file compensation already
 * guarantees "a row with its object, or nothing" for each file, and a plain
 * loop composes that correctly.
 *
 * @psalm-import-type FileMetadata from File
 *
 * @api
 */
interface StorageInterface
{
    /**
     * @param non-empty-string|null $groupName Defaults to the configured group.
     * @param non-empty-string|null $storeName Defaults to the registry's default store.
     * @param array<array-key, mixed> $metadata
     *
     * @throws PolicyViolationException When the upload is not acceptable for its group.
     * @throws AddException When metadata could not be persisted after the write.
     */
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File;

    /**
     * @param non-empty-string $id
     */
    public function find(string $id): ?File;

    /**
     * @param non-empty-string $id
     *
     * @return bool False when there was no such file.
     *
     * @throws RemoveException When the row is gone but its object could not be deleted.
     */
    public function remove(string $id): bool;

    public function exists(File $file): bool;

    /**
     * Raw permanent public URL capability, or null.
     *
     * Infrastructure-level: it ignores delivery policy. Application code wants
     * {@see self::urlFor()}.
     *
     * @return non-empty-string|null
     */
    public function url(File $file): ?string;

    /**
     * Store-native expiring URL, or null when the store cannot presign.
     *
     * @return non-empty-string|null
     */
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string;

    /**
     * The URL method application code should call.
     *
     * Applies the group's delivery policy, then tries — in order — an allowed
     * direct public URL, a store-native temporary URL, and the application
     * proxy URL. A template therefore never has to branch on whether the store
     * happens to be public, and swapping backends stays a configuration change.
     *
     * @return non-empty-string|null Null when nothing can produce a URL, which
     *         means no delivery route is installed.
     */
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string;

    public function stream(File $file): ?StreamInterface;

    /**
     * Capped convenience for small objects. Ordinary reads use {@see self::stream()}.
     *
     * @throws ContentTooLargeException When the object exceeds the configured inline cap.
     */
    public function content(File $file): ?string;
}
