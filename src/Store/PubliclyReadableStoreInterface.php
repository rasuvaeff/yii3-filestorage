<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3Filestorage\Store;

/**
 * A store that can say whether anyone can read what it holds without
 * credentials — a directory the web server serves, a public bucket.
 *
 * A method rather than a marker type, for the same reason as
 * {@see StoreUrlProviderInterface}: one store class wraps buckets of both
 * kinds, and whether a bucket is public is configuration, not a class. A store
 * that does not implement this interface counts as not publicly readable.
 *
 * Answering `publicUrl()` is not evidence: an S3 adapter builds an object URL
 * for a private bucket too, and that URL answers 403. This is the operator's
 * statement that the URL answers 200 for everyone.
 *
 * `filestorage:check` treats every group as reachable through such a store
 * (groups are not bound to stores) and fails a group that accepts active
 * content — HTML, SVG, XML — because a content-addressed path is computable
 * by whoever uploaded the bytes, whatever the delivery policy says.
 *
 * @api
 */
interface PubliclyReadableStoreInterface extends StoreInterface
{
    /**
     * True when every object the store holds is readable at a URL without
     * credentials.
     */
    public function isPubliclyReadable(): bool;
}
