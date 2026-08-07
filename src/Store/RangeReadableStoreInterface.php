<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * A store that can read a byte range without pulling the whole object.
 *
 * Explicit because not every PSR-7 stream is seekable: a Flysystem adapter over
 * a network may hand back a forward-only resource, and answering `206` on it
 * would mean downloading everything up to the offset and discarding it. A
 * download action that cannot find this capability serves an honest full `200`
 * with no `Accept-Ranges` instead of claiming range support it cannot deliver.
 *
 * @api
 */
interface RangeReadableStoreInterface extends StoreInterface
{
    /**
     * @param int<0, max> $offset
     * @param int<1, max> $length
     *
     * @return StreamInterface|null Null when the object is missing or the range
     *         starts past its end.
     */
    public function streamRange(File $file, int $offset, int $length): ?StreamInterface;
}
