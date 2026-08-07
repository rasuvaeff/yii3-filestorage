<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * A store that can hold renditions beside an original.
 *
 * Defined now although nothing generates derivatives yet, because a derivative
 * has no `File` row and therefore no existing store method can address it.
 * Introducing this interface later would break every third-party store — a
 * major release for the whole package family — so it is part of the contract
 * from the start.
 *
 * @api
 */
interface DerivativeAwareStoreInterface extends StoreInterface
{
    public function hasDerivative(File $file, DerivativeDescriptor $derivative): bool;

    public function derivativeStream(File $file, DerivativeDescriptor $derivative): ?StreamInterface;

    /**
     * Writes atomically and enforces `$maxBytes` while copying, exactly like
     * {@see StoreInterface::write()}.
     *
     * @param int $maxBytes Zero means no cap.
     *
     * @throws StoreException
     * @throws UploadTooLargeException
     */
    public function writeDerivative(
        File $file,
        DerivativeDescriptor $derivative,
        StreamInterface $contents,
        int $maxBytes = 0,
    ): DerivativeObject;
}
