<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use DateTimeImmutable;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Where the bytes actually live.
 *
 * Everything optional a backend might support — permanent URLs, presigned
 * URLs, range reads, content-addressed writes, inventory, derivatives — is a
 * separate capability interface. A store implements the ones it can honour,
 * and callers ask with `instanceof` instead of calling a method that returns
 * null or throws "not supported".
 *
 * There is deliberately no `content()` here. Reading a whole object is the
 * facade's capped concern; putting it on the store would hand every adapter a
 * way to exhaust memory.
 *
 * @api
 */
interface StoreInterface
{
    /**
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Streams an upload to a newly generated, unique path.
     *
     * Implementations enforce `$maxBytes` *while copying* — the check cannot
     * wait until the end, because the point is to stop before an unknown-length
     * body has been written in full — remove any partial object when the cap is
     * crossed, and publish atomically so a reader never observes a half-written
     * file. A generated-path collision is an error: the base store never infers
     * sharing from an object already being there.
     *
     * @param non-empty-string $groupName
     * @param non-empty-string|null $mediaType Detected type, for the extension.
     * @param int $maxBytes Zero means no policy cap; the ingress spool cap still applies.
     *
     * @throws StoreException
     * @throws UploadTooLargeException
     */
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult;

    /**
     * Deletes the file's directory — the object and every derivative stored
     * beside it.
     *
     * Deleting only the object would leak every preview generated for it,
     * indistinguishably from a live file, forever.
     *
     * @throws StoreException
     */
    public function delete(File $file): void;

    public function exists(File $file): bool;

    /**
     * @return int<0, max>|null Null when the object is missing.
     */
    public function size(File $file): ?int;

    public function lastModified(File $file): ?DateTimeImmutable;

    public function stream(File $file): ?StreamInterface;
}
